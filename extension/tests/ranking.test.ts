import { describe, expect, it } from 'vitest';
import {
    AMBIGUITY_GAP,
    classifyResults,
    formatScore,
    isExactMatch,
    rankResults,
    toPanelResults,
} from '../src/shared/ranking';
import { applicationBase, buildResultsView, manualSearchLink } from '../src/shared/viewModel';
import { normalizeSettings } from '../src/shared/settings';
import { parseSearchResponse } from '../src/api/translationApi';
import { sampleResult, specResponse } from './helpers';

describe('formatScore', () => {
    it('affiche un pourcentage avec espace insécable', () => {
        expect(formatScore(0.96)).toBe('96 %');
        expect(formatScore(0.714)).toBe('71 %');
        expect(formatScore(1.4)).toBe('100 %');
        expect(formatScore(-1)).toBe('0 %');
    });
});

describe('isExactMatch', () => {
    it('compare sans casse ni accents, à la traduction courante ou au texte source', () => {
        expect(isExactMatch(sampleResult({ currentTranslation: 'Échéance' }), 'echeance')).toBe(true);
        expect(isExactMatch(sampleResult({ sourceText: 'Bestellung' }), 'bestellung')).toBe(true);
        expect(isExactMatch(sampleResult({ currentTranslation: 'Commande fournisseur', sourceText: 'x' }), 'Commande')).toBe(false);
    });

    it('fait confiance à l’indicateur exact de l’API', () => {
        expect(isExactMatch(sampleResult({ currentTranslation: 'autre', exact: true }), 'Commande')).toBe(true);
    });
});

describe('rankResults', () => {
    it('classe par score décroissant', () => {
        const ranked = rankResults(
            [sampleResult({ key: 'B', score: 0.5 }), sampleResult({ key: 'A', score: 0.96 }), sampleResult({ key: 'C', score: 0.71 })],
            'Commande',
        );
        expect(ranked.map((result) => result.key)).toEqual(['A', 'C', 'B']);
    });

    it('à score égal : exactes d’abord, puis ordre alphabétique', () => {
        const ranked = rankResults(
            [
                sampleResult({ key: 'Z_EXACT', score: 0.8, currentTranslation: 'Commande' }),
                sampleResult({ key: 'A_APPROX', score: 0.8, currentTranslation: 'Commande fournisseur', sourceText: 'x' }),
                sampleResult({ key: 'B_EXACT', score: 0.8, currentTranslation: 'Commande' }),
            ],
            'Commande',
        );
        expect(ranked.map((result) => result.key)).toEqual(['B_EXACT', 'Z_EXACT', 'A_APPROX']);
    });

    it('borne le nombre de résultats sans modifier l’entrée', () => {
        const input = [sampleResult({ key: 'A', score: 0.1 }), sampleResult({ key: 'B', score: 0.9 })];
        expect(rankResults(input, 'x', 1).map((result) => result.key)).toEqual(['B']);
        expect(input.map((result) => result.key)).toEqual(['A', 'B']);
    });
});

describe('classifyResults', () => {
    const exact = (key: string, score: number) => sampleResult({ key, score, currentTranslation: 'Commande' });
    const approximate = (key: string, score: number) =>
        sampleResult({ key, score, currentTranslation: 'Commande fournisseur', sourceText: 'Bestellung' });

    it('aucun résultat', () => {
        expect(classifyResults([], 'Commande')).toBe('none');
    });

    it('correspondance forte : exacte, score élevé et nettement devant la suivante', () => {
        expect(classifyResults([exact('A', 0.96), exact('B', 0.71)], 'Commande')).toBe('strong');
        expect(0.96 - 0.71).toBeGreaterThanOrEqual(AMBIGUITY_GAP);
    });

    it('une seule correspondance exacte est forte même avec un score modeste', () => {
        expect(classifyResults([exact('A', 0.75)], 'Commande')).toBe('strong');
    });

    it('plusieurs correspondances possibles quand elles sont proches', () => {
        expect(classifyResults([exact('A', 0.92), exact('B', 0.88)], 'Commande')).toBe('multiple');
    });

    it('plusieurs correspondances si la première n’est pas assez sûre', () => {
        expect(classifyResults([exact('A', 0.8), exact('B', 0.4)], 'Commande')).toBe('multiple');
    });

    it('aucune correspondance exacte : résultats seulement approximatifs', () => {
        expect(classifyResults([approximate('A', 0.97), approximate('B', 0.5)], 'Commande')).toBe('noExact');
    });
});

describe('affichage des résultats (modèle de vue)', () => {
    const settings = normalizeSettings({
        apiUrl: 'https://translations.example.test/api/browser-extension',
        maxResults: 5,
    });
    const request = { text: 'Commande' } as Parameters<typeof buildResultsView>[1];

    it('classe l’exemple de la spécification et le qualifie de correspondance forte', () => {
        const view = buildResultsView(parseSearchResponse(specResponse), request, settings);
        expect(view.state).toBe('strong');
        expect(view.results.map((result) => [result.key, result.scoreLabel])).toEqual([
            ['PURCHASE_ORDER', '96 %'],
            ['ORDER', '71 %'],
        ]);
        expect(view.results[0]?.openUrl).toBe('https://translations.example.test/translations/1245');
    });

    it('respecte le nombre maximum de résultats', () => {
        const limited = normalizeSettings({ apiUrl: settings.apiUrl, maxResults: 1 });
        const view = buildResultsView(parseSearchResponse(specResponse), request, limited);
        expect(view.results).toHaveLength(1);
    });

    it('n’expose un lien que vers l’origine de l’application', () => {
        const links = toPanelResults(
            [
                sampleResult({ translationUrl: 'https://translations.example.test/t/1' }),
                sampleResult({ translationUrl: 'https://evil.example.com/t/1' }),
                sampleResult({ translationUrl: 'javascript:alert(1)' }),
                sampleResult({ translationUrl: '' }),
            ],
            'Commande',
            (url) => (url.startsWith('https://translations.example.test/') ? url : null),
        );
        expect(links.map((result) => result.openUrl)).toEqual(['https://translations.example.test/t/1', null, null, null]);
    });

    it('construit la recherche manuelle sur l’application configurée', () => {
        expect(manualSearchLink(settings, 'Commande & co')).toBe('https://translations.example.test/terms?q=Commande%20%26%20co');
        const withApp = normalizeSettings({ apiUrl: settings.apiUrl, appUrl: 'https://app.example.test/base/' });
        expect(applicationBase(withApp)).toBe('https://app.example.test/base');
        expect(manualSearchLink(withApp, 'x')).toBe('https://app.example.test/base/terms?q=x');
        expect(manualSearchLink(normalizeSettings({}), 'x')).toBeNull();
    });
});
