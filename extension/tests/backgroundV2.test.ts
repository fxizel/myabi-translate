import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { errorMessage } from '../src/shared/i18n';
import type { PanelError } from '../src/types';
import { Inspector } from '../src/content/inspector';
import { normalizeSettings, SETTINGS_KEY, TOKEN_KEY, ENABLED_KEY } from '../src/shared/settings';
import type { ExtensionApi, MessageSender } from '../src/shared/browserApi';
import { fakeApi } from './helpers';

let api: ExtensionApi;
let inspector: Inspector | undefined;
let onBackgroundMessage: (message: unknown, sender: MessageSender, response: (value: unknown) => void) => unknown;
const sender = { id: 'test-extension', url: 'http://localhost:3000/myab?private=1', frameId: 0, tab: { id: 1, url: 'http://localhost:3000/myab?private=1', title: 'Tab title' } };
const empty = { contractVersion: 2, query: 'Test', matchState: 'none', exactCandidateCount: 0, hasMore: false, candidateSetComplete: true, feedbackAllowed: false, results: [] };

function dispatch(message: unknown, from = sender): Promise<unknown> {
    return new Promise(resolve => onBackgroundMessage(message, from, resolve));
}

beforeEach(async () => {
    vi.resetModules();
    const stub = fakeApi({ [SETTINGS_KEY]: normalizeSettings({ apiUrl: 'http://localhost:8000/api/browser-extension', allowedDomains: ['localhost'], collectContext: true, searchLanguage: 'it', uiLanguage: 'de', feedbackEnabled: true }), [TOKEN_KEY]: { value: 'synthetic-secret', origin: 'http://localhost:8000' }, [ENABLED_KEY]: true });
    api = stub.api;
    const noopEvent = () => ({ addListener: vi.fn(), removeListener: vi.fn() });
    Object.assign(api.runtime, { getURL: (path: string) => 'chrome-extension://test-extension/' + path, onInstalled: noopEvent(), onStartup: noopEvent() });
    Object.assign(api, {
        permissions: { contains: vi.fn(async () => true), request: vi.fn(async () => true), remove: vi.fn(async () => true) },
        tabs: { sendMessage: vi.fn(async () => undefined) },
        action: { setBadgeText: vi.fn(async () => undefined), setBadgeBackgroundColor: vi.fn(async () => undefined) },
    });
    vi.stubGlobal('browser', api);
    await import('../src/background/serviceWorker');
    onBackgroundMessage = vi.mocked(api.runtime.onMessage.addListener).mock.calls[0]![0];
    api.runtime.sendMessage = vi.fn(message => dispatch(message));
    document.documentElement.lang = 'xx';
    window.history.replaceState({}, '', '/myab?private=1');
});

afterEach(() => { inspector?.stop(); inspector = undefined; vi.unstubAllGlobals(); document.body.innerHTML = ''; document.documentElement.removeAttribute('lang'); });

describe('message content → sanitizer background → HTTP réel', () => {
    it.each([
        ['origin_not_allowed', 'serveur est à jour'],
        ['mfa_required', 'authentification à deux facteurs'],
        ['permission_denied', 'permissions du jeton'],
        ['feedback_not_allowed', 'retour de correspondance'],
        ['unknown_code', 'vérifiez les droits du compte et du token'],
    ])('propage HTTP403 / %s à la sonde et affiche une action fidèle', async (code, message) => {
        const privateMessage = 'PRIVATE-BUSINESS synthetic-secret https://private.example.test/account';
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ error: { code, message: privateMessage } }), { status: 403 })));
        const reply = await dispatch({ type: 'testConnection' }, { ...sender, url: 'chrome-extension://test-extension/options.html' }) as { ok: boolean; error: PanelError };
        expect(reply).toMatchObject({ ok: false, error: { kind: 'forbidden', status: 403 } });
        if (code === 'unknown_code') expect(reply.error).not.toHaveProperty('code');
        else expect(reply.error.code).toBe(code);
        expect(errorMessage('fr', reply.error)).toContain(message);
        expect(JSON.stringify(reply)).not.toContain(privateMessage);
        expect(errorMessage('fr', reply.error)).not.toContain('synthetic-secret');
        expect(errorMessage('fr', reply.error)).not.toContain('PRIVATE-BUSINESS');
    });
    it('accepte le mock non versionné uniquement quand la sonde est explicitement en mode mock', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ query: 'Test', results: [] }), { status: 200 })));
        const from = { ...sender, url: 'chrome-extension://test-extension/options.html' };
        expect(await dispatch({ type: 'testConnection', backendMode: 'mock' }, from)).toEqual({ ok: true, count: 0 });
        expect(await dispatch({ type: 'testConnection', backendMode: 'real' }, from)).toEqual({ ok: false, error: { kind: 'invalidResponse' } });
    });
    it('respecte le mode mock enregistré lorsque la sonde ne fournit pas de mode', async () => {
        const stored = await api.storage.local.get(SETTINGS_KEY);
        await api.storage.local.set({ [SETTINGS_KEY]: { ...(stored[SETTINGS_KEY] as object), backendMode: 'mock' } });
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ query: 'Test', results: [] }), { status: 200 })));
        const from = { ...sender, url: 'chrome-extension://test-extension/options.html' };
        expect(await dispatch({ type: 'testConnection' }, from)).toEqual({ ok: true, count: 0 });
        expect(await dispatch({ type: 'testConnection', backendMode: 'real' }, from)).toEqual({ ok: false, error: { kind: 'invalidResponse' } });
    });
    it('conserve v2, langue explicite et marqueur original sur tout le parcours Alt+clic', async () => {
        document.title = '𐐷'.repeat(201);
        document.body.innerHTML = '<button id="b" data-i18n-key="' + 'K'.repeat(101) + '" data-account="private">' + '𐐷'.repeat(501) + '</button>';
        const fetchImpl = vi.fn(async (_url: string, _init: RequestInit) => new Response(JSON.stringify({ ...empty, matchState: 'noExact', candidateSetComplete: false }), { status: 200 }));
        vi.stubGlobal('fetch', fetchImpl);
        inspector = new Inspector(api, document, { requireTrusted: false }); await inspector.start();
        document.getElementById('b')!.dispatchEvent(new MouseEvent('click', { altKey: true, bubbles: true, cancelable: true }));
        await vi.waitFor(() => expect(fetchImpl).toHaveBeenCalledOnce());
        const [url, init] = fetchImpl.mock.calls[0]!; const body = JSON.parse(String(init.body));
        expect(url).toBe('http://localhost:8000/api/browser-extension/search');
        expect(body).toMatchObject({ contractVersion: 2, textTruncated: true, searchLanguage: 'it', uiLanguage: 'de', dataAttributes: {}, pageUrl: 'http://localhost:3000' });
        expect([...body.text]).toHaveLength(500); expect([...body.pageTitle]).toHaveLength(200);
        expect(init).toMatchObject({ credentials: 'omit', cache: 'no-store', redirect: 'error' });
        await vi.waitFor(() => expect(api.tabs.sendMessage).toHaveBeenCalledWith(1, expect.objectContaining({ type: 'panel:results', feedbackEnabled: false, candidateSetComplete: false }), { frameId: 0 }));
    });
    it('sonde v2 avec catalogue vide depuis la page Options et sans domaine MyAB', async () => {
        const fetchImpl = vi.fn(async (_url: string, _init: RequestInit) => new Response(JSON.stringify(empty), { status: 200 })); vi.stubGlobal('fetch', fetchImpl);
        expect(await dispatch({ type: 'testConnection' }, { ...sender, url: 'chrome-extension://test-extension/options.html' })).toEqual({ ok: true, count: 0 });
        expect(JSON.parse(String(fetchImpl.mock.calls[0]![1].body))).toMatchObject({ contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr', maxResults: 1, pageUrl: '' });
    });
    it('n’active pas le feedback quand le serveur le refuse malgré le réglage local', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify(empty), { status: 200 })));
        const request = { contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr', uiLanguage: 'fr', maxResults: 1 };
        expect(await dispatch({ type: 'search', requestId: 'r', request })).toEqual({ ok: true });
        expect(api.tabs.sendMessage).toHaveBeenLastCalledWith(1, expect.objectContaining({ type: 'panel:results', feedbackEnabled: false }), { frameId: 0 });
    });
    it('applique les réglages de confidentialité actuels à un ancien message content', async () => {
        const stored = await api.storage.local.get(SETTINGS_KEY);
        await api.storage.local.set({ [SETTINGS_KEY]: { ...(stored[SETTINGS_KEY] as object), collectContext: false, searchLanguage: 'auto' } });
        const fetchImpl = vi.fn(async (_url: string, _init: RequestInit) => new Response(JSON.stringify(empty), { status: 200 })); vi.stubGlobal('fetch', fetchImpl);
        await dispatch({ type: 'search', requestId: 'r', request: { contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr', searchLanguage: 'it', pageUrl: 'http://localhost:3000/private/123?secret=1', pageTitle: 'PRIVATE-TITLE', surroundingText: 'PRIVATE-CONTEXT', dataAttributes: { 'data-i18n-key': 'K' } } });
        const body = JSON.parse(String(fetchImpl.mock.calls[0]![1].body));
        expect(body).toMatchObject({ pageUrl: 'http://localhost:3000', pageTitle: '', surroundingText: '', dataAttributes: {} });
        expect(body).not.toHaveProperty('searchLanguage'); expect(JSON.stringify(body)).not.toContain('PRIVATE');
    });
    it('relaie la preuve et la cellule du message feedback intacts en v2', async () => {
        const fetchImpl = vi.fn(async (_url: string, _init: RequestInit) => new Response(JSON.stringify({ ok: true }), { status: 200 })); vi.stubGlobal('fetch', fetchImpl);
        const proof = 'signed.' + 'P'.repeat(2000);
        const resultId = 'cell2.WzEyMzQsImxhYmVsIiwiZnIiXQ';
        expect(await dispatch({ type: 'feedback', request: { contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr' }, key: 'K', resultId, feedbackProof: proof })).toEqual({ ok: true });
        expect(JSON.parse(String(fetchImpl.mock.calls[0]![1].body))).toEqual({ contractVersion: 2, resultId, feedbackProof: proof, verdict: 'correct' });
    });
    it('ne bascule pas automatiquement vers le mock sur un échec réseau', async () => {
        const fetchImpl = vi.fn(async () => { throw new Error('synthetic-secret PRIVATE'); }); vi.stubGlobal('fetch', fetchImpl);
        expect(await dispatch({ type: 'search', requestId: 'r', request: { contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr' } })).toEqual({ ok: false });
        expect(fetchImpl).toHaveBeenCalledOnce();
        expect(api.tabs.sendMessage).toHaveBeenLastCalledWith(1, expect.objectContaining({ type: 'panel:error', error: { kind: 'network' } }), { frameId: 0 });
    });
    it('ne transmet pas le jeton enregistré à une autre origine depuis Tester', async () => {
        const fetchImpl = vi.fn(); vi.stubGlobal('fetch', fetchImpl);
        const from = { ...sender, url: 'chrome-extension://test-extension/options.html' };
        expect(await dispatch({ type: 'testConnection', apiUrl: 'https://untrusted.example.test/api', token: '' }, from))
            .toMatchObject({ ok: false, error: { kind: 'notConfigured' } });
        expect(fetchImpl).not.toHaveBeenCalled();
    });
    it('permet de tester une nouvelle origine avec un nouveau jeton saisi explicitement', async () => {
        const fetchImpl = vi.fn(async () => new Response(JSON.stringify(empty), { status: 200 }));
        vi.stubGlobal('fetch', fetchImpl);
        const from = { ...sender, url: 'chrome-extension://test-extension/options.html' };
        expect(await dispatch({ type: 'testConnection', apiUrl: 'https://new.example.test/api', token: 'new-synthetic-token' }, from)).toEqual({ ok: true, count: 0 });
        expect(fetchImpl).toHaveBeenCalledWith('https://new.example.test/api/search', expect.objectContaining({
            headers: expect.objectContaining({ Authorization: 'Bearer new-synthetic-token' }), redirect: 'error',
        }));
    });
    it('refuse search et feedback si les réglages changent avant le remplacement du jeton', async () => {
        const stored = await api.storage.local.get(SETTINGS_KEY);
        await api.storage.local.set({ [SETTINGS_KEY]: { ...(stored[SETTINGS_KEY] as object), apiUrl: 'https://new.example.test/api' } });
        const fetchImpl = vi.fn(); vi.stubGlobal('fetch', fetchImpl);
        const request = { contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr' };
        expect(await dispatch({ type: 'search', requestId: 'r', request })).toEqual({ ok: false });
        expect(await dispatch({ type: 'feedback', request, key: 'K', resultId: 'cell2.synthetic', feedbackProof: 'synthetic-proof' })).toEqual({ ok: false });
        const from = { ...sender, url: 'chrome-extension://test-extension/options.html' };
        expect(await dispatch({ type: 'testConnection' }, from)).toMatchObject({ ok: false, error: { kind: 'notConfigured' } });
        expect(fetchImpl).not.toHaveBeenCalled();
    });
    it('refuse le jeton suivant si une recherche possède encore les réglages précédents', async () => {
        const get = api.storage.local.get.bind(api.storage.local);
        api.storage.local.get = vi.fn(async (keys) => {
            const value = await get(keys);
            if (keys === SETTINGS_KEY) {
                await api.storage.local.set({ [TOKEN_KEY]: { value: 'next-token', origin: 'https://new.example.test' } });
            }
            return value;
        });
        const fetchImpl = vi.fn(); vi.stubGlobal('fetch', fetchImpl);
        expect(await dispatch({ type: 'search', requestId: 'r', request: { contractVersion: 2, textTruncated: false, text: 'Test', lang: 'fr' } })).toEqual({ ok: false });
        expect(fetchImpl).not.toHaveBeenCalled();
    });

});
