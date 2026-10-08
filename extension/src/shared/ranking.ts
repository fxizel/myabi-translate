import type { MatchState, PanelResult, SearchResult } from '../types';
import { searchKey } from './text';

/** Score à partir duquel une correspondance exacte est présentée comme « forte ». */
export const STRONG_SCORE = 0.9;
/** Écart minimal avec le résultat suivant pour parler d'une seule correspondance forte. */
export const AMBIGUITY_GAP = 0.15;

/**
 * Exacte = l'API le dit (`exact`) ou le texte cherché est identique (sans casse ni accents)
 * à la traduction courante ou au texte source.
 */
export function isExactMatch(result: SearchResult, query: string): boolean {
    if (result.exact === true) {
        return true;
    }
    const wanted = searchKey(query);
    if (!wanted) {
        return false;
    }
    return [result.currentTranslation, result.sourceText].some((value) => value !== '' && searchKey(value) === wanted);
}

/** Score décroissant ; à score égal : exactes d'abord, puis ordre alphabétique des clés. */
export function rankResults(results: readonly SearchResult[], query: string, limit = Number.POSITIVE_INFINITY): SearchResult[] {
    const exact = new Map(results.map((result) => [result, isExactMatch(result, query)] as const));
    return [...results]
        .sort(
            (a, b) =>
                b.score - a.score ||
                Number(exact.get(b)) - Number(exact.get(a)) ||
                a.key.localeCompare(b.key, 'en'),
        )
        .slice(0, Math.max(0, limit));
}

/** `ranked` doit déjà être trié par rankResults. */
export function classifyResults(ranked: readonly SearchResult[], query: string): MatchState {
    const top = ranked[0];
    if (!top) {
        return 'none';
    }
    if (!ranked.some((result) => isExactMatch(result, query))) {
        return 'noExact';
    }
    const second = ranked[1];
    const clearWinner = !second || top.score - second.score >= AMBIGUITY_GAP;
    if (isExactMatch(top, query) && clearWinner && (top.score >= STRONG_SCORE || ranked.length === 1)) {
        return 'strong';
    }
    return 'multiple';
}

/** 0.96 → « 96 % » (espace insécable avant le signe). */
export function formatScore(score: number): string {
    return `${Math.round(Math.min(1, Math.max(0, score)) * 100)} %`;
}

export function toPanelResults(
    ranked: readonly SearchResult[],
    query: string,
    resolveLink: (url: string) => string | null,
): PanelResult[] {
    return ranked.map((result) => ({
        ...result,
        scoreLabel: formatScore(result.score),
        openUrl: result.translationUrl ? resolveLink(result.translationUrl) : null,
        exactMatch: isExactMatch(result, query),
    }));
}
