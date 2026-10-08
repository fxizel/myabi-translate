import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { buildContext, buildSearchRequest, pageLanguage, type ContextSettings } from '../src/content/contextExtractor';
import { resolveText } from '../src/content/textExtractor';

const settings: ContextSettings = {
    collectContext: true,
    urlMode: 'noQuery',
    maxResults: 5,
    selectors: { breadcrumb: '', dialog: '', sectionTitle: '', ignore: '' },
};

function context(id: string, overrides: Partial<ContextSettings> = {}) {
    const element = document.getElementById(id);
    if (!element) throw new Error(`#${id} introuvable`);
    const result = resolveText(element);
    if (!result.ok) throw new Error(`texte non résolu pour #${id} : ${result.reason}`);
    return buildContext(result.value, { ...settings, ...overrides });
}

beforeEach(() => {
    document.documentElement.lang = 'fr-CH';
    document.title = '  Détail   de la commande ';
    window.history.pushState({}, '', '/orders/12?id=42&token=abc#/detail?x=1');
});

afterEach(() => {
    document.body.innerHTML = '';
    document.documentElement.removeAttribute('lang');
});

describe('buildContext — champs de base', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <main>
                <section>
                    <h2>Informations générales</h2>
                    <div class="row"><label for="supplier">Fournisseur</label><input id="supplier" name="supplier" type="text" value="PRIVATE-VALUE"></div>
                    <div class="row"><span id="t" class="lbl strong" data-i18n-key="PURCHASE_ORDER" data-extra="x" title="Info" aria-describedby="tip">Commande</span> <span>Statut</span></div>
                </section>
            </main>`;
    });

    it('collecte tag, id, classes, attributs data-*/aria-*, titre, langue, page', () => {
        const payload = context('t');
        expect(payload).toMatchObject({
            text: 'Commande',
            pageTitle: 'Détail de la commande',
            elementTag: 'span',
            elementId: 't',
            elementClasses: ['lbl', 'strong'],
            ariaLabel: '',
            title: 'Info',
            dataAttributes: { 'data-i18n-key': 'PURCHASE_ORDER', 'data-extra': 'x' },
            ariaAttributes: { 'aria-describedby': 'tip' },
            lang: 'fr',
        });
    });

    it('retire la query-string et les paramètres de la route de l’URL', () => {
        const payload = context('t');
        expect(payload.pageUrl).toBe('http://localhost:3000/orders/12#/detail');
        expect(payload.pageUrl).not.toContain('token');
    });

    it('trouve le titre de section, le texte du parent et les frères', () => {
        const payload = context('t');
        expect(payload.sectionTitle).toBe('Informations générales');
        expect(payload.parentText).toBe('Commande Statut');
        expect(payload.siblingTexts).toEqual(['Statut']);
        expect(payload.surroundingText).toContain('Commande');
    });

    it('champ de saisie : libellé, name et type, jamais la valeur saisie', () => {
        const payload = context('supplier');
        expect(payload).toMatchObject({ text: 'Fournisseur', formLabel: 'Fournisseur', elementName: 'supplier', inputType: 'text' });
        expect(JSON.stringify(payload)).not.toContain('PRIVATE-VALUE');
    });

    it('ne contient jamais de HTML', () => {
        const json = JSON.stringify(context('t'));
        expect(json).not.toMatch(/<\/?(div|span|section|main|input)/);
    });
});

describe('buildContext — fil d’Ariane, modale, tableau', () => {
    it('fil d’Ariane', () => {
        document.body.innerHTML = `
            <nav class="breadcrumb" aria-label="Fil d'Ariane"><ol><li>Accueil</li><li>Achats</li><li>Commandes</li></ol></nav>
            <p id="p">Texte</p>`;
        expect(context('p').breadcrumbs).toEqual(['Accueil', 'Achats', 'Commandes']);
    });

    it('sélecteur de fil d’Ariane configurable', () => {
        document.body.innerHTML = `
            <div class="trail"><a href="#">Un</a><a href="#">Deux</a></div>
            <p id="p">Texte</p>`;
        const payload = context('p', { selectors: { ...settings.selectors, breadcrumb: '.trail' } });
        expect(payload.breadcrumbs).toEqual(['Un', 'Deux']);
    });

    it('modale : titre via aria-labelledby, y compris pour un élément de la boîte', () => {
        document.body.innerHTML = `
            <dialog open aria-labelledby="dt"><h3 id="dt">Confirmer la commande</h3><button id="ok">Valider</button></dialog>`;
        expect(context('ok')).toMatchObject({ inDialog: true, dialogTitle: 'Confirmer la commande' });
    });

    it('modale ARIA et titre de modale détecté par classe', () => {
        document.body.innerHTML = `
            <div role="dialog" aria-modal="true"><div class="modal-title">Supprimer l’article</div><button id="b">Supprimer</button></div>`;
        expect(context('b')).toMatchObject({ inDialog: true, dialogTitle: 'Supprimer l’article' });
    });

    it('modale personnalisée par sélecteur', () => {
        document.body.innerHTML = `<div class="my-modal"><h4>Ma modale</h4><button id="b">Fermer</button></div>`;
        const payload = context('b', { selectors: { ...settings.selectors, dialog: '.my-modal' } });
        expect(payload).toMatchObject({ inDialog: true, dialogTitle: 'Ma modale' });
    });

    it('pas de modale : aucun champ de modale', () => {
        document.body.innerHTML = '<p id="p">Texte</p>';
        const payload = context('p');
        expect(payload.inDialog).toBeUndefined();
        expect(payload.dialogTitle).toBeUndefined();
    });

    it('cellule de tableau : en-tête de colonne, jamais le contenu de la ligne', () => {
        document.body.innerHTML = `
            <table>
                <thead><tr><th>Référence</th><th>Fournisseur</th></tr></thead>
                <tbody><tr><td>BC-2026-0412</td><td id="cell">Muster AG</td></tr></tbody>
            </table>`;
        const payload = context('cell');
        expect(payload.text).toBe('Muster AG');
        expect(payload.tableColumn).toBe('Fournisseur');
        expect(payload.parentText).toBeUndefined();
        expect(payload.siblingTexts).toBeUndefined();
        expect(payload.surroundingText).toBe('');
        expect(JSON.stringify(payload)).not.toContain('BC-2026-0412');
    });

    it('colonne via l’attribut headers', () => {
        document.body.innerHTML = `
            <table>
                <thead><tr><th id="h1">Statut</th></tr></thead>
                <tbody><tr><td id="cell" headers="h1">Validé</td></tr></tbody>
            </table>`;
        expect(context('cell').tableColumn).toBe('Statut');
    });
});

describe('buildContext — titre de section et région', () => {
    it('legend d’un fieldset', () => {
        document.body.innerHTML = '<fieldset><legend>Adresse de livraison</legend><div><span id="s">Rue</span><span>Ville</span></div></fieldset>';
        expect(context('s').sectionTitle).toBe('Adresse de livraison');
    });

    it('région étiquetée par aria-label', () => {
        document.body.innerHTML = '<section aria-label="Totaux"><div id="d"><span>Montant</span><span>Taxe</span></div></section>';
        expect(context('d').sectionTitle).toBe('Totaux');
    });

    it('sélecteur de titre de section configurable', () => {
        document.body.innerHTML = '<div class="panel-title">Mon panneau</div><div><p id="p">Texte</p></div>';
        const payload = context('p', { selectors: { ...settings.selectors, sectionTitle: '.panel-title' } });
        expect(payload.sectionTitle).toBe('Mon panneau');
    });
});

describe('buildContext — collecte désactivée et limites', () => {
    beforeEach(() => {
        document.body.innerHTML = '<section><h2>Titre</h2><p id="p" class="x" data-k="v">Commande</p><p>Voisin</p></section>';
    });

    it('collecte désactivée : texte, URL, titre et langue seulement', () => {
        const payload = context('p', { collectContext: false });
        expect(payload).toEqual({
            text: 'Commande',
            pageUrl: 'http://localhost:3000/orders/12#/detail',
            pageTitle: 'Détail de la commande',
            surroundingText: '',
            elementTag: '',
            elementId: '',
            elementClasses: [],
            ariaLabel: '',
            title: '',
            dataAttributes: {},
            lang: 'fr',
        });
    });

    it('mode URL « domaine seulement »', () => {
        expect(context('p', { urlMode: 'origin' }).pageUrl).toBe('http://localhost:3000');
    });

    it('borne le nombre et la taille des attributs data-*', () => {
        const attributes = Array.from({ length: 30 }, (_, index) => `data-a${index}="${'v'.repeat(300)}"`).join(' ');
        document.body.innerHTML = `<p id="p" ${attributes}>Commande</p>`;
        const { dataAttributes } = context('p');
        expect(Object.keys(dataAttributes)).toHaveLength(20);
        expect(Object.values(dataAttributes).every((value) => value.length <= 101)).toBe(true);
    });

    it('buildSearchRequest ajoute la limite de résultats et la langue d’interface', () => {
        const element = document.getElementById('p')!;
        const result = resolveText(element);
        if (!result.ok) throw new Error('inattendu');
        expect(buildSearchRequest(result.value, settings, 'de')).toMatchObject({
            text: 'Commande',
            maxResults: 5,
            uiLanguage: 'de',
        });
    });
});

describe('pageLanguage', () => {
    it('privilégie le lang le plus proche et ne garde que la langue principale', () => {
        document.body.innerHTML = '<div lang="de-CH"><p id="p">Text</p></div><p id="q">Texte</p>';
        expect(pageLanguage(document.getElementById('p')!)).toBe('de');
        expect(pageLanguage(document.getElementById('q')!)).toBe('fr');
    });
});
