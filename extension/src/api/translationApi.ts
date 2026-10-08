import type { ApiErrorKind, SearchRequest, SearchResponse, SearchResult } from '../types';
import { isLocalHost } from '../shared/domains';
import { cleanString, isRecord } from '../shared/text';
import { apiEndpoint } from '../shared/url';

export class ApiError extends Error {
    constructor(
        readonly kind: ApiErrorKind,
        readonly status?: number,
        readonly retryAfterSec?: number,
    ) {
        super(`Translation API error: ${kind}${status ? ` (${status})` : ''}`);
        this.name = 'ApiError';
    }
}

export interface ApiCallOptions {
    apiUrl: string;
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

function errorForStatus(response: Response): ApiError {
    const { status } = response;
    if (status === 401) return new ApiError('unauthorized', status);
    if (status === 403) return new ApiError('forbidden', status);
    if (status === 404) return new ApiError('notFound', status);
    if (status === 408) return new ApiError('timeout', status);
    if (status === 429) return new ApiError('rateLimited', status, parseRetryAfter(response.headers.get('Retry-After')));
    if (status >= 500) return new ApiError('server', status);
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
        }
        if (!response.ok) {
            throw errorForStatus(response);
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

/** Valide la réponse de l'API : champs inconnus ignorés, entrées sans clé écartées, score borné à [0, 1]. */
export function parseSearchResponse(data: unknown): SearchResponse {
    if (!isRecord(data) || !Array.isArray(data.results)) {
        throw new ApiError('invalidResponse');
    }
    const results = data.results.map(parseResult).filter((result): result is SearchResult => result !== null);
    return { query: cleanString(data.query, 500), results };
}

/** POST {apiUrl}/search */
export async function searchTranslations(request: SearchRequest, options: ApiCallOptions): Promise<SearchResponse> {
    return parseSearchResponse(await postJson(apiEndpoint(options.apiUrl, 'search'), request, options));
}

/** POST {apiUrl}/feedback : « cette correspondance est correcte ». */
export async function sendFeedback(
    payload: { text: string; resultId: string | number; key: string; pageUrl: string },
    options: ApiCallOptions,
): Promise<void> {
    await postJson(apiEndpoint(options.apiUrl, 'feedback'), { ...payload, verdict: 'correct' }, options);
}
