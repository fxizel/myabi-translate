/// <reference types="vite/client" />

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import optionsPage from '../src/options/options.html?raw';
import type { ExtensionApi } from '../src/shared/browserApi';
import { t } from '../src/shared/i18n';
import { SETTINGS_KEY, TIMEOUT_RANGE_MS } from '../src/shared/settings';
import { fakeApi } from './helpers';

let api: ExtensionApi;
let testButton: HTMLButtonElement;
let status: HTMLElement;

beforeEach(async () => {
    vi.resetModules();
    document.body.innerHTML = new DOMParser().parseFromString(optionsPage, 'text/html').body.innerHTML;
    testButton = document.querySelector<HTMLButtonElement>('#test')!;
    status = document.getElementById('status')!;
    const listen = vi.spyOn(testButton, 'addEventListener');
    ({ api } = fakeApi({ [SETTINGS_KEY]: { apiUrl: 'https://translations.example.test/api/browser-extension' } }));
    api.permissions = {
        request: vi.fn(async () => true),
        contains: vi.fn(async () => true),
        remove: vi.fn(async () => true),
    };
    vi.stubGlobal('chrome', api);
    await import('../src/options/options');
    await vi.waitFor(() => expect(listen).toHaveBeenCalledWith('click', expect.any(Function)));
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('test de connexion dans les paramètres', () => {
    it('affiche une erreur et permet une nouvelle tentative si le canal de messages se ferme', async () => {
        vi.mocked(api.runtime.sendMessage)
            .mockRejectedValueOnce(new Error('Extension context invalidated.'))
            .mockResolvedValueOnce({ ok: true, count: 1 });

        testButton.click();
        await vi.waitFor(() => expect(status.textContent).toContain('Rechargez la page des paramètres'));
        expect(testButton.disabled).toBe(false);

        testButton.click();
        await vi.waitFor(() => expect(status.textContent).toBe(t('fr', 'optTestOk', { count: 1 })));
    });

    it('termine le test quand le navigateur ne renvoie aucune réponse', async () => {
        vi.useFakeTimers();
        vi.mocked(api.runtime.sendMessage).mockReturnValueOnce(new Promise(() => {}));

        testButton.click();
        await vi.advanceTimersByTimeAsync(TIMEOUT_RANGE_MS.max);
        expect(status.textContent).toBe(t('fr', 'optTesting'));
        expect(testButton.disabled).toBe(true);

        await vi.advanceTimersByTimeAsync(5_000);
        expect(status.textContent).toContain('Rechargez la page des paramètres');
        expect(testButton.disabled).toBe(false);
    });
});
