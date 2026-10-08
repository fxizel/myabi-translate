import { vi } from 'vitest';
import type { ExtensionApi, StorageArea } from '../src/shared/browserApi';
import type { SearchRequest, SearchResult } from '../src/types';

export interface MemoryArea extends StorageArea {
    data: Record<string, unknown>;
}

export function memoryArea(initial: Record<string, unknown> = {}): MemoryArea {
    const data: Record<string, unknown> = { ...initial };
    return {
        data,
        async get(keys) {
            if (keys === undefined || keys === null) {
                return { ...data };
            }
            const wanted = typeof keys === 'string' ? [keys] : keys;
            return Object.fromEntries(wanted.filter((key) => key in data).map((key) => [key, data[key]]));
        },
        async set(items) {
            Object.assign(data, items);
        },
        async remove(keys) {
            for (const key of typeof keys === 'string' ? [keys] : keys) {
                delete data[key];
            }
        },
    };
}

/** API WebExtensions factice : stockage en mémoire, messages enregistrés. */
export function fakeApi(local: Record<string, unknown> = {}) {
    const sent: unknown[] = [];
    const localArea = memoryArea(local);
    const sessionArea = memoryArea();
    const api = {
        runtime: {
            id: 'test-extension',
            sendMessage: vi.fn(async (message: unknown) => {
                sent.push(message);
                return { ok: true };
            }),
            onMessage: { addListener: vi.fn(), removeListener: vi.fn() },
            openOptionsPage: vi.fn(async () => undefined),
        },
        storage: {
            local: localArea,
            session: sessionArea,
            onChanged: { addListener: vi.fn(), removeListener: vi.fn() },
        },
        i18n: { getUILanguage: () => 'fr-CH' },
    } as unknown as ExtensionApi;
    return { api, sent, localArea, sessionArea };
}

export function sampleRequest(overrides: Partial<SearchRequest> = {}): SearchRequest {
    return {
        text: 'Commande',
        pageUrl: 'https://myab.example.test/orders',
        pageTitle: 'Commandes',
        surroundingText: '',
        elementTag: 'span',
        elementId: '',
        elementClasses: [],
        ariaLabel: '',
        title: '',
        dataAttributes: {},
        lang: 'fr',
        maxResults: 5,
        uiLanguage: 'fr',
        ...overrides,
    };
}

export function sampleResult(overrides: Partial<SearchResult> = {}): SearchResult {
    return {
        id: 1245,
        key: 'PURCHASE_ORDER',
        sourceLanguage: 'de',
        sourceText: 'Bestellung',
        currentTranslation: 'Commande',
        proposedTranslation: '',
        targetLanguage: 'fr',
        module: 'Purchasing',
        status: 'approved',
        score: 0.96,
        translationUrl: 'https://translations.example.test/translations/1245',
        ...overrides,
    };
}

/** Réponse de l'exemple de la spécification. */
export const specResponse = {
    query: 'Commande',
    results: [
        {
            id: 1245,
            key: 'PURCHASE_ORDER',
            sourceLanguage: 'de',
            sourceText: 'Bestellung',
            currentTranslation: 'Commande',
            proposedTranslation: 'Commande fournisseur',
            module: 'Purchasing',
            status: 'approved',
            score: 0.96,
            translationUrl: 'https://translations.example.test/translations/1245',
        },
        {
            id: 678,
            key: 'ORDER',
            sourceLanguage: 'de',
            sourceText: 'Auftrag',
            currentTranslation: 'Commande',
            module: 'Sales',
            status: 'review',
            score: 0.71,
            translationUrl: 'https://translations.example.test/translations/678',
        },
    ],
};
