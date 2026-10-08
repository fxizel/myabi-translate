import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Inspector, modifierMatches } from '../src/content/inspector';
import { fakeApi } from './helpers';

const baseSettings = {
    apiUrl: 'https://translations.example.test/api/browser-extension',
    allowedDomains: ['localhost'],
};

let inspector: Inspector | undefined;

async function start(stored: Record<string, unknown> = {}) {
    const fake = fakeApi({ settings: baseSettings, inspectorEnabled: true, ...stored });
    inspector = new Inspector(fake.api, document, { requireTrusted: false });
    await inspector.start();
    return fake;
}

function mouse(type: string, init: MouseEventInit = {}): MouseEvent {
    return new MouseEvent(type, { bubbles: true, cancelable: true, composed: true, button: 0, ...init });
}

function sentTypes(sent: unknown[]): string[] {
    return sent.map((message) => (message as { type: string }).type);
}

beforeEach(() => {
    document.body.innerHTML = `
        <main>
            <button id="save" type="button">Enregistrer</button>
            <a id="link" href="#detail">Voir le détail</a>
            <span id="deco">»</span>
        </main>`;
});

afterEach(() => {
    inspector?.stop();
    inspector = undefined;
    document.body.innerHTML = '';
});

describe('modifierMatches', () => {
    const event = (init: MouseEventInit) => new MouseEvent('click', init);

    it('Alt seul par défaut, sans Ctrl, Maj ni Meta', () => {
        expect(modifierMatches(event({ altKey: true }), 'alt')).toBe(true);
        expect(modifierMatches(event({}), 'alt')).toBe(false);
        expect(modifierMatches(event({ altKey: true, shiftKey: true }), 'alt')).toBe(false);
        expect(modifierMatches(event({ altKey: true, ctrlKey: true }), 'alt')).toBe(false);
        expect(modifierMatches(event({ altKey: true, metaKey: true }), 'alt')).toBe(false);
    });

    it('combinaisons configurables', () => {
        expect(modifierMatches(event({ altKey: true, shiftKey: true }), 'altShift')).toBe(true);
        expect(modifierMatches(event({ altKey: true }), 'altShift')).toBe(false);
        expect(modifierMatches(event({ altKey: true, ctrlKey: true }), 'altCtrl')).toBe(true);
    });
});

describe('Alt+clic en mode inspecteur', () => {
    it('neutralise le clic pour la page et lance la recherche avec le contexte', async () => {
        const { sent } = await start();
        const onPage = vi.fn();
        document.addEventListener('click', onPage);
        document.getElementById('save')!.addEventListener('click', onPage);

        const click = mouse('click', { altKey: true });
        document.getElementById('save')!.dispatchEvent(click);
        document.removeEventListener('click', onPage);

        expect(click.defaultPrevented).toBe(true);
        expect(onPage).not.toHaveBeenCalled();
        expect(sent).toHaveLength(1);
        expect(sent[0]).toMatchObject({
            type: 'search',
            request: { text: 'Enregistrer', elementTag: 'button', uiLanguage: 'fr', maxResults: 5 },
        });
        expect(document.querySelector('myab-translation-inspector')).not.toBeNull();
    });

    it('neutralise aussi mousedown / pointerdown / mouseup pour éviter les effets de bord', async () => {
        const { sent } = await start();
        const onPage = vi.fn();
        for (const type of ['pointerdown', 'mousedown', 'mouseup']) {
            document.addEventListener(type, onPage);
            const event = mouse(type, { altKey: true });
            document.getElementById('link')!.dispatchEvent(event);
            document.removeEventListener(type, onPage);
            expect(event.defaultPrevented).toBe(true);
        }
        expect(onPage).not.toHaveBeenCalled();
        expect(sent).toHaveLength(0); // seule la phase « click » lance une recherche
    });

    it('un clic normal n’est jamais intercepté', async () => {
        const { sent } = await start();
        const onPage = vi.fn();
        document.addEventListener('click', onPage);
        const click = mouse('click');
        document.getElementById('save')!.dispatchEvent(click);
        document.removeEventListener('click', onPage);

        expect(click.defaultPrevented).toBe(false);
        expect(onPage).toHaveBeenCalledTimes(1);
        expect(sent).toHaveLength(0);
    });

    it('ignore les clics autres que le bouton principal', async () => {
        const { sent } = await start();
        const click = mouse('click', { altKey: true, button: 1 });
        document.getElementById('save')!.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(false);
        expect(sent).toHaveLength(0);
    });

    it('mode désactivé : MyAB fonctionne normalement', async () => {
        const { sent } = await start({ inspectorEnabled: false });
        const click = mouse('click', { altKey: true });
        document.getElementById('save')!.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(false);
        expect(sent).toHaveLength(0);
    });

    it('domaine non configuré : aucune interception', async () => {
        const { sent } = await start({ settings: { ...baseSettings, allowedDomains: ['myab.example.test'] } });
        const click = mouse('click', { altKey: true });
        document.getElementById('save')!.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(false);
        expect(sent).toHaveLength(0);
    });

    it('respecte la touche configurée', async () => {
        const { sent } = await start({ settings: { ...baseSettings, activationModifier: 'altShift' } });
        const plain = mouse('click', { altKey: true });
        document.getElementById('save')!.dispatchEvent(plain);
        expect(plain.defaultPrevented).toBe(false);

        const combo = mouse('click', { altKey: true, shiftKey: true });
        document.getElementById('save')!.dispatchEvent(combo);
        expect(combo.defaultPrevented).toBe(true);
        expect(sentTypes(sent)).toEqual(['search']);
    });

    it('texte décoratif : prévient l’utilisateur sans lancer de recherche', async () => {
        const { sent } = await start();
        const click = mouse('click', { altKey: true });
        document.getElementById('deco')!.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
        expect(sentTypes(sent)).toEqual(['noText']);
    });

    it('ignore la liste d’éléments configurée', async () => {
        const { sent } = await start({ settings: { ...baseSettings, selectors: { ignore: '#save' } } });
        document.getElementById('save')!.dispatchEvent(mouse('click', { altKey: true }));
        expect(sentTypes(sent)).toEqual(['noText']);
    });

    it('identifie l’élément cliqué dans un Shadow DOM ouvert', async () => {
        document.body.innerHTML = '<div id="host"></div>';
        const shadow = document.getElementById('host')!.attachShadow({ mode: 'open' });
        shadow.innerHTML = '<button id="sb">Bouton shadow</button>';
        const { sent } = await start();

        shadow.getElementById('sb')!.dispatchEvent(mouse('click', { altKey: true }));
        expect(sent[0]).toMatchObject({ type: 'search', request: { text: 'Bouton shadow', elementTag: 'button' } });
    });
});

describe('Échap et retour à la normale', () => {
    it('Échap ferme l’inspecteur, prévient les autres frames et laisse la page tranquille', async () => {
        const { sent } = await start();
        document.getElementById('save')!.dispatchEvent(mouse('click', { altKey: true }));
        expect(document.querySelector('myab-translation-inspector')).not.toBeNull();

        const onPage = vi.fn();
        document.addEventListener('keydown', onPage);
        const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        document.body.dispatchEvent(escape);
        document.removeEventListener('keydown', onPage);

        expect(escape.defaultPrevented).toBe(true);
        expect(onPage).not.toHaveBeenCalled();
        expect(document.querySelector('myab-translation-inspector')).toBeNull();
        expect(sentTypes(sent)).toEqual(['search', 'closeInspector']);
    });

    it('Échap sans inspecteur ouvert n’est pas intercepté (la page garde son comportement)', async () => {
        await start();
        const escape = new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true });
        document.body.dispatchEvent(escape);
        expect(escape.defaultPrevented).toBe(false);
    });

    it('stop() retire les écouteurs : le clic Alt redevient normal', async () => {
        await start();
        inspector!.stop();
        const click = mouse('click', { altKey: true });
        document.getElementById('save')!.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(false);
    });
});
