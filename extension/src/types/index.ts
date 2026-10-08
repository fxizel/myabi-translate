export type Lang = 'fr' | 'de' | 'en';
export type LangPreference = 'auto' | Lang;
export type UrlMode = 'full' | 'noQuery' | 'origin';
export type ActivationModifier = 'alt' | 'altShift' | 'altCtrl';
export type TokenPersistence = 'local' | 'session';

/** Sélecteurs CSS optionnels pour adapter l'extraction au DOM réel de MyAB. */
export interface SelectorSettings {
    breadcrumb: string;
    dialog: string;
    sectionTitle: string;
    /** Éléments jamais inspectés ni lus (en plus de l'overlay de l'extension). */
    ignore: string;
}

/** Paramètres non secrets. Le token est stocké à part (voir shared/settings.ts). */
export interface Settings {
    /** Base de l'API, sans slash final : https://translations.example.test/api/browser-extension */
    apiUrl: string;
    /** Base de l'application de gestion (liens, recherche manuelle). Vide = origine de apiUrl. */
    appUrl: string;
    /** Domaines MyAB autorisés, forme canonique : « myab.example.test » ou « *.example.test ». */
    allowedDomains: string[];
    uiLanguage: LangPreference;
    activationModifier: ActivationModifier;
    maxResults: number;
    collectContext: boolean;
    urlMode: UrlMode;
    timeoutMs: number;
    feedbackEnabled: boolean;
    tokenPersistence: TokenPersistence;
    manualSearchUrlTemplate: string;
    selectors: SelectorSettings;
}

/** Contexte collecté lors d'un Alt+clic. Les champs de base sont toujours présents. */
export interface ContextPayload {
    text: string;
    pageUrl: string;
    pageTitle: string;
    surroundingText: string;
    elementTag: string;
    elementId: string;
    elementClasses: string[];
    ariaLabel: string;
    title: string;
    dataAttributes: Record<string, string>;
    lang: string;
    /** Autres lectures plausibles du texte cliqué (élément le plus profond, texte direct). */
    alternativeTexts?: string[];
    frameUrl?: string;
    role?: string;
    elementName?: string;
    inputType?: string;
    ariaAttributes?: Record<string, string>;
    parentText?: string;
    siblingTexts?: string[];
    formLabel?: string;
    sectionTitle?: string;
    inDialog?: boolean;
    dialogTitle?: string;
    breadcrumbs?: string[];
    tableColumn?: string;
}

export interface SearchRequest extends ContextPayload {
    maxResults: number;
    uiLanguage: Lang;
}

export interface SearchResult {
    id: string | number;
    key: string;
    sourceLanguage: string;
    sourceText: string;
    currentTranslation: string;
    proposedTranslation: string;
    targetLanguage: string;
    module: string;
    status: string;
    score: number;
    translationUrl: string;
    /** Renseigné par l'API si elle sait que la correspondance est exacte. */
    exact?: boolean;
}

export interface SearchResponse {
    query: string;
    results: SearchResult[];
}

export type ApiErrorKind =
    | 'network'
    | 'timeout'
    | 'aborted'
    | 'unauthorized'
    | 'forbidden'
    | 'notFound'
    | 'rateLimited'
    | 'server'
    | 'http'
    | 'invalidResponse'
    | 'insecure';

export type PanelErrorKind =
    | ApiErrorKind
    | 'noText'
    | 'notConfigured'
    | 'domainNotAllowed'
    | 'permission'
    | 'contextInvalidated';

export interface PanelError {
    kind: PanelErrorKind;
    status?: number;
    retryAfterSec?: number;
}

export type MatchState = 'none' | 'noExact' | 'strong' | 'multiple';

/** Résultat prêt à afficher : lien déjà validé, score déjà formaté. */
export interface PanelResult extends SearchResult {
    scoreLabel: string;
    openUrl: string | null;
    exactMatch: boolean;
}
