import { describe, expect, it } from 'vitest';
import { apiEndpoint, buildManualSearchUrl, safeLinkUrl, sanitizeUrl, validateHttpUrl } from '../src/shared/url';

describe('sanitizeUrl', () => {
    const raw = 'https://user:secret@myab.example.test/orders/1?id=42&x=y#/page?token=1';

    it('retire identifiants, query-string et paramètres de la route en #', () => {
        expect(sanitizeUrl(raw, 'noQuery')).toBe('https://myab.example.test/orders/1#/page');
    });

    it('mode complet : conserve la query mais jamais les identifiants', () => {
        expect(sanitizeUrl(raw, 'full')).toBe('https://myab.example.test/orders/1?id=42&x=y#/page?token=1');
    });

    it('mode domaine seulement', () => {
        expect(sanitizeUrl(raw, 'origin')).toBe('https://myab.example.test');
    });

    it('refuse les schémas autres que http(s) et les valeurs invalides', () => {
        expect(sanitizeUrl('javascript:alert(1)', 'full')).toBe('');
        expect(sanitizeUrl('pas une url', 'full')).toBe('');
    });
});

describe('validateHttpUrl', () => {
    it('normalise et retire le slash final', () => {
        expect(validateHttpUrl(' https://translations.example.test/api/browser-extension/ ')).toEqual({
            ok: true,
            url: 'https://translations.example.test/api/browser-extension',
            origin: 'https://translations.example.test',
            matchPattern: 'https://translations.example.test/*',
        });
    });

    it('exige HTTPS, sauf pour localhost', () => {
        expect(validateHttpUrl('http://translations.example.test/api')).toEqual({ ok: false, reason: 'insecure' });
        expect(validateHttpUrl('http://localhost:8000/api')).toMatchObject({
            ok: true,
            url: 'http://localhost:8000/api',
            matchPattern: 'http://localhost/*',
        });
    });

    it('refuse vide, invalide, identifiants intégrés et autres schémas', () => {
        expect(validateHttpUrl('')).toEqual({ ok: false, reason: 'empty' });
        expect(validateHttpUrl('notaurl')).toEqual({ ok: false, reason: 'invalid' });
        expect(validateHttpUrl('https://user:pw@x.example.test')).toEqual({ ok: false, reason: 'invalid' });
        expect(validateHttpUrl('ftp://x.example.test')).toEqual({ ok: false, reason: 'invalid' });
    });
});

describe('apiEndpoint', () => {
    it('assemble sans double slash', () => {
        expect(apiEndpoint('https://t.example.test/api/', 'search')).toBe('https://t.example.test/api/search');
    });
});

describe('safeLinkUrl', () => {
    const origins = ['https://translations.example.test'];

    it('autorise l’origine attendue', () => {
        expect(safeLinkUrl('https://translations.example.test/translations/1245', origins)).toBe(
            'https://translations.example.test/translations/1245',
        );
    });

    it('refuse une autre origine, javascript: et http hors localhost', () => {
        expect(safeLinkUrl('https://evil.example.com/x', origins)).toBeNull();
        expect(safeLinkUrl('javascript:alert(1)', origins)).toBeNull();
        expect(safeLinkUrl('http://translations.example.test/x', ['http://translations.example.test'])).toBeNull();
        expect(safeLinkUrl('/relatif', origins)).toBeNull();
    });
});

describe('buildManualSearchUrl', () => {
    it('remplace {appUrl} et encode {query}', () => {
        expect(buildManualSearchUrl('{appUrl}/terms?q={query}', 'https://t.example.test/', 'Commande & co')).toBe(
            'https://t.example.test/terms?q=Commande%20%26%20co',
        );
    });
});
