import type { PanelError, SearchRequest } from '../types';
import { ApiError, searchTranslations, sendFeedback } from '../api/translationApi';
import { getApi, isFromExtensionPage, type MessageSender } from '../shared/browserApi';
import { isHostAllowed } from '../shared/domains';
import { resolveLanguage } from '../shared/i18n';
import {
    hasType,
    sanitizeSearchRequest,
    type PanelMessage,
    type PanelResultsMessage,
} from '../shared/messages';
import {
    ENABLED_KEY,
    SETTINGS_KEY,
    loadEnabled,
    loadSettings,
    loadToken,
    saveEnabled,
} from '../shared/settings';
import { cleanString, isRecord } from '../shared/text';
import { sanitizeUrl, validateHttpUrl } from '../shared/url';
import { buildResultsView, manualSearchLink } from '../shared/viewModel';
import { syncContentScripts } from './contentScripts';

const api = getApi();

/** Une seule recherche à la fois par onglet : la précédente est annulée. */
const inFlight = new Map<number, AbortController>();

/* ---- Utilitaires ------------------------------------------------------------------------------------------------ */

function toPanelError(error: unknown): PanelError {
    if (error instanceof ApiError) {
        const panelError: PanelError = { kind: error.kind };
        if (error.status !== undefined) panelError.status = error.status;
        if (error.retryAfterSec !== undefined) panelError.retryAfterSec = error.retryAfterSec;
        return panelError;
    }
    return { kind: 'network' };
}

/** Envoie un message à la frame du haut de l'onglet, où vit le panneau. Silencieux si l'onglet a disparu. */
async function toPanel(tabId: number, message: PanelMessage): Promise<void> {
    try {
        await api.tabs.sendMessage(tabId, message, { frameId: 0 });
    } catch {
        // Onglet fermé ou rechargé entre-temps.
    }
}

async function refreshBadge(): Promise<void> {
    try {
        const enabled = await loadEnabled(api);
        await api.action.setBadgeText({ text: enabled ? 'ON' : '' });
        if (enabled) {
            await api.action.setBadgeBackgroundColor({ color: '#1a7f37' });
        }
    } catch {
        // Badge non critique.
    }
}

/** Dans une sous-frame, la page et le titre à transmettre sont ceux de l'onglet ; l'URL de la frame est jointe à part. */
function withTabContext(request: SearchRequest, sender: MessageSender, urlMode: Parameters<typeof sanitizeUrl>[1]): SearchRequest {
    if (!sender.frameId || !sender.tab?.url) {
        return request;
    }
    return {
        ...request,
        frameUrl: request.pageUrl,
        pageUrl: sanitizeUrl(sender.tab.url, urlMode),
        pageTitle: cleanString(sender.tab.title, 200) || request.pageTitle,
    };
}

/* ---- Recherche -------------------------------------------------------------------------------------------------- */

async function handleSearch(message: Record<string, unknown>, sender: MessageSender): Promise<{ ok: boolean }> {
    const tabId = sender.tab?.id;
    const requestId = typeof message.requestId === 'string' ? message.requestId.slice(0, 40) : '';
    const cleaned = sanitizeSearchRequest(message.request);
    if (tabId === undefined || !requestId || !cleaned) {
        return { ok: false };
    }

    const settings = await loadSettings(api);
    const lang = resolveLanguage(settings.uiLanguage, api.i18n.getUILanguage());
    const request = withTabContext(cleaned, sender, settings.urlMode);
    const manualSearchUrl = manualSearchLink(settings, request.text);
    const fail = async (error: PanelError): Promise<{ ok: boolean }> => {
        await toPanel(tabId, { type: 'panel:error', requestId, request, lang, error, manualSearchUrl });
        return { ok: false };
    };

    let origin: URL | null = null;
    try {
        origin = new URL(sender.url ?? sender.tab?.url ?? '');
    } catch {
        origin = null;
    }
    if (!origin || !isHostAllowed(origin.hostname, origin.protocol, settings.allowedDomains)) {
        return fail({ kind: 'domainNotAllowed' });
    }

    const apiCheck = validateHttpUrl(settings.apiUrl);
    const token = await loadToken(api);
    if (!apiCheck.ok || !token) {
        return fail({ kind: 'notConfigured' });
    }
    let permitted = false;
    try {
        permitted = await api.permissions.contains({ origins: [apiCheck.matchPattern] });
    } catch {
        permitted = false;
    }
    if (!permitted) {
        return fail({ kind: 'permission' });
    }

    inFlight.get(tabId)?.abort();
    const controller = new AbortController();
    inFlight.set(tabId, controller);
    await toPanel(tabId, { type: 'panel:loading', requestId, request, lang });

    try {
        const response = await searchTranslations(request, {
            apiUrl: apiCheck.url,
            token,
            timeoutMs: settings.timeoutMs,
            signal: controller.signal,
        });
        const view = buildResultsView(response, request, settings);
        const results: PanelResultsMessage = {
            type: 'panel:results',
            requestId,
            request,
            lang,
            state: view.state,
            results: view.results,
            manualSearchUrl,
            feedbackEnabled: settings.feedbackEnabled,
        };
        await toPanel(tabId, results);
        return { ok: true };
    } catch (error) {
        if (error instanceof ApiError && error.kind === 'aborted') {
            return { ok: false }; // remplacée par une recherche plus récente : rien à afficher
        }
        return fail(toPanelError(error));
    } finally {
        if (inFlight.get(tabId) === controller) {
            inFlight.delete(tabId);
        }
    }
}

async function handleNoText(message: Record<string, unknown>, sender: MessageSender): Promise<{ ok: boolean }> {
    const tabId = sender.tab?.id;
    const requestId = typeof message.requestId === 'string' ? message.requestId.slice(0, 40) : '';
    if (tabId === undefined || !requestId) {
        return { ok: false };
    }
    const settings = await loadSettings(api);
    const lang = resolveLanguage(settings.uiLanguage, api.i18n.getUILanguage());
    await toPanel(tabId, {
        type: 'panel:error',
        requestId,
        lang,
        error: { kind: 'noText' },
        manualSearchUrl: null,
    });
    return { ok: true };
}

async function handleFeedback(message: Record<string, unknown>, sender: MessageSender): Promise<{ ok: boolean }> {
    const request = sanitizeSearchRequest(message.request);
    const key = cleanString(message.key, 300);
    const resultId = typeof message.resultId === 'number' || typeof message.resultId === 'string' ? message.resultId : key;
    const settings = await loadSettings(api);
    const apiCheck = validateHttpUrl(settings.apiUrl);
    const token = await loadToken(api);
    if (!settings.feedbackEnabled || !request || !key || !apiCheck.ok || !token) {
        return { ok: false };
    }
    const tab = withTabContext(request, sender, settings.urlMode);
    try {
        await sendFeedback(
            { text: tab.text, resultId, key, pageUrl: tab.pageUrl },
            { apiUrl: apiCheck.url, token, timeoutMs: settings.timeoutMs },
        );
        return { ok: true };
    } catch {
        return { ok: false };
    }
}

/** Test depuis la page Options avec les valeurs du formulaire ; un token vide réutilise le token enregistré. */
async function handleTestConnection(message: Record<string, unknown>): Promise<Record<string, unknown>> {
    const settings = await loadSettings(api);
    const apiCheck = validateHttpUrl(typeof message.apiUrl === 'string' ? message.apiUrl : settings.apiUrl);
    const typed = typeof message.token === 'string' ? message.token.trim() : '';
    const token = typed || (await loadToken(api));
    if (!apiCheck.ok || !token) {
        return { ok: false, error: { kind: 'notConfigured' } satisfies PanelError };
    }
    const probe: SearchRequest = {
        text: 'Test',
        pageUrl: '',
        pageTitle: '',
        surroundingText: '',
        elementTag: '',
        elementId: '',
        elementClasses: [],
        ariaLabel: '',
        title: '',
        dataAttributes: {},
        lang: 'fr',
        maxResults: 1,
        uiLanguage: 'fr',
    };
    try {
        const response = await searchTranslations(probe, {
            apiUrl: apiCheck.url,
            token,
            timeoutMs: settings.timeoutMs,
        });
        return { ok: true, count: response.results.length };
    } catch (error) {
        return { ok: false, error: toPanelError(error) };
    }
}

async function closeInspector(sender: MessageSender): Promise<{ ok: boolean }> {
    const tabId = sender.tab?.id;
    if (tabId === undefined) {
        return { ok: false };
    }
    inFlight.get(tabId)?.abort();
    try {
        await api.tabs.sendMessage(tabId, { type: 'inspector:close' });
    } catch {
        // Aucune frame à l'écoute.
    }
    return { ok: true };
}

/* ---- Routage des messages --------------------------------------------------------------------------------------- */

async function dispatch(message: Record<string, unknown>, sender: MessageSender): Promise<unknown> {
    switch (message.type) {
        case 'search':
            return handleSearch(message, sender);
        case 'noText':
            return handleNoText(message, sender);
        case 'feedback':
            return handleFeedback(message, sender);
        case 'closeInspector':
            return closeInspector(sender);
        case 'openOptions':
            await api.runtime.openOptionsPage();
            return { ok: true };
        case 'testConnection':
            // Réservé aux pages de l'extension : un content script (page web) ne peut pas l'utiliser.
            return isFromExtensionPage(sender, api.runtime.getURL('')) ? handleTestConnection(message) : { ok: false };
        default:
            return { ok: false };
    }
}

api.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (sender.id !== api.runtime.id || !hasType(message)) {
        return false;
    }
    dispatch(message, sender).then(
        (response) => sendResponse(response),
        () => sendResponse({ ok: false }),
    );
    return true; // réponse asynchrone
});

/* ---- Cycle de vie ----------------------------------------------------------------------------------------------- */

api.runtime.onInstalled.addListener((details) => {
    void syncContentScripts(api);
    void refreshBadge();
    if (isRecord(details) && details.reason === 'install') {
        void api.runtime.openOptionsPage().catch(() => undefined);
    }
});

api.runtime.onStartup.addListener(() => {
    void syncContentScripts(api);
    void refreshBadge();
});

api.storage.onChanged.addListener((changes, area) => {
    if (area !== 'local') {
        return;
    }
    if (ENABLED_KEY in changes) {
        void refreshBadge();
    }
    if (SETTINGS_KEY in changes) {
        void syncContentScripts(api);
    }
});

api.permissions.onAdded?.addListener(() => void syncContentScripts(api));
api.permissions.onRemoved?.addListener(() => void syncContentScripts(api));

api.commands?.onCommand.addListener((command) => {
    if (command === 'toggle-inspector') {
        void loadEnabled(api).then((enabled) => saveEnabled(!enabled, api));
    }
});

void refreshBadge();
