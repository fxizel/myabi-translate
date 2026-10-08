import type { MatchState, PanelResult, SearchRequest, SearchResponse, Settings } from '../types';
import { classifyResults, rankResults, toPanelResults } from './ranking';
import { buildManualSearchUrl, safeLinkUrl, validateHttpUrl } from './url';

export interface ResultsView {
    state: MatchState;
    results: PanelResult[];
}

/** Base de l'application de gestion : « URL de l'application » ou, à défaut, l'origine de l'API. */
export function applicationBase(settings: Settings): string {
    const app = validateHttpUrl(settings.appUrl);
    if (app.ok) {
        return app.url;
    }
    const api = validateHttpUrl(settings.apiUrl);
    return api.ok ? api.origin : '';
}

/** Seules ces origines peuvent être ouvertes depuis le panneau : une API compromise ne peut pas rediriger ailleurs. */
export function allowedLinkOrigins(settings: Settings): string[] {
    const origins: string[] = [];
    for (const raw of [settings.apiUrl, settings.appUrl]) {
        const check = validateHttpUrl(raw);
        if (check.ok && !origins.includes(check.origin)) {
            origins.push(check.origin);
        }
    }
    return origins;
}

export function manualSearchLink(settings: Settings, query: string): string | null {
    const base = applicationBase(settings);
    if (!base) {
        return null;
    }
    const url = buildManualSearchUrl(settings.manualSearchUrlTemplate, base, query);
    return safeLinkUrl(url, allowedLinkOrigins(settings));
}

/** Classe (score décroissant), borne à « nombre maximum de résultats » et qualifie la réponse de l'API. */
export function buildResultsView(response: SearchResponse, request: SearchRequest, settings: Settings): ResultsView {
    const ranked = rankResults(response.results, request.text, settings.maxResults);
    const origins = allowedLinkOrigins(settings);
    return {
        state: classifyResults(ranked, request.text),
        results: toPanelResults(ranked, request.text, (url) => safeLinkUrl(url, origins)),
    };
}
