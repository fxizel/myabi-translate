import { describe, expect, it } from 'vitest';
import { sanitizeSearchRequest } from '../src/shared/messages';
import { sampleRequest } from './helpers';

describe('sanitizeSearchRequest', () => {
    it('conserve une requête valide', () => {
        const request = sampleRequest({ elementClasses: ['a', 'b'], dataAttributes: { 'data-key': 'X' } });
        expect(sanitizeSearchRequest(request)).toMatchObject({
            text: 'Commande',
            elementTag: 'span',
            elementClasses: ['a', 'b'],
            dataAttributes: { 'data-key': 'X' },
            maxResults: 5,
            uiLanguage: 'fr',
        });
    });

    it('refuse ce qui n’est pas une requête ou n’a pas de texte', () => {
        expect(sanitizeSearchRequest(null)).toBeNull();
        expect(sanitizeSearchRequest('texte')).toBeNull();
        expect(sanitizeSearchRequest({})).toBeNull();
        expect(sanitizeSearchRequest({ text: '   ' })).toBeNull();
        expect(sanitizeSearchRequest({ text: 42 })).toBeNull();
    });

    it('borne les tailles et ignore les types inattendus', () => {
        const request = sanitizeSearchRequest({
            text: 'x'.repeat(2000),
            pageTitle: 'y'.repeat(2000),
            elementClasses: Array.from({ length: 50 }, (_, index) => `c${index}`).concat([42 as unknown as string]),
            siblingTexts: Array.from({ length: 50 }, (_, index) => `s${index}`),
            maxResults: 9999,
            uiLanguage: 'klingon',
            ariaLabel: { evil: true },
        });
        expect(request).not.toBeNull();
        expect(request!.text.length).toBeLessThanOrEqual(501);
        expect(request!.pageTitle.length).toBeLessThanOrEqual(201);
        expect(request!.elementClasses).toHaveLength(20);
        expect(request!.siblingTexts).toHaveLength(6);
        expect(request!.maxResults).toBe(20);
        expect(request!.uiLanguage).toBe('fr');
        expect(request!.ariaLabel).toBe('');
    });

    it('ne laisse passer que des attributs data-* et aria-*', () => {
        const request = sanitizeSearchRequest({
            text: 'Commande',
            dataAttributes: { 'data-id': '1', onclick: 'alert(1)', 'data-a b': 'x', style: 'color:red' },
            ariaAttributes: { 'aria-describedby': 'tip', class: 'x' },
        });
        expect(request?.dataAttributes).toEqual({ 'data-id': '1' });
        expect(request?.ariaAttributes).toEqual({ 'aria-describedby': 'tip' });
    });

    it('ne transmet aucun champ inconnu (par exemple du HTML)', () => {
        const request = sanitizeSearchRequest({ text: 'Commande', html: '<div>page entière</div>', outerHTML: '<p/>' });
        expect(JSON.stringify(request)).not.toContain('page entière');
        expect(request).not.toHaveProperty('html');
    });
});
