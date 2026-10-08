import { afterEach, describe, expect, it } from 'vitest';
import { getVisibleText, resolveText, type ResolvedText } from '../src/content/textExtractor';

function page(html: string): void {
    document.body.innerHTML = `<div id="root">${html}</div>`;
}

function resolved(id: string, options = {}): ResolvedText {
    const element = document.getElementById(id);
    if (!element) throw new Error(`#${id} introuvable`);
    const result = resolveText(element, options);
    if (!result.ok) throw new Error(`échec inattendu pour #${id} : ${result.reason}`);
    return result.value;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('getVisibleText', () => {
    it('inclut les sous-éléments et normalise les espaces et retours à la ligne', () => {
        page('<p id="p">  Bon de\n   <em>commande</em>\t fournisseur </p>');
        expect(getVisibleText(document.getElementById('p')!)).toBe('Bon de commande fournisseur');
    });

    it('sépare les blocs par une espace mais pas les éléments inline', () => {
        page('<div id="d"><div>Commande</div><div>Statut</div></div><p id="p">Sa<b>lut</b></p>');
        expect(getVisibleText(document.getElementById('d')!)).toBe('Commande Statut');
        expect(getVisibleText(document.getElementById('p')!)).toBe('Salut');
    });

    it('exclut texte masqué, aria-hidden, svg, scripts et texte réservé aux lecteurs d’écran', () => {
        page(`<p id="p">Total
            <span hidden>caché</span>
            <span style="display:none">invisible</span>
            <span aria-hidden="true">★</span>
            <span class="sr-only">lecteur</span>
            <svg><title>icône</title></svg>
            <script>var x = 1;</script>
        </p>`);
        expect(getVisibleText(document.getElementById('p')!)).toBe('Total');
    });

    it('ne lit jamais les valeurs saisies ni les options d’une liste', () => {
        page(`<div id="d">Fournisseur
            <input value="secret">
            <textarea>note privée</textarea>
            <select><option>Donnée métier</option></select>
        </div>`);
        expect(getVisibleText(document.getElementById('d')!)).toBe('Fournisseur');
    });

    it('traverse un Shadow DOM ouvert', () => {
        page('<div id="host"></div>');
        document.getElementById('host')!.attachShadow({ mode: 'open' }).innerHTML = '<span>Libellé shadow</span>';
        expect(getVisibleText(document.getElementById('host')!)).toBe('Libellé shadow');
    });

    it('applique le sélecteur « éléments à ignorer »', () => {
        page('<p id="p">Commande <span class="user">Jean Dupont</span></p>');
        expect(getVisibleText(document.getElementById('p')!, { ignoreSelector: '.user' })).toBe('Commande');
    });
});

describe('resolveText — texte simple, boutons, liens', () => {
    it('texte simple', () => {
        page('<p id="p">Commande</p>');
        expect(resolved('p')).toMatchObject({ text: 'Commande', source: 'content' });
    });

    it('un élément inline remonte à la phrase entière et garde sa propre lecture en alternative', () => {
        page('<p id="p">Une phrase avec un <strong id="s">mot en gras</strong> au milieu</p>');
        const value = resolved('s');
        expect(value.text).toBe('Une phrase avec un mot en gras au milieu');
        expect(value.container.id).toBe('p');
        expect(value.target.id).toBe('s');
        expect(value.alternatives).toContain('mot en gras');
    });

    it('bouton avec sous-élément : le texte du bouton entier', () => {
        page('<button id="b">Enregistrer <b id="n">maintenant</b></button>');
        const value = resolved('n');
        expect(value.text).toBe('Enregistrer maintenant');
        expect(value.container.id).toBe('b');
    });

    it('lien contenant une icône masquée et du texte', () => {
        page('<a id="a" href="#"><span class="material-icons" aria-hidden="true">delete</span> Supprimer</a>');
        expect(resolved('a').text).toBe('Supprimer');
    });

    it('bouton à icône seule : retombe sur aria-label', () => {
        page('<button id="b" aria-label="Supprimer la ligne"><svg id="i"></svg></button>');
        const value = resolved('i');
        expect(value).toMatchObject({ text: 'Supprimer la ligne', source: 'attribute', attribute: 'aria-label' });
        expect(value.container.id).toBe('b');
    });

    it('icône sans texte à côté d’un libellé : prend le libellé voisin', () => {
        page('<div id="row"><svg id="i"></svg><span>Imprimer</span></div>');
        expect(resolved('i').text).toBe('Imprimer');
    });

    it('image : utilise alt', () => {
        page('<img id="logo" src="x.png" alt="Logo de l’entreprise">');
        expect(resolved('logo')).toMatchObject({ text: 'Logo de l’entreprise', attribute: 'alt' });
    });
});

describe('resolveText — formulaires', () => {
    it('champ : le libellé du <label for>, jamais la valeur saisie', () => {
        page('<label for="f">Fournisseur</label><input id="f" value="Valeur confidentielle">');
        const value = resolved('f');
        expect(value).toMatchObject({ text: 'Fournisseur', source: 'label' });
        expect(JSON.stringify(value.text)).not.toContain('confidentielle');
    });

    it('champ dans un <label> englobant', () => {
        page('<label>Date de livraison <input id="d" type="date" value="2026-01-01"></label>');
        expect(resolved('d').text).toBe('Date de livraison');
    });

    it('clic sur le libellé lui-même', () => {
        page('<label id="l" for="f">Fournisseur</label><input id="f">');
        expect(resolved('l').text).toBe('Fournisseur');
    });

    it('aria-labelledby, puis aria-label, puis placeholder', () => {
        page(`<span id="t">Référence interne</span>
              <input id="a" aria-labelledby="t">
              <input id="b" aria-label="Recherche d'article">
              <input id="c" placeholder="Rechercher un article">`);
        expect(resolved('a').text).toBe('Référence interne');
        expect(resolved('b').text).toBe('Recherche d\'article');
        expect(resolved('c')).toMatchObject({ text: 'Rechercher un article', attribute: 'placeholder' });
    });

    it('bouton <input type="button"> : sa valeur est son libellé', () => {
        page('<input id="v" type="button" value="Valider">');
        expect(resolved('v')).toMatchObject({ text: 'Valider', source: 'value' });
    });

    it('champ caché ou sans libellé : rien d’exploitable', () => {
        page('<input id="h" type="hidden" value="x"><input id="n" type="text" value="x">');
        expect(resolveText(document.getElementById('h')!)).toEqual({ ok: false, reason: 'ignored' });
        expect(resolveText(document.getElementById('n')!)).toEqual({ ok: false, reason: 'empty' });
    });
});

describe('resolveText — tableaux et éléments imbriqués', () => {
    it('cellule de tableau', () => {
        page('<table><tbody><tr><td id="c">Muster AG</td></tr></tbody></table>');
        expect(resolved('c').text).toBe('Muster AG');
    });

    it('libellés indépendants dans une même cellule : on reste sur l’élément cliqué', () => {
        page('<table><tbody><tr><td id="td"><span id="a">Commande</span><span id="b" class="badge">3</span></td></tr></tbody></table>');
        const value = resolved('a');
        expect(value.text).toBe('Commande');
        expect(value.container.id).toBe('a');
    });

    it('éléments profondément imbriqués', () => {
        page('<div><div><span id="s">Commande <em>fournisseur</em></span></div></div>');
        expect(resolved('s').text).toBe('Commande fournisseur');
    });

    it('lien avec libellé et badge : le lien entier', () => {
        page('<ul><li><a id="a" href="#"><span id="l">Livraisons</span> <span class="badge">12</span></a></li></ul>');
        const value = resolved('l');
        expect(value.text).toBe('Livraisons 12');
        expect(value.container.id).toBe('a');
        expect(value.alternatives).toContain('Livraisons');
    });

    it('cellule ARIA (role="gridcell")', () => {
        page('<div role="row"><div id="g" role="gridcell"><span>Validé</span></div></div>');
        expect(resolved('g').text).toBe('Validé');
    });

    it('Shadow DOM ouvert : l’élément cliqué dans la racine fantôme', () => {
        page('<div id="host"></div>');
        const shadow = document.getElementById('host')!.attachShadow({ mode: 'open' });
        shadow.innerHTML = '<button id="sb">Bouton shadow</button>';
        const result = resolveText(shadow.getElementById('sb')!);
        expect(result.ok && result.value.text).toBe('Bouton shadow');
    });
});

describe('resolveText — textes à écarter', () => {
    it('élément vide', () => {
        page('<span id="e"></span>');
        expect(resolveText(document.getElementById('e')!)).toEqual({ ok: false, reason: 'empty' });
    });

    it('texte purement décoratif', () => {
        page('<span id="d">»</span>');
        expect(resolveText(document.getElementById('d')!)).toEqual({ ok: false, reason: 'decorative' });
    });

    it('bloc trop grand (page, section) plutôt qu’un libellé', () => {
        page(`<div id="big"><p>${'a '.repeat(250)}</p><p>${'b '.repeat(250)}</p></div>`);
        expect(resolveText(document.getElementById('big')!)).toEqual({ ok: false, reason: 'tooLong' });
    });

    it('un long paragraphe unique est tronqué', () => {
        page(`<p id="p">${'mot '.repeat(300)}</p>`);
        const value = resolved('p');
        expect(value.text.length).toBeLessThanOrEqual(501);
        expect(value.text.endsWith('…')).toBe(true);
    });

    it('éléments ignorés par réglage ou appartenant à l’overlay', () => {
        page('<div class="skip"><span id="s">Nom utilisateur</span></div><div data-myab-ti-root=""><b id="o">Panneau</b></div>');
        expect(resolveText(document.getElementById('s')!, { ignoreSelector: '.skip' })).toEqual({ ok: false, reason: 'ignored' });
        expect(resolveText(document.getElementById('o')!)).toEqual({ ok: false, reason: 'ignored' });
    });

    it('texte répété : chaque occurrence se résout séparément', () => {
        page('<p id="a">Commande</p><button id="b">Commande</button><table><tr><td id="c">Commande</td></tr></table>');
        expect(['a', 'b', 'c'].map((id) => resolved(id).text)).toEqual(['Commande', 'Commande', 'Commande']);
    });
});
