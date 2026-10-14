import type { ApiErrorKind, BackendMode, SearchRequest, SearchResponse, SearchResult } from '../types';
import { isLocalHost } from '../shared/domains';
import { characterLength, cleanString, isRecord } from '../shared/text';
import { apiEndpoint } from '../shared/url';

export class ApiError extends Error {
    constructor(
        readonly kind: ApiErrorKind,
        readonly status?: number,
        readonly retryAfterSec?: number,
        readonly code?: string,
    ) {
        super(`Translation API error: ${kind}${status ? ` (${status})` : ''}`);
        this.name = 'ApiError';
    }
}

export interface ApiCallOptions {
    apiUrl: string;
    backendMode?: BackendMode;
    /** Jamais journalisé : n'apparaît ni dans les messages d'erreur ni dans les logs. */
    token: string;
    timeoutMs: number;
    signal?: AbortSignal;
    fetchImpl?: (input: string, init: RequestInit) => Promise<Response>;
}

const MAX_RETRY_AFTER_SEC = 3600;

function parseRetryAfter(header: string | null): number | undefined {
    if (!header) {
        return undefined;
    }
    const seconds = /^\d+$/.test(header.trim()) ? Number(header) : Math.ceil((Date.parse(header) - Date.now()) / 1000);
    return Number.isFinite(seconds) && seconds > 0 ? Math.min(seconds, MAX_RETRY_AFTER_SEC) : undefined;
}

async function errorForStatus(response: Response): Promise<ApiError> {
    const { status } = response;
    let code: string | undefined;
    try {
        const body: unknown = await response.json();
        if (isRecord(body) && isRecord(body.error) && typeof body.error.code === 'string' && ['unauthenticated', 'origin_not_allowed', 'permission_denied', 'mfa_required', 'feedback_not_allowed', 'result_stale', 'payload_too_large', 'unsupported_media_type', 'invalid_request', 'search_language_required', 'client_upgrade_required', 'rate_limited', 'internal_error', 'search_index_unavailable'].includes(body.error.code)) code = body.error.code;
    } catch { /* Une erreur HTTP reste exploitable sans JSON. */ }
    if (status === 401) return new ApiError('unauthorized', status, undefined, code);
    if (status === 403) return new ApiError('forbidden', status, undefined, code);
    if (status === 404) return new ApiError('notFound', status, undefined, code);
    if (status === 409) return new ApiError('stale', status, undefined, code);
    if (status === 413) return new ApiError('payloadTooLarge', status, undefined, code);
    if (status === 415) return new ApiError('mediaType', status, undefined, code);
    if (status === 422) return new ApiError(code === 'search_language_required' ? 'languageRequired' : 'invalidRequest', status, undefined, code);
    if (status === 426) return new ApiError('upgradeRequired', status, undefined, code);
    if (status === 408) return new ApiError('timeout', status);
    if (status === 429) return new ApiError('rateLimited', status, parseRetryAfter(response.headers.get('Retry-After')));
    if (status >= 500) return new ApiError('server', status, undefined, code);
    return new ApiError('http', status);
}

async function postJson(url: string, body: unknown, options: ApiCallOptions): Promise<unknown> {
    const send = options.fetchImpl ?? ((input: string, init: RequestInit) => fetch(input, init));
    const controller = new AbortController();
    let timedOut = false;
    const timer = setTimeout(() => {
        timedOut = true;
        controller.abort();
    }, options.timeoutMs);
    const forwardAbort = (): void => controller.abort();
    options.signal?.addEventListener('abort', forwardAbort, { once: true });
    if (options.signal?.aborted) {
        controller.abort();
    }

    try {
        let response: Response;
        try {
            response = await send(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    Authorization: `Bearer ${options.token}`,
                },
                body: JSON.stringify(body),
                signal: controller.signal,
                credentials: 'omit',
                cache: 'no-store',
                redirect: 'error',
            });
        } catch {
            if (timedOut) throw new ApiError('timeout');
            if (options.signal?.aborted) throw new ApiError('aborted');
            throw new ApiError('network');
        }

        if (response.url) {
            const finalUrl = new URL(response.url);
            if (finalUrl.protocol !== 'https:' && !isLocalHost(finalUrl.hostname)) {
                throw new ApiError('insecure');
            }
            if (finalUrl.origin !== new URL(url).origin) throw new ApiError('invalidResponse', response.status);
        }
        if (!response.ok) {
            throw await errorForStatus(response);
        }
        try {
            return await response.json();
        } catch {
            if (timedOut) throw new ApiError('timeout');
            throw new ApiError('invalidResponse', response.status);
        }
    } finally {
        clearTimeout(timer);
        options.signal?.removeEventListener('abort', forwardAbort);
    }
}

function toScore(value: unknown): number {
    const number = typeof value === 'number' ? value : typeof value === 'string' ? Number(value) : NaN;
    if (!Number.isFinite(number)) {
        return 0;
    }
    // Tolère une API qui répond en pourcentage (96) plutôt qu'en ratio (0.96).
    const ratio = number > 1 && number <= 100 ? number / 100 : number;
    return Math.min(1, Math.max(0, ratio));
}

function parseResult(item: unknown): SearchResult | null {
    if (!isRecord(item)) {
        return null;
    }
    const key = cleanString(item.key, 300);
    if (!key) {
        return null;
    }
    const id = typeof item.id === 'number' || typeof item.id === 'string' ? item.id : key;
    const result: SearchResult = {
        id,
        key,
        sourceLanguage: cleanString(item.sourceLanguage, 8).toLowerCase(),
        sourceText: cleanString(item.sourceText, 1000),
        currentTranslation: cleanString(item.currentTranslation, 1000),
        proposedTranslation: cleanString(item.proposedTranslation, 1000),
        targetLanguage: cleanString(item.targetLanguage, 8).toLowerCase(),
        module: cleanString(item.module, 200),
        status: cleanString(item.status, 60),
        score: toScore(item.score),
        translationUrl: typeof item.translationUrl === 'string' ? item.translationUrl.trim().slice(0, 2000) : '',
    };
    if (typeof item.exact === 'boolean') {
        result.exact = item.exact;
    }
    return result;
}

function invalid(): never { throw new ApiError('invalidResponse'); }

function requiredString(value: unknown, max = Number.POSITIVE_INFINITY, nonempty = false): string {
    if (typeof value !== 'string' || characterLength(value) > max || (nonempty && !value)) invalid();
    return value;
}

function nonnegativeInt(value: unknown): number {
    if (typeof value !== 'number' || !Number.isSafeInteger(value) || value < 0) invalid();
    return value;
}

function parseV2Result(item: unknown, feedbackAllowed: boolean): SearchResult {
    if (!isRecord(item)) invalid();
    const id = requiredString(item.id, 1024, true);
    if (!/^cell2\.[A-Za-z0-9_-]+$/.test(id)) invalid();
    const termId = nonnegativeInt(item.termId);
    if (!termId || !Array.isArray(item.keyParts) || !item.keyParts.length || item.keyParts.some((part) => typeof part !== 'string')) invalid();
    const type = requiredString(item.type, 40, true);
    const attribute = requiredString(item.attribute, 200, true);
    const targetLanguage = requiredString(item.targetLanguage, 2);
    const sourceLanguage = requiredString(item.sourceLanguage, 2);
    if (!['de', 'fr', 'it', 'en'].includes(targetLanguage) || !['', 'de', 'fr', 'it', 'en'].includes(sourceLanguage)) invalid();
    const status = requiredString(item.status, 20);
    if (!['review', 'missing', 'published', 'validated', 'obsolete'].includes(status)) invalid();
    if (!['validated', 'imported', 'reference'].includes(String(item.valueOrigin))) invalid();
    if (!['literal', 'normalized', 'alternative', 'approximate'].includes(String(item.matchReason))) invalid();
    if (item.exact === true && item.valueOrigin === 'reference' && sourceLanguage !== targetLanguage) invalid();
    if (typeof item.score !== 'number' || !Number.isFinite(item.score) || item.score < 0 || item.score > 1 || typeof item.exact !== 'boolean' || typeof item.previewTruncated !== 'boolean') invalid();
    const proposedTranslation = requiredString(item.proposedTranslation, 1000);
    if (proposedTranslation !== '') invalid();
    const proof = item.feedbackProof === undefined ? undefined : requiredString(item.feedbackProof, 4096, true);
    if (feedbackAllowed && !proof) invalid();
    return {
        id, termId, type, attribute, targetLanguage, sourceLanguage, status,
        key: requiredString(item.key),
        keyParts: [...item.keyParts] as string[],
        sourceText: requiredString(item.sourceText, 1000),
        currentTranslation: requiredString(item.currentTranslation, 1000),
        importedTranslation: requiredString(item.importedTranslation, 1000),
        matchedText: requiredString(item.matchedText, 1000),
        proposedTranslation,
        valueOrigin: item.valueOrigin as SearchResult['valueOrigin'],
        matchReason: item.matchReason as string,
        pendingCount: nonnegativeInt(item.pendingCount),
        module: requiredString(item.module, 200),
        score: item.score,
        exact: item.exact,
        previewTruncated: item.previewTruncated,
        translationUrl: requiredString(item.translationUrl, 2000),
        ...(proof === undefined ? {} : { feedbackProof: proof }),
    };
}

/** Le contrat réel est complet ou refusé. Le contrat legacy n'est accepté qu'en mode mock explicite. */
export function parseSearchResponse(data: unknown, backendMode: BackendMode = 'real'): SearchResponse {
    if (!isRecord(data) || !Array.isArray(data.results)) invalid();
    if (data.contractVersion !== 2) {
        if (backendMode !== 'mock' || data.contractVersion !== undefined) invalid();
        return {
            query: cleanString(data.query, 500),
            results: data.results.map(parseResult).filter((result): result is SearchResult => result !== null),
            mock: true,
            ...(typeof data.matchState === 'string' && ['none', 'noExact', 'strong', 'multiple'].includes(data.matchState) ? { matchState: data.matchState as SearchResponse['matchState'] } : {}),
            ...(typeof data.hasMore === 'boolean' ? { hasMore: data.hasMore } : {}),
            ...(typeof data.candidateSetComplete === 'boolean' ? { candidateSetComplete: data.candidateSetComplete } : {}),
            ...(typeof data.feedbackAllowed === 'boolean' ? { feedbackAllowed: data.feedbackAllowed } : {}),
        };
    }
    if (typeof data.hasMore !== 'boolean' || typeof data.candidateSetComplete !== 'boolean' || typeof data.feedbackAllowed !== 'boolean' || !['none', 'noExact', 'strong', 'multiple'].includes(String(data.matchState)) || data.results.length > 20) invalid();
    const exactCandidateCount = nonnegativeInt(data.exactCandidateCount);
    const results = data.results.map((item) => parseV2Result(item, data.feedbackAllowed as boolean));
    const displayedExacts = results.filter((result) => result.exact).length;
    if (new Set(results.map((result) => result.id)).size !== results.length || displayedExacts > exactCandidateCount || (!data.hasMore && displayedExacts !== exactCandidateCount)) invalid();
    if (data.matchState === 'none' && (results.length || exactCandidateCount || !data.candidateSetComplete)) invalid();
    if (data.matchState === 'noExact' && exactCandidateCount) invalid();
    if (data.matchState === 'multiple' && exactCandidateCount === 0) invalid();
    if (data.matchState === 'strong' && (exactCandidateCount !== 1 || !data.candidateSetComplete || !results[0]?.exact || results[0].score < 0.9 || (results[1] && results[0].score - results[1].score < 0.15))) invalid();
    return {
        contractVersion: 2,
        query: requiredString(data.query, 500),
        matchState: data.matchState as SearchResponse['matchState'],
        exactCandidateCount,
        hasMore: data.hasMore,
        candidateSetComplete: data.candidateSetComplete,
        feedbackAllowed: data.feedbackAllowed,
        results,
        mock: backendMode === 'mock',
    };
}

/** POST {apiUrl}/search. Aucun repli automatique vers le mock. */
export async function searchTranslations(request: SearchRequest, options: ApiCallOptions): Promise<SearchResponse> {
    return parseSearchResponse(await postJson(apiEndpoint(options.apiUrl, 'search'), request, options), options.backendMode ?? 'real');
}

/** Les champs de preuve et d'identité sont relayés sans modification. */
export async function sendFeedback(
    payload: { text: string; resultId: string | number; key: string; pageUrl: string; feedbackProof?: string },
    options: ApiCallOptions,
): Promise<void> {
    const body = options.backendMode === 'mock'
        ? { ...payload, verdict: 'correct' }
        : { contractVersion: 2, resultId: payload.resultId, feedbackProof: payload.feedbackProof, verdict: 'correct' };
    const reply = await postJson(apiEndpoint(options.apiUrl, 'feedback'), body, options);
    if (!isRecord(reply) || reply.ok !== true) throw new ApiError('invalidResponse');
}
