import type { Lang, MatchState, PanelError, PanelResult, SearchRequest } from '../types';
import { characterLength, cleanString, isRecord, normalizeText, truncate } from './text';

/* ---- Content script → background -------------------------------------------------------------------------------- */

export interface SearchMessage {
    type: 'search';
    requestId: string;
    request: SearchRequest;
}
export interface NoTextMessage {
    type: 'noText';
    requestId: string;
}
export interface FeedbackMessage {
    type: 'feedback';
    request: SearchRequest;
    resultId: string | number;
    feedbackProof?: string;
    key: string;
}
export interface CloseInspectorMessage {
    type: 'closeInspector';
}
export interface OpenOptionsMessage {
    type: 'openOptions';
}
/** Page Options → background : test avec les valeurs du formulaire (token vide = token enregistré). */
export interface TestConnectionMessage {
    type: 'testConnection';
    apiUrl: string;
    token: string;
}

/* ---- Background → content script (frame du haut) ---------------------------------------------------------------- */

export interface PanelLoadingMessage {
    type: 'panel:loading';
    requestId: string;
    request: SearchRequest;
    lang: Lang;
}
export interface PanelResultsMessage {
    type: 'panel:results';
    requestId: string;
    request: SearchRequest;
    lang: Lang;
    state: MatchState;
    results: PanelResult[];
    manualSearchUrl: string | null;
    feedbackEnabled: boolean;
    hasMore?: boolean;
    candidateSetComplete?: boolean;
    mock?: boolean;
}
export interface PanelErrorMessage {
    type: 'panel:error';
    requestId: string;
    request?: SearchRequest;
    lang: Lang;
    error: PanelError;
    manualSearchUrl: string | null;
}
export interface InspectorCloseMessage {
    type: 'inspector:close';
}
export interface InspectorPingMessage {
    type: 'inspector:ping';
}

export type PanelMessage = PanelLoadingMessage | PanelResultsMessage | PanelErrorMessage;

export function hasType(message: unknown): message is { type: string } & Record<string, unknown> {
    return isRecord(message) && typeof message.type === 'string';
}

/* ---- Validation de ce qui part vers l'API ----------------------------------------------------------------------- */

function cleanStringList(value: unknown, maxItems: number, maxLength: number): string[] {
    if (!Array.isArray(value)) {
        return [];
    }
    return value
        .map((item) => cleanString(item, maxLength))
        .filter((item) => item !== '')
        .slice(0, maxItems);
}

function cleanStringMap(value: unknown, maxItems: number, maxLength: number): Record<string, string> {
    const out: Record<string, string> = {};
    if (!isRecord(value)) {
        return out;
    }
    for (const [key, item] of Object.entries(value)) {
        if (Object.keys(out).length >= maxItems) {
            break;
        }
        if (/^(data|aria)-[a-z0-9_.:-]{1,80}$/i.test(key)) {
            out[key] = cleanString(item, maxLength);
        }
    }
    return out;
}

/**
 * Le background est la frontière avant l'envoi vers l'API : il reconstruit la requête champ par champ
 * (types, tailles, listes bornées) au lieu de transmettre telle quelle la donnée reçue.
 */
export function sanitizeSearchRequest(raw: unknown): SearchRequest | null {
    if (!isRecord(raw)) {
        return null;
    }
    if (raw.contractVersion !== undefined && raw.contractVersion !== 2) return null;
    if (raw.textTruncated !== undefined && typeof raw.textTruncated !== 'boolean') return null;
    if (raw.searchLanguage !== undefined && !['de', 'fr', 'it', 'en'].includes(String(raw.searchLanguage))) return null;
    const text = cleanString(raw.text, 500);
    if (!text) {
        return null;
    }
    const request: SearchRequest = {
        text,
        contractVersion: 2,
        textTruncated: raw.textTruncated === true || characterLength(normalizeText(typeof raw.text === 'string' ? raw.text : '')) > 500,
        pageUrl: truncate(typeof raw.pageUrl === 'string' ? raw.pageUrl : '', 2000),
        pageTitle: cleanString(raw.pageTitle, 200),
        surroundingText: cleanString(raw.surroundingText, 600),
        elementTag: cleanString(raw.elementTag, 40).toLowerCase(),
        elementId: cleanString(raw.elementId, 100),
        elementClasses: cleanStringList(raw.elementClasses, 20, 60),
        ariaLabel: cleanString(raw.ariaLabel, 200),
        title: cleanString(raw.title, 200),
        dataAttributes: isRecord(raw.dataAttributes) && typeof raw.dataAttributes['data-i18n-key'] === 'string' && characterLength(raw.dataAttributes['data-i18n-key']) <= 100
            ? { 'data-i18n-key': raw.dataAttributes['data-i18n-key'] } : {},
        lang: cleanString(raw.lang, 12).toLowerCase(),
        maxResults: typeof raw.maxResults === 'number' && Number.isFinite(raw.maxResults) ? Math.min(20, Math.max(1, Math.round(raw.maxResults))) : 5,
        uiLanguage: raw.uiLanguage === 'de' || raw.uiLanguage === 'en' ? raw.uiLanguage : 'fr',
    };

    if (raw.searchLanguage === 'de' || raw.searchLanguage === 'fr' || raw.searchLanguage === 'it' || raw.searchLanguage === 'en') request.searchLanguage = raw.searchLanguage;
    const alternatives = cleanStringList(raw.alternativeTexts, 3, 300);
    if (alternatives.length) request.alternativeTexts = alternatives;
    const frameUrl = typeof raw.frameUrl === 'string' ? truncate(raw.frameUrl, 2000) : '';
    if (frameUrl) request.frameUrl = frameUrl;
    for (const field of ['role', 'elementName', 'inputType', 'parentText', 'formLabel', 'sectionTitle', 'dialogTitle', 'tableColumn'] as const) {
        const value = cleanString(raw[field], field === 'parentText' ? 400 : 200);
        if (value) request[field] = value;
    }
    const ariaAttributes = cleanStringMap(raw.ariaAttributes, 10, 100);
    if (Object.keys(ariaAttributes).length) request.ariaAttributes = ariaAttributes;
    const siblings = cleanStringList(raw.siblingTexts, 6, 100);
    if (siblings.length) request.siblingTexts = siblings;
    const breadcrumbs = cleanStringList(raw.breadcrumbs, 8, 80);
    if (breadcrumbs.length) request.breadcrumbs = breadcrumbs;
    if (raw.inDialog === true) request.inDialog = true;
    return request;
}
