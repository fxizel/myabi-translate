import { describe, expect, it } from 'vitest';
import {
    domainsToMatchPatterns,
    isHostAllowed,
    matchPatternsFor,
    parseDomainEntry,
    parseDomainList,
} from '../src/shared/domains';

describe('parseDomainEntry', () => {
    it('normalise un domaine saisi de plusieurs façons', () => {
        for (const input of ['myab.example.test', 'HTTPS://MyAB.Example.TEST/path?x#y', ' myab.example.test:8443 ']) {
            expect(parseDomainEntry(input)).toMatchObject({ ok: true, canonical: 'myab.example.test', wildcard: false });
        }
    });

    it('accepte un joker sur au moins deux niveaux', () => {
        expect(parseDomainEntry('*.example.test')).toMatchObject({ ok: true, canonical: '*.example.test', wildcard: true });
    });

    it('refuse les jokers trop larges ou mal placés', () => {
        expect(parseDomainEntry('*.test')).toEqual({ ok: false, reason: 'tooBroad' });
        expect(parseDomainEntry('*')).toEqual({ ok: false, reason: 'invalid' });
        expect(parseDomainEntry('my*.example.test')).toEqual({ ok: false, reason: 'invalid' });
        expect(parseDomainEntry('*.localhost')).toEqual({ ok: false, reason: 'invalid' });
    });

    it('exige HTTPS sauf pour localhost et 127.0.0.1', () => {
        expect(parseDomainEntry('http://myab.example.test')).toEqual({ ok: false, reason: 'insecure' });
        expect(parseDomainEntry('http://localhost:8080')).toMatchObject({ ok: true, canonical: 'localhost' });
        expect(parseDomainEntry('127.0.0.1')).toMatchObject({ ok: true, canonical: '127.0.0.1' });
    });

    it('refuse les entrées invalides', () => {
        expect(parseDomainEntry('')).toEqual({ ok: false, reason: 'empty' });
        for (const input of ['ftp://x.test', 'user@host.test', 'exa mple.test', '[::1]', 'a..b.test']) {
            expect(parseDomainEntry(input)).toEqual({ ok: false, reason: 'invalid' });
        }
    });
});

describe('parseDomainList', () => {
    it('sépare les entrées, fusionne les doublons et signale les erreurs', () => {
        const result = parseDomainList('a.test\n*.b.test, a.test; ;bad host');
        expect(result.domains).toEqual(['a.test', '*.b.test']);
        expect(result.errors).toEqual([{ entry: 'bad host', reason: 'invalid' }]);
    });
});

describe('motifs de correspondance', () => {
    it('produit des motifs HTTPS, ou HTTP + HTTPS pour localhost', () => {
        expect(matchPatternsFor('*.example.test')).toEqual(['https://*.example.test/*']);
        expect(matchPatternsFor('localhost')).toEqual(['http://localhost/*', 'https://localhost/*']);
        expect(matchPatternsFor('pas un domaine')).toEqual([]);
    });

    it('fusionne les motifs d’une liste', () => {
        expect(domainsToMatchPatterns(['a.test', 'a.test', '*.b.test'])).toEqual(['https://a.test/*', 'https://*.b.test/*']);
    });
});

describe('isHostAllowed', () => {
    it('autorise uniquement les domaines configurés', () => {
        const domains = ['myab.example.test', '*.test.example.test'];
        expect(isHostAllowed('myab.example.test', 'https:', domains)).toBe(true);
        expect(isHostAllowed('MYAB.example.test.', 'https:', domains)).toBe(true);
        expect(isHostAllowed('other.example.test', 'https:', domains)).toBe(false);
        expect(isHostAllowed('sub.myab.example.test', 'https:', domains)).toBe(false);
    });

    it('le joker couvre le domaine lui-même et ses sous-domaines, jamais un suffixe voisin', () => {
        const domains = ['*.example.test'];
        expect(isHostAllowed('example.test', 'https:', domains)).toBe(true);
        expect(isHostAllowed('a.b.example.test', 'https:', domains)).toBe(true);
        expect(isHostAllowed('evilexample.test', 'https:', domains)).toBe(false);
    });

    it('refuse HTTP hors localhost', () => {
        expect(isHostAllowed('myab.example.test', 'http:', ['myab.example.test'])).toBe(false);
        expect(isHostAllowed('localhost', 'http:', ['localhost'])).toBe(true);
    });

    it('refuse tout quand aucun domaine n’est configuré', () => {
        expect(isHostAllowed('myab.example.test', 'https:', [])).toBe(false);
    });
});
