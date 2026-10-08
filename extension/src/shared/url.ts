import type { UrlMode } from '../types';
import { isLocalHost } from './domains';

export type ApiUrlCheck =
    | { ok: true; url: string; origin: string; matchPattern: string }
    | { ok: false; reason: 'empty' | 'invalid' | 'insecure' };

/**
 * URL transmise avec la requête. Identifiants, et par défaut la query-string (et celle d'une route
 * « #/page?id=1 »), sont retirés : les URL d'un ERP portent souvent des identifiants métier.
 */
export function sanitizeUrl(raw: string, mode: UrlMode): string {
    let url: URL;
    try {
        url = new URL(raw);
    } catch {
        return '';
    }
    if (url.protocol !== 'https:' && url.protocol !== 'http:') {
        return '';
    }
    url.username = '';
    url.password = '';
    if (mode === 'origin') {
        return url.origin;
    }
    if (mode === 'noQuery') {
        url.search = '';
        const queryStart = url.hash.indexOf('?');
        if (queryStart >= 0) {
            url.hash = url.hash.slice(0, queryStart);
        }
    }
    return url.toString();
}

/** HTTPS obligatoire ; HTTP toléré uniquement pour localhost / 127.0.0.1 (développement). */
export function validateHttpUrl(raw: string): ApiUrlCheck {
    const value = raw.trim();
    if (!value) {
        return { ok: false, reason: 'empty' };
    }
    let url: URL;
    try {
        url = new URL(value);
    } catch {
        return { ok: false, reason: 'invalid' };
    }
    if (url.username || url.password || (url.protocol !== 'https:' && url.protocol !== 'http:')) {
        return { ok: false, reason: 'invalid' };
    }
    if (url.protocol === 'http:' && !isLocalHost(url.hostname)) {
        return { ok: false, reason: 'insecure' };
    }
    const path = url.pathname.replace(/\/+$/, '');
    return {
        ok: true,
        url: `${url.origin}${path}`,
        origin: url.origin,
        matchPattern: `${url.protocol}//${url.hostname}/*`,
    };
}

export function apiEndpoint(apiUrl: string, name: string): string {
    return `${apiUrl.replace(/\/+$/, '')}/${name}`;
}

/** Lien sûr à ouvrir depuis le panneau : http(s) uniquement et origine attendue. */
export function safeLinkUrl(raw: string, allowedOrigins: readonly string[]): string | null {
    let url: URL;
    try {
        url = new URL(raw);
    } catch {
        return null;
    }
    if (url.protocol !== 'https:' && !(url.protocol === 'http:' && isLocalHost(url.hostname))) {
        return null;
    }
    return allowedOrigins.includes(url.origin) ? url.toString() : null;
}

/** Remplace {appUrl} et {query} dans le gabarit de recherche manuelle. */
export function buildManualSearchUrl(template: string, appUrl: string, query: string): string {
    return template.replace(/\{appUrl\}/g, appUrl.replace(/\/+$/, '')).replace(/\{query\}/g, encodeURIComponent(query));
}
