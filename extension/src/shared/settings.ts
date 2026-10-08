import type {
    ActivationModifier,
    LangPreference,
    Settings,
    TokenPersistence,
    UrlMode,
} from '../types';
import { getApi, type ExtensionApi, type StorageArea } from './browserApi';
import { parseDomainList } from './domains';
import { isRecord } from './text';
import { validateHttpUrl } from './url';

export const SETTINGS_KEY = 'settings';
export const ENABLED_KEY = 'inspectorEnabled';
export const TOKEN_KEY = 'apiToken';

export const DEFAULT_MANUAL_SEARCH_TEMPLATE = '{appUrl}/terms?q={query}';

export const DEFAULT_SETTINGS: Settings = {
    apiUrl: '',
    appUrl: '',
    allowedDomains: [],
    uiLanguage: 'auto',
    activationModifier: 'alt',
    maxResults: 5,
    collectContext: true,
    urlMode: 'noQuery',
    timeoutMs: 10_000,
    feedbackEnabled: false,
    tokenPersistence: 'local',
    manualSearchUrlTemplate: DEFAULT_MANUAL_SEARCH_TEMPLATE,
    selectors: { breadcrumb: '', dialog: '', sectionTitle: '', ignore: '' },
};

export const MAX_RESULTS_RANGE = { min: 1, max: 20 } as const;
export const TIMEOUT_RANGE_MS = { min: 1_000, max: 30_000 } as const;

function oneOf<T extends string>(value: unknown, allowed: readonly T[], fallback: T): T {
    return typeof value === 'string' && (allowed as readonly string[]).includes(value) ? (value as T) : fallback;
}

function clampInt(value: unknown, min: number, max: number, fallback: number): number {
    const number = typeof value === 'number' ? value : typeof value === 'string' ? Number(value) : NaN;
    return Number.isFinite(number) ? Math.min(max, Math.max(min, Math.round(number))) : fallback;
}

function boolOr(value: unknown, fallback: boolean): boolean {
    return typeof value === 'boolean' ? value : fallback;
}

function selector(value: unknown): string {
    return typeof value === 'string' ? value.trim().slice(0, 300) : '';
}

/** Tolérant : toute valeur inconnue ou invalide retombe sur la valeur par défaut. Jamais d'exception. */
export function normalizeSettings(raw: unknown): Settings {
    const source = isRecord(raw) ? raw : {};
    const selectors = isRecord(source.selectors) ? source.selectors : {};
    const api = validateHttpUrl(typeof source.apiUrl === 'string' ? source.apiUrl : '');
    const app = validateHttpUrl(typeof source.appUrl === 'string' ? source.appUrl : '');
    const domains = parseDomainList(
        Array.isArray(source.allowedDomains)
            ? source.allowedDomains.filter((entry): entry is string => typeof entry === 'string')
            : [],
    );
    const template =
        typeof source.manualSearchUrlTemplate === 'string' && source.manualSearchUrlTemplate.includes('{query}')
            ? source.manualSearchUrlTemplate.trim().slice(0, 500)
            : DEFAULT_MANUAL_SEARCH_TEMPLATE;

    return {
        apiUrl: api.ok ? api.url : '',
        appUrl: app.ok ? app.url : '',
        allowedDomains: domains.domains,
        uiLanguage: oneOf<LangPreference>(source.uiLanguage, ['auto', 'fr', 'de', 'en'], DEFAULT_SETTINGS.uiLanguage),
        activationModifier: oneOf<ActivationModifier>(
            source.activationModifier,
            ['alt', 'altShift', 'altCtrl'],
            DEFAULT_SETTINGS.activationModifier,
        ),
        maxResults: clampInt(source.maxResults, MAX_RESULTS_RANGE.min, MAX_RESULTS_RANGE.max, DEFAULT_SETTINGS.maxResults),
        collectContext: boolOr(source.collectContext, DEFAULT_SETTINGS.collectContext),
        urlMode: oneOf<UrlMode>(source.urlMode, ['full', 'noQuery', 'origin'], DEFAULT_SETTINGS.urlMode),
        timeoutMs: clampInt(source.timeoutMs, TIMEOUT_RANGE_MS.min, TIMEOUT_RANGE_MS.max, DEFAULT_SETTINGS.timeoutMs),
        feedbackEnabled: boolOr(source.feedbackEnabled, DEFAULT_SETTINGS.feedbackEnabled),
        tokenPersistence: oneOf<TokenPersistence>(
            source.tokenPersistence,
            ['local', 'session'],
            DEFAULT_SETTINGS.tokenPersistence,
        ),
        manualSearchUrlTemplate: template,
        selectors: {
            breadcrumb: selector(selectors.breadcrumb),
            dialog: selector(selectors.dialog),
            sectionTitle: selector(selectors.sectionTitle),
            ignore: selector(selectors.ignore),
        },
    };
}

export async function loadSettings(api: ExtensionApi = getApi()): Promise<Settings> {
    const stored = await api.storage.local.get(SETTINGS_KEY);
    return normalizeSettings(stored[SETTINGS_KEY]);
}

export async function saveSettings(settings: Settings, api: ExtensionApi = getApi()): Promise<void> {
    await api.storage.local.set({ [SETTINGS_KEY]: settings });
}

export async function loadEnabled(api: ExtensionApi = getApi()): Promise<boolean> {
    const stored = await api.storage.local.get(ENABLED_KEY);
    return stored[ENABLED_KEY] === true;
}

export async function saveEnabled(enabled: boolean, api: ExtensionApi = getApi()): Promise<void> {
    await api.storage.local.set({ [ENABLED_KEY]: enabled });
}

/* ---- Token ------------------------------------------------------------------------------------------------------
 * Le token n'est lu que par le background et la page Options ; jamais par le content script, jamais journalisé.
 * « session » : conservé en mémoire du navigateur uniquement (storage.session, effacé à la fermeture).
 * « local »   : storage.local, isolé de la page mais non chiffré par le navigateur (protection du profil OS).
 * storage.sync n'est volontairement pas utilisé : le token ne doit pas quitter l'appareil.
 */

function tokenArea(api: ExtensionApi, persistence: TokenPersistence): StorageArea {
    if (persistence === 'session') {
        if (!api.storage.session) {
            throw new Error('storage.session unavailable');
        }
        return api.storage.session;
    }
    return api.storage.local;
}

export type TokenState = 'none' | TokenPersistence;

async function readToken(area: StorageArea | undefined): Promise<string> {
    if (!area) {
        return '';
    }
    const stored = await area.get(TOKEN_KEY);
    const value = stored[TOKEN_KEY];
    return typeof value === 'string' ? value : '';
}

export async function loadToken(api: ExtensionApi = getApi()): Promise<string> {
    return (await readToken(api.storage.session)) || (await readToken(api.storage.local));
}

export async function tokenState(api: ExtensionApi = getApi()): Promise<TokenState> {
    if (await readToken(api.storage.session)) {
        return 'session';
    }
    return (await readToken(api.storage.local)) ? 'local' : 'none';
}

export async function saveToken(token: string, persistence: TokenPersistence, api: ExtensionApi = getApi()): Promise<void> {
    const target = tokenArea(api, persistence);
    const other = tokenArea(api, persistence === 'local' ? 'session' : 'local');
    await target.set({ [TOKEN_KEY]: token });
    await other.remove(TOKEN_KEY);
}

export async function clearToken(api: ExtensionApi = getApi()): Promise<void> {
    await api.storage.local.remove(TOKEN_KEY);
    await api.storage.session?.remove(TOKEN_KEY);
}

/** Jeton plausible : non vide, sans espace ni caractère de contrôle, 512 caractères au plus. */
export function isPlausibleToken(token: string): boolean {
    return /^[^\s\u0000-\u001f\u007f]{1,512}$/.test(token);
}
