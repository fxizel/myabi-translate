import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, parseSearchResponse, searchTranslations, sendFeedback } from '../src/api/translationApi';
import { sampleRequest, specResponse } from './helpers';

const TOKEN = 'secret-token-1234';
const API = 'https://translations.example.test/api/browser-extension';

function json(body: unknown, init: ResponseInit = {}): Response {
    return new Response(JSON.stringify(body), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
        ...init,
    });
}

function options(fetchImpl: (input: string, init: RequestInit) => Promise<Response>, extra = {}) {
    return { apiUrl: API, token: TOKEN, timeoutMs: 1000, fetchImpl, ...extra };
}

async function failure(promise: Promise<unknown>): Promise<ApiError> {
    try {
        await promise;
    } catch (error) {
        expect(error).toBeInstanceOf(ApiError);
        return error as ApiError;
    }
    throw new Error('La promesse aurait dû être rejetée');
}

afterEach(() => vi.restoreAllMocks());

describe('searchTranslations — requête', () => {
    it('envoie un POST JSON authentifié, sans cookies, vers /search', async () => {
        const fetchImpl = vi.fn(async (_input: string, _init: RequestInit) => json(specResponse));
        const request = sampleRequest();
        await searchTranslations(request, options(fetchImpl));

        expect(fetchImpl).toHaveBeenCalledTimes(1);
        const [url, init] = fetchImpl.mock.calls[0]!;
        expect(url).toBe(`${API}/search`);
        expect(init.method).toBe('POST');
        expect(init.credentials).toBe('omit');
        expect(init.headers).toMatchObject({
            'Content-Type': 'application/json',
            Authorization: `Bearer ${TOKEN}`,
        });
        expect(JSON.parse(String(init.body))).toEqual(request);
    });
});

describe('searchTranslations — réponse', () => {
    it('analyse l’exemple de la spécification', async () => {
        const response = await searchTranslations(sampleRequest(), options(async () => json(specResponse)));
        expect(response.query).toBe('Commande');
        expect(response.results.map((result) => result.key)).toEqual(['PURCHASE_ORDER', 'ORDER']);
        expect(response.results[0]).toMatchObject({
            id: 1245,
            sourceLanguage: 'de',
            sourceText: 'Bestellung',
            currentTranslation: 'Commande',
            proposedTranslation: 'Commande fournisseur',
            module: 'Purchasing',
            status: 'approved',
            score: 0.96,
        });
        expect(response.results[1]?.proposedTranslation).toBe('');
    });

    it('accepte une liste vide', async () => {
        const response = await searchTranslations(sampleRequest(), options(async () => json({ query: 'x', results: [] })));
        expect(response.results).toEqual([]);
    });
});

describe('parseSearchResponse', () => {
    it('écarte les entrées sans clé et borne le score à [0, 1]', () => {
        const parsed = parseSearchResponse({
            query: 'x',
            results: [
                { key: 'A', score: 96 },
                { key: 'B', score: 7 },
                { key: 'C', score: -3 },
                { key: 'D', score: 'abc' },
                { key: 'E', score: '0.5' },
                { score: 0.9 },
                'pas un objet',
            ],
        });
        expect(parsed.results.map((result) => [result.key, result.score])).toEqual([
            ['A', 0.96],
            ['B', 0.07],
            ['C', 0],
            ['D', 0],
            ['E', 0.5],
        ]);
    });

    it('refuse une réponse sans tableau results', () => {
        expect(() => parseSearchResponse({ query: 'x' })).toThrow(ApiError);
        expect(() => parseSearchResponse('<html>')).toThrow(ApiError);
    });

    it('utilise la clé comme identifiant quand id manque', () => {
        expect(parseSearchResponse({ results: [{ key: 'K' }] }).results[0]?.id).toBe('K');
    });
});

describe('searchTranslations — erreurs', () => {
    const cases: Array<[number, string]> = [
        [401, 'unauthorized'],
        [403, 'forbidden'],
        [404, 'notFound'],
        [429, 'rateLimited'],
        [500, 'server'],
        [503, 'server'],
        [422, 'http'],
    ];

    it.each(cases)('HTTP %i → %s', async (status, kind) => {
        const error = await failure(
            searchTranslations(sampleRequest(), options(async () => new Response('{}', { status }))),
        );
        expect(error.kind).toBe(kind);
        expect(error.status).toBe(status);
    });

    it('lit Retry-After (secondes) sur un 429', async () => {
        const error = await failure(
            searchTranslations(
                sampleRequest(),
                options(async () => new Response('{}', { status: 429, headers: { 'Retry-After': '30' } })),
            ),
        );
        expect(error.retryAfterSec).toBe(30);
    });

    it('signale une API inaccessible', async () => {
        const error = await failure(
            searchTranslations(sampleRequest(), options(async () => Promise.reject(new TypeError('Failed to fetch')))),
        );
        expect(error.kind).toBe('network');
    });

    it('interrompt la requête au bout du délai', async () => {
        const hang = (_input: string, init: RequestInit) =>
            new Promise<Response>((_resolve, reject) => {
                init.signal?.addEventListener('abort', () => reject(new Error('aborted')));
            });
        const error = await failure(searchTranslations(sampleRequest(), options(hang, { timeoutMs: 20 })));
        expect(error.kind).toBe('timeout');
    });

    it('distingue l’annulation voulue du délai dépassé', async () => {
        const controller = new AbortController();
        const hang = (_input: string, init: RequestInit) =>
            new Promise<Response>((_resolve, reject) => {
                init.signal?.addEventListener('abort', () => reject(new Error('aborted')));
            });
        const pending = failure(searchTranslations(sampleRequest(), options(hang, { signal: controller.signal })));
        controller.abort();
        expect((await pending).kind).toBe('aborted');
    });

    it('refuse un corps qui n’est pas du JSON', async () => {
        const error = await failure(
            searchTranslations(sampleRequest(), options(async () => new Response('<html>Connexion</html>', { status: 200 }))),
        );
        expect(error.kind).toBe('invalidResponse');
    });

    it('refuse une redirection finale vers du HTTP non sécurisé', async () => {
        const insecure = async () => {
            const response = json(specResponse);
            Object.defineProperty(response, 'url', { value: 'http://translations.example.test/api/browser-extension/search' });
            return response;
        };
        expect((await failure(searchTranslations(sampleRequest(), options(insecure)))).kind).toBe('insecure');
    });
});

describe('confidentialité du token', () => {
    it('n’apparaît ni dans les erreurs ni dans la console', async () => {
        const spies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((method) =>
            vi.spyOn(console, method).mockImplementation(() => undefined),
        );
        for (const status of [401, 403, 429, 500]) {
            const error = await failure(
                searchTranslations(sampleRequest(), options(async () => new Response('{}', { status }))),
            );
            expect(error.message).not.toContain(TOKEN);
            expect(JSON.stringify(error)).not.toContain(TOKEN);
        }
        const networkError = await failure(
            searchTranslations(sampleRequest(), options(async () => Promise.reject(new Error(`boom ${TOKEN}`)))),
        );
        expect(networkError.message).not.toContain(TOKEN);
        for (const spy of spies) {
            expect(JSON.stringify(spy.mock.calls)).not.toContain(TOKEN);
        }
    });
});

describe('sendFeedback', () => {
    it('poste le verdict « correct » vers /feedback', async () => {
        const fetchImpl = vi.fn(async (_input: string, _init: RequestInit) => json({ ok: true }));
        await sendFeedback(
            { text: 'Commande', resultId: 1245, key: 'PURCHASE_ORDER', pageUrl: 'https://myab.example.test/orders' },
            options(fetchImpl),
        );
        const [url, init] = fetchImpl.mock.calls[0]!;
        expect(url).toBe(`${API}/feedback`);
        expect(JSON.parse(String(init.body))).toMatchObject({ key: 'PURCHASE_ORDER', verdict: 'correct' });
    });
});
