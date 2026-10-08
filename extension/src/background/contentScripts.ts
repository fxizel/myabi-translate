import { getApi, type ExtensionApi, type ScriptRegistration } from '../shared/browserApi';
import { domainsToMatchPatterns } from '../shared/domains';
import { loadSettings } from '../shared/settings';

export const CONTENT_SCRIPT_ID = 'myab-translation-inspector';

async function grantedPatterns(api: ExtensionApi, patterns: readonly string[]): Promise<string[]> {
    const granted: string[] = [];
    for (const pattern of patterns) {
        try {
            if (await api.permissions.contains({ origins: [pattern] })) {
                granted.push(pattern);
            }
        } catch {
            // Motif refusé par le navigateur : ignoré.
        }
    }
    return granted;
}

async function synchronize(api: ExtensionApi): Promise<void> {
    const settings = await loadSettings(api);
    const patterns = await grantedPatterns(api, domainsToMatchPatterns(settings.allowedDomains));
    const existing = await api.scripting.getRegisteredContentScripts({ ids: [CONTENT_SCRIPT_ID] }).catch(() => []);

    if (patterns.length === 0) {
        if (existing.length > 0) {
            await api.scripting.unregisterContentScripts({ ids: [CONTENT_SCRIPT_ID] });
        }
        return;
    }
    const script: ScriptRegistration = {
        id: CONTENT_SCRIPT_ID,
        matches: patterns,
        js: ['content.js'],
        runAt: 'document_start',
        allFrames: true,
        persistAcrossSessions: true,
    };
    if (existing.length > 0) {
        await api.scripting.updateContentScripts([script]);
    } else {
        await api.scripting.registerContentScripts([script]);
    }
}

let queue: Promise<void> = Promise.resolve();

/**
 * Le content script n'est PAS déclaré dans le manifeste : il n'est enregistré que pour les domaines
 * configurés ET autorisés par l'utilisateur. Les appels sont sérialisés (install + changement de réglages…).
 */
export function syncContentScripts(api: ExtensionApi = getApi()): Promise<void> {
    queue = queue.then(
        () => synchronize(api),
        () => synchronize(api),
    );
    return queue.catch(() => undefined);
}
