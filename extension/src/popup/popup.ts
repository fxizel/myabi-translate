import { getApi } from '../shared/browserApi';
import { modifierLabel, resolveLanguage, t } from '../shared/i18n';
import { loadEnabled, loadSettings, loadToken, saveEnabled } from '../shared/settings';
import { isRecord } from '../shared/text';
import { validateHttpUrl } from '../shared/url';

const api = getApi();
const $ = <T extends HTMLElement>(id: string): T => document.getElementById(id) as T;

async function pageStatus(): Promise<'active' | 'inactive'> {
    try {
        const [tab] = await api.tabs.query({ active: true, currentWindow: true });
        if (tab?.id === undefined) {
            return 'inactive';
        }
        // Le content script n'existe que sur les domaines configurés et autorisés.
        const reply = await api.tabs.sendMessage(tab.id, { type: 'inspector:ping' }, { frameId: 0 });
        return isRecord(reply) && reply.ok === true ? 'active' : 'inactive';
    } catch {
        return 'inactive';
    }
}

function setStatus(text: string, tone: 'ok' | 'warn'): void {
    const status = $('status');
    status.textContent = text;
    status.className = `status ${tone}`;
}

async function init(): Promise<void> {
    const settings = await loadSettings(api);
    const lang = resolveLanguage(settings.uiLanguage, api.i18n.getUILanguage());
    document.documentElement.lang = lang;

    $('toggleLabel').textContent = t(lang, 'popupToggle');
    $('hint').textContent = t(lang, 'popupHint', { modifier: modifierLabel(lang, settings.activationModifier) });
    $('settings').textContent = t(lang, 'openSettings');
    $('settings').addEventListener('click', () => {
        void api.runtime.openOptionsPage().then(() => window.close());
    });

    const toggle = $<HTMLInputElement>('toggle');
    toggle.checked = await loadEnabled(api);
    toggle.addEventListener('change', () => {
        void saveEnabled(toggle.checked, api);
    });

    const configured =
        validateHttpUrl(settings.apiUrl).ok && settings.allowedDomains.length > 0 && (await loadToken(api)) !== '';
    if (!configured) {
        setStatus(t(lang, 'popupNotConfigured'), 'warn');
        return;
    }
    const status = await pageStatus();
    setStatus(t(lang, status === 'active' ? 'popupActive' : 'popupInactive'), status === 'active' ? 'ok' : 'warn');
}

void init();
