import { describe, expect, it } from 'vitest';
import { excerptAround, hasMeaningfulText, normalizeText, searchKey, truncate, uniqueStrings } from '../src/shared/text';

describe('normalizeText', () => {
    it('supprime les espaces en début et fin', () => {
        expect(normalizeText('   Commande  ')).toBe('Commande');
    });

    it('réduit espaces multiples, tabulations et retours à la ligne à une seule espace', () => {
        expect(normalizeText('Bon\n  de\t\tcommande\r\n fournisseur')).toBe('Bon de commande fournisseur');
    });

    it('traite les espaces insécables comme des espaces', () => {
        expect(normalizeText('Total : 12')).toBe('Total : 12');
    });

    it('retire les caractères invisibles et les glyphes de polices d’icônes', () => {
        expect(normalizeText('Com​mande')).toBe('Commande');
        expect(normalizeText(' Enregistrer')).toBe('Enregistrer');
    });

    it('accepte null, undefined et la chaîne vide', () => {
        expect(normalizeText(null)).toBe('');
        expect(normalizeText(undefined)).toBe('');
        expect(normalizeText('   ')).toBe('');
    });

    it('compose les caractères Unicode (NFC)', () => {
        expect(normalizeText('é')).toBe('é');
    });
});

describe('hasMeaningfulText', () => {
    it('refuse les textes purement décoratifs', () => {
        for (const decorative of ['', '»', '×', '…', '---', '★']) {
            expect(hasMeaningfulText(decorative)).toBe(false);
        }
    });

    it('accepte lettres et chiffres, accentués ou non latins', () => {
        for (const text of ['Commande', 'é', '3', 'Größe', 'Ωμέγα']) {
            expect(hasMeaningfulText(text)).toBe(true);
        }
    });
});

describe('truncate', () => {
    it('laisse intact un texte assez court', () => {
        expect(truncate('court', 10)).toBe('court');
    });

    it('coupe à la limite de mot quand c’est raisonnable', () => {
        expect(truncate('one two three four', 14)).toBe('one two three…');
    });

    it('coupe brutalement un texte sans espace', () => {
        expect(truncate('abcdefghijkl', 5)).toBe('abcde…');
    });
});

describe('excerptAround', () => {
    it('centre l’extrait sur le texte cherché', () => {
        const full = `${'x'.repeat(100)} Commande ${'y'.repeat(100)}`;
        const excerpt = excerptAround(full, 'Commande', 60);
        expect(excerpt).toContain('Commande');
        expect(excerpt.length).toBeLessThanOrEqual(62);
        expect(excerpt.startsWith('…')).toBe(true);
        expect(excerpt.endsWith('…')).toBe(true);
    });

    it('retourne le texte entier s’il tient dans la limite', () => {
        expect(excerptAround('petit texte', 'texte', 50)).toBe('petit texte');
    });
});

describe('searchKey', () => {
    it('ignore casse, accents, espaces multiples et apostrophes typographiques', () => {
        expect(searchKey('  Échéance   Proche ')).toBe('echeance proche');
        expect(searchKey('L’ordre')).toBe(searchKey("L'ordre"));
    });
});

describe('uniqueStrings', () => {
    it('déduplique, ignore les vides, exclut et borne', () => {
        expect(uniqueStrings(['a', 'a', '', 'b', 'c', 'd'], 2, [])).toEqual(['a', 'b']);
        expect(uniqueStrings(['a', 'b'], 5, ['a'])).toEqual(['b']);
    });
});
