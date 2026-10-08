import type { Lang, Settings } from '../types';
import { getApi } from '../shared/browserApi';
import { domainsToMatchPatterns } from '../shared/domains';
import { errorMessage, resolveLanguage, t, type MessageKey } from '../shared/i18n';
import {
    clearToken,
    loadSettings,
    loadToken,
    normalizeSettings,
    saveSettings,
    saveToken,
    tokenState,
} from '../shared/settings';
import { isRecord } from '../shared/text';
import { validateHttpUrl } from '../shared/url';
import { buildSettingsFromForm, requiredOriginPatterns, type FormInput } from './formModel';

const api = getApi();
const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;

let lang: Lang = 'fr';
let saved: Settings;

function isValidSelector(selector: string): boolean {
    try {
        document.createDocumentFragment().querySelector(selector);
        return true;
    } catch {
        return false;
    }
}

function applyTranslations(): void {
    document.documentElement.lang = lang;
    document.title = t(lang, 'optTitle');
    document.querySelectorAll<HTMLElement>('[data-i18n]').forEach((element) => {
        element.textContent = t(lang, element.dataset.i18n as MessageKey);
    });
}

function setStatus(text: string, tone: 'ok' | 'error' | '' = ''): void {
    const status = $('status');
    status.textContent = text;
    status.className = `status ${tone}`.trim();
}

function showErrors(errors: readonly string[]): void {
    const list = $('errors');
    list.replaceChildren(
        ...errors.map((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            return item;
        }),
    );
    list.hidden = errors.length === 0;
}

async function showTokenState(): Promise<void> {
    const state = await tokenState(api);
    $('tokenState').textContent = t(lang, state === 'none' ? 'optTokenNone' : 'optTokenSaved');
}

async function showShortcut(): Promise<void> {
    let shortcut = '';
    try {
        const commands = (await api.commands?.getAll()) ?? [];
        shortcut = commands.find((command) => command.name === 'toggle-inspector')?.shortcut ?? '';
    } catch {
        shortcut = '';
    }
    $('shortcut').textContent = shortcut || t(lang, 'optShortcutNone');
}

function fill(settings: Settings): void {
    $<HTMLInputElement>('apiUrl').value = settings.apiUrl;
    $<HTMLInputElement>('appUrl').value = settings.appUrl;
    $<HTMLTextAreaElement>('domains').value = settings.allowedDomains.join('\n');
    $<HTMLSelectElement>('tokenPersistence').value = settings.tokenPersistence;
    $<HTMLSelectElement>('uiLanguage').value = settings.uiLanguage;
    $<HTMLSelectElement>('activationModifier').value = settings.activationModifier;
    $<HTMLInputElement>('maxResults').value = String(settings.maxResults);
    $<HTMLInputElement>('timeoutSeconds').value = String(Math.round(settings.timeoutMs / 1000));
    $<HTMLInputElement>('collectContext').checked = settings.collectContext;
    $<HTMLSelectElement>('urlMode').value = settings.urlMode;
    $<HTMLInputElement>('feedbackEnabled').checked = settings.feedbackEnabled;
    $<HTMLInputElement>('selectorBreadcrumb').value = settings.selectors.breadcrumb;
    $<HTMLInputElement>('selectorDialog').value = settings.selectors.dialog;
    $<HTMLInputElement>('selectorSection').value = settings.selectors.sectionTitle;
    $<HTMLInputElement>('selectorIgnore').value = settings.selectors.ignore;
    $<HTMLInputElement>('manualSearchUrlTemplate').value = settings.manualSearchUrlTemplate;
}

function readForm(): FormInput {
    return {
        apiUrl: $<HTMLInputElement>('apiUrl').value,
        appUrl: $<HTMLInputElement>('appUrl').value,
        domains: $<HTMLTextAreaElement>('domains').value,
        token: $<HTMLInputElement>('token').value,
        tokenPersistence: $<HTMLSelectElement>('tokenPersistence').value,
        uiLanguage: $<HTMLSelectElement>('uiLanguage').value,
        activationModifier: $<HTMLSelectElement>('activationModifier').value,
        maxResults: $<HTMLInputElement>('maxResults').value,
        collectContext: $<HTMLInputElement>('collectContext').checked,
        urlMode: $<HTMLSelectElement>('urlMode').value,
        timeoutSeconds: $<HTMLInputElement>('timeoutSeconds').value,
        feedbackEnabled: $<HTMLInputElement>('feedbackEnabled').checked,
        selectorBreadcrumb: $<HTMLInputElement>('selectorBreadcrumb').value,
        selectorDialog: $<HTMLInputElement>('selectorDialog').value,
        selectorSection: $<HTMLInputElement>('selectorSection').value,
        selectorIgnore: $<HTMLInputElement>('selectorIgnore').value,
        manualSearchUrlTemplate: $<HTMLInputElement>('manualSearchUrlTemplate').value,
    };
}

async function requestOrigins(origins: string[]): Promise<boolean> {
    try {
        return await api.permissions.request({ origins });
    } catch {
        return false;
    }
}

/** Moindre privilège : les autorisations des domaines / de l'API retirés de la configuration sont révoquées. */
async function revokeUnused(previous: Settings, next: Settings): Promise<void> {
    const before = requiredOriginPatterns(previous, domainsToMatchPatterns(previous.allowedDomains));
    const after = new Set(requiredOriginPatterns(next, domainsToMatchPatterns(next.allowedDomains)));
    const removed = before.filter((pattern) => !after.has(pattern));
    if (removed.length > 0) {
        try {
            await api.permissions.remove({ origins: removed });
        } catch {
            // Non bloquant : l'autorisation excédentaire sera simplement ignorée.
        }
    }
}

async function persistToken(input: FormInput, settings: Settings): Promise<void> {
    const typed = input.token.trim();
    if ($<HTMLInputElement>('clearToken').checked) {
        await clearToken(api);
    } else if (typed) {
        await saveToken(typed, settings.tokenPersistence, api);
    } else {
        const state = await tokenState(api);
        if (state !== 'none' && state !== settings.tokenPersistence) {
            const current = await loadToken(api);
            if (current) {
                await saveToken(current, settings.tokenPersistence, api);
            }
        }
    }
    $<HTMLInputElement>('token').value = '';
    $<HTMLInputElement>('clearToken').checked = false;
}

async function onSubmit(event: Event): Promise<void> {
    event.preventDefault();
    const input = readForm();
    const { settings, errors } = buildSettingsFromForm(input, lang, isValidSelector);
    showErrors(errors);
    if (errors.length > 0) {
        setStatus('', '');
        return;
    }
    // La demande d'autorisation doit suivre immédiatement le geste de l'utilisateur (Firefox est strict).
    const granted = await requestOrigins(requiredOriginPatterns(settings, domainsToMatchPatterns(settings.allowedDomains)));
    if (!granted) {
        setStatus(t(lang, 'optPermissionDenied'), 'error');
        return;
    }
    try {
        await saveSettings(settings, api);
        await persistToken(input, settings);
        await revokeUnused(saved, settings);
        saved = settings;
        await showTokenState();
        setStatus(t(lang, 'optSaved'), 'ok');
    } catch {
        setStatus(t(lang, 'optSaveFailed'), 'error');
    }
}

async function onTest(): Promise<void> {
    const input = readForm();
    const check = validateHttpUrl(input.apiUrl);
    if (!check.ok) {
        const key = check.reason === 'empty' ? 'valApiUrlEmpty' : check.reason === 'insecure' ? 'valApiUrlInsecure' : 'valApiUrlInvalid';
        setStatus(t(lang, key), 'error');
        return;
    }
    if (!(await requestOrigins([check.matchPattern]))) {
        setStatus(t(lang, 'optPermissionDenied'), 'error');
        return;
    }
    setStatus(t(lang, 'optTesting'));
    const reply = await api.runtime.sendMessage({ type: 'testConnection', apiUrl: input.apiUrl, token: input.token });
    if (isRecord(reply) && reply.ok === true) {
        setStatus(t(lang, 'optTestOk', { count: Number(reply.count ?? 0) }), 'ok');
    } else if (isRecord(reply) && isRecord(reply.error) && typeof reply.error.kind === 'string') {
        setStatus(errorMessage(lang, reply.error as unknown as Parameters<typeof errorMessage>[1]), 'error');
    } else {
        setStatus(t(lang, 'errorInvalid'), 'error');
    }
}

async function onImport(file: File): Promise<void> {
    try {
        const parsed: unknown = JSON.parse(await file.text());
        if (!isRecord(parsed)) {
            throw new Error('not an object');
        }
        fill(normalizeSettings(parsed));
        showErrors([]);
        setStatus(t(lang, 'optImportOk'), 'ok');
    } catch {
        setStatus(t(lang, 'optImportInvalid'), 'error');
    }
}

function onExport(): void {
    // Le token n'est jamais exporté.
    const { settings } = buildSettingsFromForm(readForm(), lang, isValidSelector);
    const blob = new Blob([`${JSON.stringify(settings, null, 4)}\n`], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'myab-translation-inspector.config.json';
    link.click();
    URL.revokeObjectURL(url);
}

async function init(): Promise<void> {
    saved = await loadSettings(api);
    lang = resolveLanguage(saved.uiLanguage, api.i18n.getUILanguage());
    applyTranslations();
    fill(saved);
    await Promise.all([showTokenState(), showShortcut()]);

    $('form').addEventListener('submit', (event) => void onSubmit(event));
    $('test').addEventListener('click', () => void onTest());
    $('exportButton').addEventListener('click', onExport);
    $('importButton').addEventListener('click', () => $<HTMLInputElement>('importFile').click());
    $<HTMLInputElement>('importFile').addEventListener('change', (event) => {
        const input = event.target as HTMLInputElement;
        const file = input.files?.[0];
        if (file) {
            void onImport(file);
        }
        input.value = '';
    });
    $<HTMLSelectElement>('uiLanguage').addEventListener('change', (event) => {
        lang = resolveLanguage((event.target as HTMLSelectElement).value as Settings['uiLanguage'], api.i18n.getUILanguage());
        applyTranslations();
        void Promise.all([showTokenState(), showShortcut()]);
    });
}

void init();
