const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1']);
const HOST_PATTERN = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/;
const IPV4 = /^\d{1,3}(\.\d{1,3}){3}$/;

export type DomainRejection = 'empty' | 'invalid' | 'tooBroad' | 'insecure';

export type DomainParse =
    | { ok: true; canonical: string; host: string; wildcard: boolean }
    | { ok: false; reason: DomainRejection };

export interface DomainListResult {
    domains: string[];
    errors: Array<{ entry: string; reason: DomainRejection }>;
}

export function isLocalHost(host: string): boolean {
    return LOCAL_HOSTS.has(host);
}

/**
 * Accepte « myab.example.test », « https://myab.example.test/path », « *.example.test », « localhost:8080 ».
 * Le schéma est HTTPS (HTTP seulement pour localhost / 127.0.0.1), le port et le chemin sont ignorés
 * (les motifs de correspondance des navigateurs n'en tiennent pas compte).
 */
export function parseDomainEntry(input: string): DomainParse {
    let rest = input.trim().toLowerCase();
    if (!rest) {
        return { ok: false, reason: 'empty' };
    }
    let scheme = 'https';
    const schemeMatch = /^([a-z][a-z0-9+.-]*):\/\//.exec(rest);
    if (schemeMatch) {
        scheme = schemeMatch[1] ?? '';
        rest = rest.slice(schemeMatch[0].length);
    }
    if (scheme !== 'https' && scheme !== 'http') {
        return { ok: false, reason: 'invalid' };
    }
    rest = (rest.split(/[/?#]/, 1)[0] ?? '').replace(/:\d+$/, '').replace(/\.$/, '');
    if (!rest || rest.includes('@') || rest.includes('[') || rest.includes(':')) {
        return { ok: false, reason: 'invalid' };
    }
    let wildcard = false;
    if (rest.startsWith('*.')) {
        wildcard = true;
        rest = rest.slice(2);
    }
    if (!rest || rest.includes('*')) {
        return { ok: false, reason: 'invalid' };
    }
    let host: string;
    try {
        host = new URL(`https://${rest}`).hostname;
    } catch {
        return { ok: false, reason: 'invalid' };
    }
    if (!HOST_PATTERN.test(host)) {
        return { ok: false, reason: 'invalid' };
    }
    if (scheme === 'http' && !isLocalHost(host)) {
        return { ok: false, reason: 'insecure' };
    }
    if (wildcard && (IPV4.test(host) || isLocalHost(host))) {
        return { ok: false, reason: 'invalid' };
    }
    if (wildcard && host.split('.').length < 2) {
        return { ok: false, reason: 'tooBroad' };
    }
    return { ok: true, canonical: `${wildcard ? '*.' : ''}${host}`, host, wildcard };
}

/** Une entrée par ligne (virgules et points-virgules acceptés) ; doublons fusionnés. */
export function parseDomainList(input: string | readonly string[]): DomainListResult {
    const entries = (typeof input === 'string' ? input.split(/[\r\n,;]+/) : [...input])
        .map((entry) => entry.trim())
        .filter((entry) => entry !== '');
    const domains: string[] = [];
    const errors: DomainListResult['errors'] = [];
    for (const entry of entries) {
        const parsed = parseDomainEntry(entry);
        if (!parsed.ok) {
            errors.push({ entry, reason: parsed.reason });
        } else if (!domains.includes(parsed.canonical)) {
            domains.push(parsed.canonical);
        }
    }
    return { domains, errors };
}

/** Motifs de correspondance WebExtensions pour un domaine canonique. */
export function matchPatternsFor(domain: string): string[] {
    const parsed = parseDomainEntry(domain);
    if (!parsed.ok) {
        return [];
    }
    const host = `${parsed.wildcard ? '*.' : ''}${parsed.host}`;
    return isLocalHost(parsed.host) ? [`http://${host}/*`, `https://${host}/*`] : [`https://${host}/*`];
}

export function domainsToMatchPatterns(domains: readonly string[]): string[] {
    return [...new Set(domains.flatMap((domain) => matchPatternsFor(domain)))];
}

/** Contrôle de l'hôte courant, indépendant de l'enregistrement du content script (défense en profondeur). */
export function isHostAllowed(hostname: string, protocol: string, domains: readonly string[]): boolean {
    const host = hostname.toLowerCase().replace(/\.$/, '');
    for (const entry of domains) {
        const parsed = parseDomainEntry(entry);
        if (!parsed.ok) {
            continue;
        }
        const protocolOk = protocol === 'https:' || (protocol === 'http:' && isLocalHost(parsed.host));
        if (!protocolOk) {
            continue;
        }
        if (parsed.wildcard ? host === parsed.host || host.endsWith(`.${parsed.host}`) : host === parsed.host) {
            return true;
        }
    }
    return false;
}
