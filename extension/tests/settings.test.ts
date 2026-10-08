import { describe, expect, it } from 'vitest';
import {
    DEFAULT_SETTINGS,
    clearToken,
    isPlausibleToken,
    loadSettings,
    loadToken,
    normalizeSettings,
    saveSettings,
    saveToken,
    tokenState,
} from '../src/shared/settings';
import { buildSettingsFromForm, requiredOriginPatterns, type FormInput } from '../src/options/formModel';
import { fakeApi } from './helpers';

describe('normalizeSettings', () => {
    it('retombe sur les valeurs par défaut pour toute entrée inutilisable', () => {
        for (const raw of [undefined, null, 42, 'texte', [], {}]) {
            expect(normalizeSettings(raw)).toEqual(DEFAULT_SETTINGS);
        }
    });

    it('rejette une API en HTTP (hors localhost) et normalise une API valide', () => {
        expect(normalizeSettings({ apiUrl: 'http://translations.example.test/api' }).apiUrl).toBe('');
        expect(normalizeSettings({ apiUrl: 'https://translations.example.test/api/browser-extension/' }).apiUrl).toBe(
            'https://translations.example.test/api/browser-extension',
        );
        expect(normalizeSettings({ apiUrl: 'http://localhost:8000/api' }).apiUrl).toBe('http://localhost:8000/api');
    });

    it('borne les nombres et ignore les énumérations inconnues', () => {
        const settings = normalizeSettings({
            maxResults: 99,
            timeoutMs: 5,
            urlMode: 'tout',
            activationModifier: 'meta',
            uiLanguage: 'it',
        });
        expect(settings.maxResults).toBe(20);
        expect(settings.timeoutMs).toBe(1000);
        expect(settings.urlMode).toBe('noQuery');
        expect(settings.activationModifier).toBe('alt');
        expect(settings.uiLanguage).toBe('auto');
    });

    it('canonise les domaines et écarte les invalides', () => {
        const settings = normalizeSettings({
            allowedDomains: ['HTTPS://MyAB.example.test/', '*.test', 'myab.example.test', 42, 'http://x.example.test'],
        });
        expect(settings.allowedDomains).toEqual(['myab.example.test']);
    });

    it('exige {query} dans le gabarit de recherche manuelle', () => {
        expect(normalizeSettings({ manualSearchUrlTemplate: '{appUrl}/search' }).manualSearchUrlTemplate).toBe(
            DEFAULT_SETTINGS.manualSearchUrlTemplate,
        );
        expect(normalizeSettings({ manualSearchUrlTemplate: '{appUrl}/s?term={query}' }).manualSearchUrlTemplate).toBe(
            '{appUrl}/s?term={query}',
        );
    });
});

describe('stockage des réglages et du token', () => {
    it('relit ce qui a été enregistré', async () => {
        const { api } = fakeApi();
        const settings = normalizeSettings({ apiUrl: 'https://t.example.test/api', allowedDomains: ['myab.example.test'] });
        await saveSettings(settings, api);
        expect(await loadSettings(api)).toEqual(settings);
    });

    it('conserve le token à un seul endroit à la fois', async () => {
        const { api, localArea, sessionArea } = fakeApi();
        expect(await tokenState(api)).toBe('none');

        await saveToken('abc123', 'local', api);
        expect(await tokenState(api)).toBe('local');
        expect(await loadToken(api)).toBe('abc123');
        expect(sessionArea.data).toEqual({});

        await saveToken('abc123', 'session', api);
        expect(await tokenState(api)).toBe('session');
        expect(Object.keys(localArea.data)).toEqual([]);

        await clearToken(api);
        expect(await tokenState(api)).toBe('none');
        expect(await loadToken(api)).toBe('');
    });

    it('ne range jamais le token dans les réglages', async () => {
        const { api, localArea } = fakeApi();
        await saveSettings(normalizeSettings({ apiUrl: 'https://t.example.test/api' }), api);
        await saveToken('super-secret-token', 'local', api);
        expect(JSON.stringify(localArea.data.settings)).not.toContain('super-secret-token');
    });
});

describe('isPlausibleToken', () => {
    it('refuse espaces, caractères de contrôle et valeurs vides ou démesurées', () => {
        expect(isPlausibleToken('1|abcDEF123')).toBe(true);
        expect(isPlausibleToken('')).toBe(false);
        expect(isPlausibleToken('deux mots')).toBe(false);
        expect(isPlausibleToken('abc\ndef')).toBe(false);
        expect(isPlausibleToken('x'.repeat(513))).toBe(false);
    });
});

describe('buildSettingsFromForm', () => {
    const valid: FormInput = {
        apiUrl: 'https://translations.example.test/api/browser-extension',
        appUrl: '',
        domains: 'myab.example.test\n*.test.example.test',
        token: '',
        tokenPersistence: 'local',
        uiLanguage: 'auto',
        activationModifier: 'alt',
        maxResults: '5',
        collectContext: true,
        urlMode: 'noQuery',
        timeoutSeconds: '10',
        feedbackEnabled: false,
        selectorBreadcrumb: '',
        selectorDialog: '',
        selectorSection: '',
        selectorIgnore: '',
        manualSearchUrlTemplate: '',
    };
    const accepts = () => true;

    it('accepte une configuration valide', () => {
        const { settings, errors } = buildSettingsFromForm(valid, 'fr', accepts);
        expect(errors).toEqual([]);
        expect(settings.allowedDomains).toEqual(['myab.example.test', '*.test.example.test']);
        expect(settings.timeoutMs).toBe(10_000);
    });

    it('signale en français une API non HTTPS, des domaines invalides et l’absence de domaine', () => {
        const { errors } = buildSettingsFromForm(
            { ...valid, apiUrl: 'http://translations.example.test/api', domains: '*.test\nhttp://x.example.test\nbad host' },
            'fr',
            accepts,
        );
        expect(errors).toContain('L’URL de l’API doit utiliser HTTPS (HTTP n’est toléré que pour localhost).');
        expect(errors).toContain('« *.test » est trop large (utilisez par exemple *.exemple.test).');
        expect(errors.some((message) => message.includes('http://x.example.test'))).toBe(true);
        expect(errors.some((message) => message.includes('bad host'))).toBe(true);

        expect(buildSettingsFromForm({ ...valid, domains: '  ' }, 'fr', accepts).errors).toEqual([
            'Indiquez au moins un domaine MyAB.',
        ]);
    });

    it('valide les sélecteurs CSS et le token', () => {
        const { errors } = buildSettingsFromForm(
            { ...valid, selectorDialog: '[[invalide', token: 'deux mots' },
            'en',
            (selector) => !selector.startsWith('[['),
        );
        expect(errors).toContain('The token contains spaces or disallowed characters.');
        expect(errors).toContain('Invalid CSS selector: Modal dialog.');
    });

    it('calcule les origines à autoriser : domaines + API', () => {
        const { settings } = buildSettingsFromForm(valid, 'fr', accepts);
        expect(requiredOriginPatterns(settings, ['https://myab.example.test/*'])).toEqual([
            'https://myab.example.test/*',
            'https://translations.example.test/*',
        ]);
    });
});
