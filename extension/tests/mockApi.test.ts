// @vitest-environment node
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { ApiError, searchTranslations, sendFeedback } from '../src/api/translationApi';
import { normalizeSettings } from '../src/shared/settings';
import { buildResultsView } from '../src/shared/viewModel';
import { createMockServer } from '../scripts/mock-api.mjs';
import { sampleRequest } from './helpers';

// Test de bout en bout de la couche API : vrai client de l'extension, vrai HTTP, faux serveur.
const server = createMockServer({ slowMs: 300, log: () => undefined });
let apiUrl = '';

const call = (text: string, token = 'ok', extra = {}) =>
    searchTranslations(sampleRequest({ text }), { apiUrl, token, timeoutMs: 2000, ...extra });

async function kind(promise: Promise<unknown>): Promise<ApiError> {
    try {
        await promise;
    } catch (error) {
        expect(error).toBeInstanceOf(ApiError);
        return error as ApiError;
    }
    throw new Error('rejet attendu');
}

function view(text: string, response: Awaited<ReturnType<typeof call>>) {
    const settings = normalizeSettings({ apiUrl });
    return buildResultsView(response, sampleRequest({ text }), settings);
}

beforeAll(async () => {
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    apiUrl = `http://127.0.0.1:${(server.address() as { port: number }).port}/api/browser-extension`;
});

afterAll(async () => {
    await new Promise((resolve) => server.close(resolve));
});

describe('scénarios de résultats', () => {
    it('« Commande » : correspondance forte, 96 % puis 71 %, liens vers l’application', async () => {
        const result = view('Commande', await call('Commande'));
        expect(result.state).toBe('strong');
        expect(result.results.map((r) => [r.key, r.scoreLabel])).toEqual([
            ['PURCHASE_ORDER', '96 %'],
            ['ORDER', '71 %'],
        ]);
        expect(result.results[0]?.openUrl).toBe(`${new URL(apiUrl).origin}/translations/1245`);
    });

    it('« Statut » : plusieurs correspondances possibles', async () => {
        expect(view('Statut', await call('Statut')).state).toBe('multiple');
    });

    it('« Enregistrer » : aucune exacte, résultats approximatifs', async () => {
        const result = view('Enregistrer', await call('Enregistrer'));
        expect(result.state).toBe('noExact');
        expect(result.results).toHaveLength(3);
    });

    it('« Événement » (avec ou sans accent) : « Ereignis » et une clé très longue', async () => {
        for (const text of ['Événement', 'evenement']) {
            const result = view(text, await call(text));
            expect(result.state).toBe('strong');
            expect(result.results[0]).toMatchObject({
                key: 'modulesadministrationManagementcourseCoursesOverviewConfig.Event',
                sourceText: 'Ereignis',
                currentTranslation: 'Événement',
                sourceLanguage: 'de',
            });
        }
    });

    it('« Valider » : une seule correspondance exacte', async () => {
        expect(view('Valider', await call('Valider')).state).toBe('strong');
    });

    it('texte quelconque (MyAB réel) : réponse générée, une exacte et une approximative', async () => {
        const result = view('Bon de livraison n° 5', await call('Bon de livraison n° 5'));
        expect(result.state).toBe('strong');
        expect(result.results.map((r) => [r.key, r.exactMatch])).toEqual([
            ['BON_DE_LIVRAISON_N_5', true],
            ['BON_DE_LIVRAISON_N_5_VARIANT', false],
        ]);
        expect(result.results[0]?.openUrl).toContain('/translations/');
    });

    it('token « empty » : aucun résultat, quel que soit le texte', async () => {
        const result = view('Commande', await call('Commande', 'empty'));
        expect(result.state).toBe('none');
        expect(result.results).toEqual([]);
    });

    it('contenu hostile : liens javascript: et hors origine neutralisés', async () => {
        const result = view('xss', await call('xss'));
        expect(result.results.map((r) => r.openUrl)).toEqual([null, null]);
    });

    it('respecte maxResults', async () => {
        const response = await searchTranslations(sampleRequest({ text: 'Statut', maxResults: 1 }), {
            apiUrl,
            token: 'ok',
            timeoutMs: 2000,
        });
        expect(response.results).toHaveLength(1);
    });
});

describe('scénarios d’erreur (choisis par le token)', () => {
    it.each([
        ['401', 'unauthorized', 401],
        ['expired', 'unauthorized', 401],
        ['403', 'forbidden', 403],
        ['429', 'rateLimited', 429],
        ['500', 'server', 500],
    ])('token « %s » → %s', async (token, expected, status) => {
        const error = await kind(call('Commande', token));
        expect(error.kind).toBe(expected);
        expect(error.status).toBe(status);
    });

    it('429 transmet Retry-After', async () => {
        expect((await kind(call('Commande', '429'))).retryAfterSec).toBe(5);
    });

    it('sans token : 401', async () => {
        expect((await kind(call('Commande', ''))).kind).toBe('unauthorized');
    });

    it('corps non JSON : réponse invalide', async () => {
        expect((await kind(call('Commande', 'badjson'))).kind).toBe('invalidResponse');
    });

    it('connexion coupée : erreur réseau', async () => {
        expect((await kind(call('Commande', 'down'))).kind).toBe('network');
    });

    it('réponse trop lente : délai dépassé', async () => {
        expect((await kind(call('Commande', 'slow', { timeoutMs: 50 }))).kind).toBe('timeout');
    });

    it('URL d’API erronée : 404', async () => {
        const error = await kind(
            searchTranslations(sampleRequest(), { apiUrl: `${apiUrl}/inexistant`, token: 'ok', timeoutMs: 2000 }),
        );
        expect(error.kind).toBe('notFound');
    });
});

describe('autres routes du faux serveur', () => {
    it('feedback accepté', async () => {
        await expect(
            sendFeedback({ text: 'Commande', resultId: 1245, key: 'PURCHASE_ORDER', pageUrl: '' }, { apiUrl, token: 'ok', timeoutMs: 2000 }),
        ).resolves.toBeUndefined();
    });

    it('conserve la requête reçue sans jamais enregistrer le token', async () => {
        await call('Commande', 'ok');
        const last = server.requests.at(-1);
        expect(last).toMatchObject({ endpoint: 'search', mode: 'ok', body: { text: 'Commande' } });
        expect(JSON.stringify(server.requests)).not.toMatch(/Bearer/);
    });

    it('sert les pages ouvertes depuis le panneau', async () => {
        const origin = new URL(apiUrl).origin;
        expect((await fetch(`${origin}/translations/1245`)).status).toBe(200);
        expect(await (await fetch(`${origin}/terms?q=Commande`)).text()).toContain('Recherche : Commande');
    });
});
