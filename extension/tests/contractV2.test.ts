import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, parseSearchResponse, searchTranslations, sendFeedback } from '../src/api/translationApi';
import { buildSearchRequest } from '../src/content/contextExtractor';
import { resolveText } from '../src/content/textExtractor';
import { sanitizeSearchRequest } from '../src/shared/messages';
import { buildResultsView } from '../src/shared/viewModel';
import { normalizeSettings } from '../src/shared/settings';
import { isExactMatch } from '../src/shared/ranking';
import { characterLength, truncate, excerptAround } from '../src/shared/text';
import { errorMessage } from '../src/shared/i18n';
import { Overlay } from '../src/content/overlay';
import { sampleRequest } from './helpers';

const apiUrl = 'https://translations.example.test/api/browser-extension';
const settings = normalizeSettings({ apiUrl });
const result = (extra: Record<string, unknown> = {}) => ({
    id: 'cell2.WzEyMzQsImxhYmVsIiwiZnIiXQ', termId: 1234, type: 'mric', key: 'EXAMPLE_LABEL', keyParts: ['EXAMPLE_LABEL'], attribute: 'label',
    targetLanguage: 'fr', sourceLanguage: 'de', sourceText: 'Beispiel', currentTranslation: '', importedTranslation: 'Commande',
    proposedTranslation: '', pendingCount: 2, matchedText: 'Commande', valueOrigin: 'imported', matchReason: 'literal', module: '',
    status: 'missing', score: 1, exact: true, previewTruncated: false, translationUrl: 'https://translations.example.test/terms/1234?language=fr', ...extra,
});
const response = (extra: Record<string, unknown> = {}) => ({ contractVersion: 2, query: 'Commande', matchState: 'strong', exactCandidateCount: 1, hasMore: false, candidateSetComplete: true, feedbackAllowed: false, results: [result()], ...extra });
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const callOptions = (fetchImpl: (url: string, init: RequestInit) => Promise<Response>) => ({ apiUrl, token: 'synthetic-secret', timeoutMs: 1000, fetchImpl });

afterEach(() => { vi.restoreAllMocks(); document.body.innerHTML = ''; });

describe('contrat v2 réel', () => {
    it('refuse le mock non versionné sauf mode explicite', () => {
        const legacy = { query: 'Commande', results: [{ key: 'K', currentTranslation: 'Commande' }] };
        expect(() => parseSearchResponse(legacy)).toThrow(ApiError);
        expect(parseSearchResponse(legacy, 'mock').mock).toBe(true);
        expect(() => parseSearchResponse({ ...legacy, contractVersion: 3 }, 'mock')).toThrow(ApiError);
    });
    it.each(['matchState', 'exactCandidateCount', 'hasMore', 'candidateSetComplete', 'feedbackAllowed', 'query'])('refuse métadonnée absente : %s', (field) => {
        const raw: Record<string, unknown> = response(); delete raw[field]; expect(() => parseSearchResponse(raw)).toThrow(ApiError);
    });
    it.each(['id', 'termId', 'type', 'keyParts', 'attribute', 'targetLanguage', 'sourceLanguage', 'importedTranslation', 'matchedText', 'pendingCount', 'valueOrigin', 'matchReason', 'status', 'exact', 'previewTruncated'])('refuse cellule incomplète : %s', (field) => {
        const cell: Record<string, unknown> = result(); delete cell[field]; expect(() => parseSearchResponse(response({ results: [cell] }))).toThrow(ApiError);
    });
    it('ne normalise ni ne tronque le tuple exact et la clé machine', () => {
        const parts = ['  KEY|', '𐐷'.repeat(4000), '|tail '];
        const parsed = parseSearchResponse(response({ results: [result({ key: parts.join('|'), keyParts: parts })] }));
        expect(parsed.results[0]?.keyParts).toEqual(parts); expect(parsed.results[0]?.key).toBe(parts.join('|'));
    });
    it('refuse les scores, statuts, types et métadonnées incohérents', () => {
        for (const extra of [{ score: '1' }, { score: Infinity }, { score: 100 }, { termId: 0 }, { keyParts: ['K', 3] }, { status: 'approved' }, { exact: 1 }, { pendingCount: -1 }, { proposedTranslation: 'Arbitrary' }]) {
            expect(() => parseSearchResponse(response({ results: [result(extra)] }))).toThrow(ApiError);
        }
        expect(() => parseSearchResponse(response({ candidateSetComplete: false }))).toThrow(ApiError);
        expect(() => parseSearchResponse(response({ exactCandidateCount: 2 }))).toThrow(ApiError);
    });
    it('préserve le verdict et ordre serveur, même avec un seul résultat visible', () => {
        const raw = response({ matchState: 'multiple', exactCandidateCount: 2, hasMore: true, results: [result({ key: 'Z' })] });
        const view = buildResultsView(parseSearchResponse(raw), sampleRequest({ maxResults: 1 }), normalizeSettings({ apiUrl, maxResults: 1 }));
        expect(view.state).toBe('multiple'); expect(view.hasMore).toBe(true);
        const cells = [result({ key: 'Z' }), result({ id: 'cell2.other', termId: 2, key: 'A' })];
        expect(buildResultsView(parseSearchResponse(response({ matchState: 'multiple', exactCandidateCount: 2, results: cells })), sampleRequest(), settings).results.map(c => c.key)).toEqual(['Z', 'A']);
    });
    it('ne recalcule pas exact:false et garde l’ambiguïté 1.00 / 0.88', () => {
        const approximate = result({ id: 'cell2.second', termId: 2, exact: false, score: 0.88, matchReason: 'normalized' });
        const parsed = parseSearchResponse(response({ matchState: 'multiple', results: [result(), approximate] }));
        const view = buildResultsView(parsed, sampleRequest(), settings);
        expect(view.state).toBe('multiple'); expect(view.results[1]?.exactMatch).toBe(false);
        expect(isExactMatch(parsed.results[1]!, 'Commande')).toBe(false);
    });
    it('affiche une recherche sans candidats incomplète comme noExact', () => {
        const parsed = parseSearchResponse(response({ matchState: 'noExact', exactCandidateCount: 0, results: [], candidateSetComplete: false, hasMore: true }));
        expect(buildResultsView(parsed, sampleRequest({ textTruncated: true }), settings)).toMatchObject({ state: 'noExact', candidateSetComplete: false });
    });
    it('réussit la sonde sur un catalogue vide', async () => {
        const parsed = await searchTranslations(sampleRequest({ text: 'Test', maxResults: 1 }), callOptions(async () => json(response({ query: 'Test', matchState: 'none', exactCandidateCount: 0, results: [] }))));
        expect(parsed.results).toEqual([]);
    });
});

describe('bornes et collecte v2', () => {
    it('borne chaque chaîne Unicode ellipse comprise et propage la troncature originale', () => {
        document.title = '𐐷'.repeat(201);
        document.body.innerHTML = '<p id="p" data-business="private" data-i18n-key="' + 'K'.repeat(101) + '">' + '𐐷'.repeat(501) + '</p>';
        const resolved = resolveText(document.getElementById('p')!); if (!resolved.ok) throw new Error('texte non résolu');
        expect(resolved.value.textTruncated).toBe(true);
        const request = sanitizeSearchRequest(buildSearchRequest(resolved.value, { ...settings, collectContext: true, searchLanguage: 'it' }, 'de'))!;
        expect(request).toMatchObject({ contractVersion: 2, textTruncated: true, searchLanguage: 'it', uiLanguage: 'de', dataAttributes: {} });
        expect(characterLength(request.text)).toBe(500); expect(characterLength(request.pageTitle)).toBe(200);
        expect(sanitizeSearchRequest({ ...request, text: 'court' })?.textTruncated).toBe(true);
        expect(truncate('𐐷'.repeat(7), 4)).toBe('𐐷𐐷𐐷…');
        expect(characterLength(excerptAround('𐐷'.repeat(100) + 'WORD' + '𐐷'.repeat(100), 'WORD', 20))).toBeLessThanOrEqual(20);
    });
    it('refuse des marqueurs v2 et langues explicitement invalides', () => {
        expect(sanitizeSearchRequest({ text: 'Test', contractVersion: 1 })).toBeNull();
        expect(sanitizeSearchRequest({ text: 'Test', textTruncated: 'true' })).toBeNull();
        expect(sanitizeSearchRequest({ text: 'Test', searchLanguage: 'es' })).toBeNull();
    });
    it('conserve exactement une indication de clé courte, omet les autres données', () => {
        const request = sanitizeSearchRequest({ text: 'Commande', dataAttributes: { 'data-i18n-key': ' K| ', 'data-account': 'private' }, maxResults: NaN });
        expect(request?.dataAttributes).toEqual({ 'data-i18n-key': ' K| ' }); expect(request?.maxResults).toBe(5);
        expect(normalizeSettings({})).toMatchObject({ backendMode: 'real', searchLanguage: 'auto', collectContext: false, urlMode: 'origin' });
    });
});

describe('erreurs et feedback v2', () => {
    it.each([
        ['fr', 'serveur est à jour', 'rechargez', 'autoriser son identifiant'],
        ['de', 'Server aktuell', 'neu', 'Erweiterungs-ID'],
        ['en', 'server is up to date', 'reload', 'extension ID'],
    ] as const)('propose une mise à jour plutôt qu’une déclaration d’installation en %s', (lang, serverAction, reloadAction, obsoleteAction) => {
        const message = errorMessage(lang, { kind: 'forbidden', status: 403, code: 'origin_not_allowed' });
        expect(message).toContain(serverAction);
        expect(message).toContain(reloadAction);
        expect(message).not.toContain(obsoleteAction);
    });
    it.each(['fr', 'de', 'en'] as const)('distingue les causes connues de 403 en %s et garde le repli générique', lang => {
        const generic = errorMessage(lang, { kind: 'forbidden', status: 403 });
        const codes = ['origin_not_allowed', 'mfa_required', 'permission_denied', 'feedback_not_allowed'];
        const messages = codes.map(code => errorMessage(lang, { kind: 'forbidden', status: 403, code }));
        expect(new Set(messages).size).toBe(4);
        expect(messages.every(message => message !== generic && message.includes('403'))).toBe(true);
        expect(errorMessage(lang, { kind: 'forbidden', status: 403, code: 'unknown_code' })).toBe(generic);
        expect(errorMessage(lang, { kind: 'forbidden', status: 403, code: '__proto__' })).toBe(generic);
    });
    it.each([[409, 'result_stale', 'stale'], [413, 'payload_too_large', 'payloadTooLarge'], [415, 'unsupported_media_type', 'mediaType'], [422, 'search_language_required', 'languageRequired'], [422, 'invalid_request', 'invalidRequest'], [426, 'client_upgrade_required', 'upgradeRequired']])('HTTP %i / %s : action explicite sans données sensibles', async (status, code, kind) => {
        const spies = (['log', 'warn', 'error', 'info', 'debug'] as const).map(m => vi.spyOn(console, m).mockImplementation(() => undefined));
        const options = callOptions(async () => json({ error: { code, message: 'synthetic-secret PRIVATE-TEXT private.example.test' } }, Number(status)));
        const error = await searchTranslations(sampleRequest(), options).catch(e => e);
        expect(error).toMatchObject({ kind, status, code });
        expect(JSON.stringify(error)).not.toContain('PRIVATE'); expect(error.message).not.toContain('synthetic-secret');
        expect(spies.every(spy => spy.mock.calls.length === 0)).toBe(true);
    });
    it('conserve proof et resultId dans le payload réel, sans recopier le contexte', async () => {
        const proof = 'signed.' + 'P'.repeat(1000); const id = 'cell2.WzEyMzQsImxhYmVsIiwiZnIiXQ';
        const fetchImpl = vi.fn(async (_url: string, _init: RequestInit) => json({ ok: true }));
        await sendFeedback({ resultId: id, feedbackProof: proof, text: 'PRIVATE-TEXT', pageUrl: 'https://private.example.test/id', key: 'K' }, callOptions(fetchImpl));
        expect(JSON.parse(String(fetchImpl.mock.calls[0]?.[1]?.body))).toEqual({ contractVersion: 2, resultId: id, feedbackProof: proof, verdict: 'correct' });
        const parsed = parseSearchResponse(response({ feedbackAllowed: true, results: [result({ feedbackProof: proof })] }));
        expect(parsed.results[0]?.feedbackProof).toBe(proof);
        expect(() => parseSearchResponse(response({ feedbackAllowed: true }))).toThrow(ApiError);
    });
});

describe('panneau v2', () => {
    it('distingue deux types DEVCONF partageant clé et attribut', () => {
        const overlay = new Overlay(document, { onClose: vi.fn(), onRetry: vi.fn(), onOpenSettings: vi.fn(), onFeedback: vi.fn(async () => true) }, { shadowMode: 'open' });
        const view = buildResultsView(parseSearchResponse(response({ matchState: 'multiple', exactCandidateCount: 2, results: [result({ type: 'mric', key: 'SAME' }), result({ id: 'cell2.other', termId: 2, type: 'server', key: 'SAME' })] })), sampleRequest(), settings);
        overlay.showLoading({ type: 'panel:loading', requestId: 'r1', request: sampleRequest(), lang: 'fr' });
        overlay.showResults({ type: 'panel:results', requestId: 'r1', request: sampleRequest(), lang: 'fr', ...view, manualSearchUrl: null, feedbackEnabled: false });
        const cards = document.querySelector('myab-translation-inspector')!.shadowRoot!.querySelectorAll('.card');
        expect(cards[0]?.textContent).toContain('Format DEVCONFmric'); expect(cards[1]?.textContent).toContain('Format DEVCONFserver');
        overlay.close();
    });
    it('distingue import, validation, référence inconnue et propositions, signale les previews copiés', async () => {
        const handlers = { onClose: vi.fn(), onRetry: vi.fn(), onOpenSettings: vi.fn(), onFeedback: vi.fn(async () => true) };
        const overlay = new Overlay(document, handlers, { shadowMode: 'open' });
        const request = sampleRequest(); const view = buildResultsView(parseSearchResponse(response({ results: [result({ sourceLanguage: '', previewTruncated: true, sourceText: 'Source…' })] })), request, settings);
        const writeText = vi.fn(async () => undefined); Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        overlay.showLoading({ type: 'panel:loading', requestId: 'r1', request, lang: 'fr' });
        overlay.showResults({ type: 'panel:results', requestId: 'r1', request, lang: 'fr', ...view, manualSearchUrl: null, feedbackEnabled: false, mock: true });
        const root = document.querySelector('myab-translation-inspector')!.shadowRoot!;
        expect(root.textContent).toContain('Texte trouvé (valeur importée)'); expect(root.textContent).toContain('Référence (langue inconnue)'); expect(root.textContent).toContain('Propositions en attente');
        expect(root.textContent).toContain('Non traduit'); expect(root.textContent).toContain('Mode test'); expect(root.textContent).not.toContain('allemand');
        const button = [...root.querySelectorAll('button')].find(b => b.textContent === 'Copier l’aperçu source (tronqué)')!;
        button.click(); await vi.waitFor(() => expect(button.textContent).toBe('Aperçu tronqué copié')); expect(writeText).toHaveBeenCalledWith('Source…');
        overlay.close(); Reflect.deleteProperty(navigator, 'clipboard');
    });
});
