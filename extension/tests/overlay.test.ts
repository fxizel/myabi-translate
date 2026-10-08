import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Overlay, type OverlayHandlers } from '../src/content/overlay';
import type { PanelErrorMessage, PanelLoadingMessage, PanelResultsMessage } from '../src/shared/messages';
import { buildResultsView } from '../src/shared/viewModel';
import { parseSearchResponse } from '../src/api/translationApi';
import { normalizeSettings } from '../src/shared/settings';
import { sampleRequest, sampleResult, specResponse } from './helpers';

const settings = normalizeSettings({ apiUrl: 'https://translations.example.test/api/browser-extension' });
const request = sampleRequest();

let handlers: OverlayHandlers;
let overlay: Overlay;

function root(): ShadowRoot {
    const host = document.querySelector('myab-translation-inspector');
    if (!host?.shadowRoot) throw new Error('overlay absent');
    return host.shadowRoot;
}

function texts(selector: string): string[] {
    return Array.from(root().querySelectorAll(selector)).map((element) => element.textContent ?? '');
}

function loading(requestId = 'r1'): PanelLoadingMessage {
    return { type: 'panel:loading', requestId, request, lang: 'fr' };
}

function results(overrides: Partial<PanelResultsMessage> = {}): PanelResultsMessage {
    const view = buildResultsView(parseSearchResponse(specResponse), request, settings);
    return {
        type: 'panel:results',
        requestId: 'r1',
        request,
        lang: 'fr',
        state: view.state,
        results: view.results,
        manualSearchUrl: 'https://translations.example.test/terms?q=Commande',
        feedbackEnabled: false,
        ...overrides,
    };
}

function failed(error: PanelErrorMessage['error'], extra: Partial<PanelErrorMessage> = {}): PanelErrorMessage {
    return { type: 'panel:error', requestId: 'r1', request, lang: 'fr', error, manualSearchUrl: null, ...extra };
}

beforeEach(() => {
    handlers = {
        onClose: vi.fn(),
        onRetry: vi.fn(),
        onOpenSettings: vi.fn(),
        onFeedback: vi.fn(async () => true),
    };
    overlay = new Overlay(document, handlers, { shadowMode: 'open' });
});

afterEach(() => {
    overlay.close();
    document.body.innerHTML = '';
    Reflect.deleteProperty(navigator, 'clipboard');
});

describe('panneau — résultats', () => {
    it('affiche le texte détecté, la correspondance forte et les résultats classés par score', () => {
        overlay.showLoading(loading());
        overlay.showResults(results());

        expect(texts('.detected')).toEqual(['Commande']);
        expect(texts('.banner')).toEqual(['Correspondance forte']);
        expect(texts('.card .key')).toEqual(['PURCHASE_ORDER', 'ORDER']);
        expect(texts('.card .score')).toEqual(['96 %', '71 %']);
    });

    it('détaille chaque correspondance : langues, module, statut lisible, proposition', () => {
        overlay.showLoading(loading());
        overlay.showResults(results());

        const first = root().querySelector('.card')!;
        const rows = Array.from(first.querySelectorAll('dt, dd')).map((element) => element.textContent);
        expect(rows).toEqual(['DE', 'Bestellung', 'FR', 'Commande', 'Proposition', 'Commande fournisseur', 'Module', 'Purchasing']);
        expect(first.querySelector('.chip')?.textContent).toBe('Validé');
        expect(texts('.card:nth-child(2) .chip')).toEqual(['En révision']);
    });

    it('propose copier la clé, copier le texte allemand, ouvrir la traduction', () => {
        overlay.showLoading(loading());
        overlay.showResults(results());

        const first = root().querySelector('.card')!;
        expect(Array.from(first.querySelectorAll('button')).map((button) => button.textContent)).toEqual([
            'Copier la clé',
            'Copier le texte allemand',
        ]);
        const open = first.querySelector<HTMLAnchorElement>('a.button')!;
        expect(open.textContent).toBe('Ouvrir la traduction');
        expect(open.href).toBe('https://translations.example.test/translations/1245');
        expect(open.target).toBe('_blank');
        expect(open.rel).toContain('noopener');
    });

    it('n’affiche pas « Ouvrir la traduction » sans lien validé', () => {
        overlay.showLoading(loading());
        const message = results();
        message.results = message.results.map((result) => ({ ...result, openUrl: null }));
        overlay.showResults(message);
        expect(root().querySelector('a.button')).toBeNull();
    });

    it('plusieurs correspondances possibles', () => {
        overlay.showLoading(loading());
        overlay.showResults(results({ state: 'multiple' }));
        expect(texts('.banner')).toEqual(['Plusieurs correspondances possibles']);
    });

    it('aucun résultat : message et recherche manuelle', () => {
        overlay.showLoading(loading());
        overlay.showResults(results({ state: 'none', results: [] }));
        expect(texts('.banner')).toEqual(['Aucune traduction trouvée.']);
        expect(root().querySelector('.results')).toBeNull();
        const link = root().querySelector<HTMLAnchorElement>('.footer a')!;
        expect(link.textContent).toContain('Rechercher manuellement dans l’application');
        expect(link.href).toBe('https://translations.example.test/terms?q=Commande');
    });

    it('aucune correspondance exacte : message puis résultats approximatifs', () => {
        overlay.showLoading(loading());
        const approximate = [sampleResult({ key: 'ORDER_CONFIRMATION', score: 0.8, currentTranslation: 'Confirmation de commande' })];
        overlay.showResults(
            results({
                state: 'noExact',
                results: approximate.map((result) => ({ ...result, scoreLabel: '80 %', openUrl: null, exactMatch: false })),
            }),
        );
        expect(texts('.banner')).toEqual(['Aucune traduction exacte trouvée.']);
        expect(root().textContent).toContain('Résultats approximatifs proposés par l’API');
        expect(texts('.card .key')).toEqual(['ORDER_CONFIRMATION']);
    });

    it('affiche « Signaler cette correspondance comme correcte » seulement si activé, et l’appelle', async () => {
        overlay.showLoading(loading());
        overlay.showResults(results());
        expect(root().textContent).not.toContain('Signaler');

        overlay.showResults(results({ feedbackEnabled: true }));
        const button = Array.from(root().querySelectorAll('button')).find((candidate) =>
            candidate.textContent?.startsWith('Signaler'),
        )!;
        button.click();
        expect(handlers.onFeedback).toHaveBeenCalledWith(request, expect.objectContaining({ key: 'PURCHASE_ORDER' }));
        await vi.waitFor(() => expect(button.textContent).toBe('Merci, signalé'));
    });

    it('insère le contenu de l’API comme texte, jamais comme HTML', () => {
        overlay.showLoading(loading());
        const hostile = sampleResult({ key: '<img src=x onerror=alert(1)>', sourceText: '<script>alert(1)</script>' });
        overlay.showResults(
            results({ results: [{ ...hostile, scoreLabel: '96 %', openUrl: null, exactMatch: true }] }),
        );
        expect(root().querySelector('img, script')).toBeNull();
        expect(texts('.key')).toEqual(['<img src=x onerror=alert(1)>']);
    });

    it('ignore la réponse d’une recherche déjà remplacée', () => {
        overlay.showLoading(loading('r2'));
        overlay.showResults(results({ requestId: 'r1' }));
        expect(root().querySelector('.banner')).toBeNull();
        expect(root().textContent).toContain('Recherche en cours');
    });
});

describe('panneau — chargement et copie', () => {
    it('indique la recherche en cours', () => {
        overlay.showLoading(loading());
        expect(root().textContent).toContain('Recherche en cours…');
        expect(texts('.detected')).toEqual(['Commande']);
    });

    it('copie la clé et le texte source dans le presse-papiers', async () => {
        const writeText = vi.fn(async () => undefined);
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        overlay.showLoading(loading());
        overlay.showResults(results());

        const [copyKey, copySource] = Array.from(root().querySelectorAll('.card:first-child button'));
        (copyKey as HTMLButtonElement).click();
        await vi.waitFor(() => expect(writeText).toHaveBeenCalledWith('PURCHASE_ORDER'));
        await vi.waitFor(() => expect(copyKey?.textContent).toBe('Copié'));
        (copySource as HTMLButtonElement).click();
        await vi.waitFor(() => expect(writeText).toHaveBeenCalledWith('Bestellung'));
    });

    it('signale une copie impossible', async () => {
        Object.defineProperty(navigator, 'clipboard', {
            value: { writeText: vi.fn(async () => Promise.reject(new Error('denied'))) },
            configurable: true,
        });
        overlay.showLoading(loading());
        overlay.showResults(results());
        const copyKey = root().querySelector<HTMLButtonElement>('.card button')!;
        copyKey.click();
        await vi.waitFor(() => expect(copyKey.textContent).toBe('Copie impossible'));
    });
});

describe('panneau — erreurs compréhensibles', () => {
    const cases: Array<[PanelErrorMessage['error'], string]> = [
        [{ kind: 'unauthorized', status: 401 }, 'Authentification refusée (401)'],
        [{ kind: 'forbidden', status: 403 }, 'Accès refusé (403)'],
        [{ kind: 'notFound', status: 404 }, 'introuvable (404)'],
        [{ kind: 'rateLimited', status: 429, retryAfterSec: 30 }, 'Réessayez dans 30 s'],
        [{ kind: 'rateLimited', status: 429 }, 'Réessayez dans un instant'],
        [{ kind: 'server', status: 500 }, 'erreur (500)'],
        [{ kind: 'network' }, 'API inaccessible'],
        [{ kind: 'timeout' }, 'trop de temps'],
        [{ kind: 'invalidResponse' }, 'Réponse inattendue'],
        [{ kind: 'notConfigured' }, 'Extension non configurée'],
        [{ kind: 'domainNotAllowed' }, 'domaine n’est pas autorisé'],
        [{ kind: 'noText' }, 'Aucun texte exploitable'],
    ];

    it.each(cases)('%j', (error, expected) => {
        overlay.showLoading(loading());
        overlay.showError(failed(error));
        expect(texts('.banner.error')[0]).toContain(expected);
    });

    it('propose « Réessayer » pour une erreur transitoire et relance la même requête', () => {
        overlay.showLoading(loading());
        overlay.showError(failed({ kind: 'timeout' }));
        const retry = root().querySelector<HTMLButtonElement>('button.primary')!;
        expect(retry.textContent).toBe('Réessayer');
        retry.click();
        expect(handlers.onRetry).toHaveBeenCalledWith(request);
    });

    it('propose « Ouvrir les paramètres » pour un token refusé', () => {
        overlay.showLoading(loading());
        overlay.showError(failed({ kind: 'unauthorized', status: 401 }));
        const settingsButton = root().querySelector<HTMLButtonElement>('button.primary')!;
        expect(settingsButton.textContent).toBe('Ouvrir les paramètres');
        settingsButton.click();
        expect(handlers.onOpenSettings).toHaveBeenCalled();
        expect(root().textContent).not.toContain('Réessayer');
    });

    it('une erreur locale (aucun texte) s’affiche même sans recherche en cours', () => {
        overlay.showError(failed({ kind: 'noText' }, { requestId: 'local-1', request: undefined }));
        expect(texts('.banner.error')[0]).toContain('Aucun texte exploitable');
    });

    it('traduit l’interface en allemand', () => {
        overlay.showLoading({ ...loading(), lang: 'de' });
        overlay.showResults(results({ lang: 'de' }));
        expect(texts('.banner')).toEqual(['Starke Übereinstimmung']);
        expect(texts('.detected')).toEqual(['Commande']);
        const labels = Array.from(root().querySelectorAll('.card:first-child button')).map((button) => button.textContent);
        expect(labels).toEqual(['Schlüssel kopieren', 'Text kopieren (Deutsch)']);
    });
});

describe('panneau — fermeture et isolation', () => {
    it('le bouton fermer prévient l’inspecteur', () => {
        overlay.showLoading(loading());
        root().querySelector<HTMLButtonElement>('.header button')!.click();
        expect(handlers.onClose).toHaveBeenCalled();
    });

    it('close() retire entièrement l’overlay de la page', () => {
        overlay.showLoading(loading());
        overlay.highlight(document.body);
        expect(overlay.isActive()).toBe(true);
        overlay.close();
        expect(overlay.isActive()).toBe(false);
        expect(document.querySelector('myab-translation-inspector')).toBeNull();
    });

    it('les clics dans l’overlay n’atteignent pas les écouteurs de la page', () => {
        const pageListener = vi.fn();
        document.addEventListener('click', pageListener);
        overlay.showLoading(loading());
        root().querySelector<HTMLButtonElement>('.header button')!.click();
        document.removeEventListener('click', pageListener);
        expect(pageListener).not.toHaveBeenCalled();
    });

    it('reconnaît ses propres événements', () => {
        overlay.showLoading(loading());
        const host = document.querySelector('myab-translation-inspector')!;
        expect(overlay.isOverlayEvent([document.body, host])).toBe(true);
        expect(overlay.isOverlayEvent([document.body])).toBe(false);
    });

    it('surligne l’élément sélectionné et le retire', () => {
        document.body.innerHTML = '<p id="p">Texte</p>';
        overlay.highlight(document.getElementById('p')!);
        expect(root().querySelector('.highlight')).not.toBeNull();
        overlay.clearHighlight();
        expect(root().querySelector('.highlight')).toBeNull();
    });
});
