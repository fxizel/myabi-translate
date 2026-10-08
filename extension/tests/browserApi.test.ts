import { describe, expect, it } from 'vitest';
import { isFromExtensionPage } from '../src/shared/browserApi';

const base = 'chrome-extension://abcdefghijklmnop/';

describe('isFromExtensionPage', () => {
    it('reconnaît la page Paramètres, même ouverte dans un onglet (sender.tab présent)', () => {
        expect(isFromExtensionPage({ id: 'x', url: `${base}options.html`, tab: { id: 7 } }, base)).toBe(true);
    });

    it('reconnaît la popup (pas d’onglet)', () => {
        expect(isFromExtensionPage({ id: 'x', url: `${base}popup.html` }, base)).toBe(true);
    });

    it('refuse un content script : son URL est celle de la page web', () => {
        expect(isFromExtensionPage({ id: 'x', url: 'https://myab.example.test/orders', tab: { id: 7 } }, base)).toBe(false);
        expect(isFromExtensionPage({ id: 'x', url: `https://evil.example/${base}`, tab: { id: 7 } }, base)).toBe(false);
    });

    it('refuse sans URL ou sans base connue', () => {
        expect(isFromExtensionPage({ id: 'x' }, base)).toBe(false);
        expect(isFromExtensionPage({ id: 'x', url: `${base}options.html` }, '')).toBe(false);
    });
});
