/**
 * Sous-ensemble WebExtensions réellement utilisé, avec des signatures à base de promesses.
 * Chrome (MV3) et Firefox (MV3) les fournissent via `chrome.*` / `browser.*` : un seul code.
 * Les types sont volontairement locaux (pas de dépendance à @types/chrome).
 */
export interface StorageArea {
    get(keys?: string | string[] | null): Promise<Record<string, unknown>>;
    set(items: Record<string, unknown>): Promise<void>;
    remove(keys: string | string[]): Promise<void>;
}

export interface MessageSender {
    id?: string;
    url?: string;
    frameId?: number;
    tab?: { id?: number; url?: string; title?: string };
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
interface ExtEvent<F extends (...args: any[]) => unknown> {
    addListener(callback: F): void;
    removeListener(callback: F): void;
}

export interface ScriptRegistration {
    id: string;
    matches: string[];
    js: string[];
    runAt?: 'document_start' | 'document_end' | 'document_idle';
    allFrames?: boolean;
    persistAcrossSessions?: boolean;
}

export interface ExtensionApi {
    runtime: {
        id: string;
        sendMessage(message: unknown): Promise<unknown>;
        onMessage: ExtEvent<
            (message: unknown, sender: MessageSender, sendResponse: (response?: unknown) => void) => boolean | void
        >;
        onInstalled: ExtEvent<(details: unknown) => void>;
        onStartup: ExtEvent<() => void>;
        openOptionsPage(): Promise<void>;
        getURL(path: string): string;
    };
    storage: {
        local: StorageArea;
        session?: StorageArea;
        onChanged: ExtEvent<
            (changes: Record<string, { oldValue?: unknown; newValue?: unknown }>, areaName: string) => void
        >;
    };
    tabs: {
        query(query: { active?: boolean; currentWindow?: boolean }): Promise<Array<{ id?: number; url?: string }>>;
        sendMessage(tabId: number, message: unknown, options?: { frameId?: number }): Promise<unknown>;
    };
    scripting: {
        registerContentScripts(scripts: ScriptRegistration[]): Promise<void>;
        updateContentScripts(scripts: ScriptRegistration[]): Promise<void>;
        unregisterContentScripts(filter?: { ids?: string[] }): Promise<void>;
        getRegisteredContentScripts(filter?: { ids?: string[] }): Promise<ScriptRegistration[]>;
    };
    permissions: {
        request(permissions: { origins?: string[] }): Promise<boolean>;
        contains(permissions: { origins?: string[] }): Promise<boolean>;
        remove(permissions: { origins?: string[] }): Promise<boolean>;
        onAdded?: ExtEvent<() => void>;
        onRemoved?: ExtEvent<() => void>;
    };
    action: {
        setBadgeText(details: { text: string }): Promise<void>;
        setBadgeBackgroundColor(details: { color: string }): Promise<void>;
    };
    commands?: {
        getAll(): Promise<Array<{ name?: string; shortcut?: string }>>;
        onCommand: ExtEvent<(command: string) => void>;
    };
    i18n: { getUILanguage(): string };
}

/**
 * Vrai si le message vient d'une page de l'extension (Paramètres, popup) et non d'un content script.
 * `sender.tab` ne convient pas : la page Paramètres s'ouvre dans un onglet. Une page web ne peut pas
 * avoir une URL d'extension ; l'URL d'un content script est celle de la page hôte.
 */
export function isFromExtensionPage(sender: MessageSender, extensionBaseUrl: string): boolean {
    return extensionBaseUrl !== '' && typeof sender.url === 'string' && sender.url.startsWith(extensionBaseUrl);
}

export function getApi(): ExtensionApi {
    const scope = globalThis as unknown as { browser?: ExtensionApi; chrome?: ExtensionApi };
    const api = scope.browser ?? scope.chrome;
    if (!api) {
        throw new Error('WebExtension API unavailable');
    }
    return api;
}
