import type { Lang, Settings } from '../types';
import { parseDomainList } from '../shared/domains';
import { t } from '../shared/i18n';
import { isPlausibleToken, normalizeSettings } from '../shared/settings';
import { validateHttpUrl } from '../shared/url';

/** Valeurs brutes du formulaire (tout est du texte, comme dans le DOM). */
export interface FormInput {
    apiUrl: string;
    appUrl: string;
    domains: string;
    token: string;
    tokenPersistence: string;
    uiLanguage: string;
    activationModifier: string;
    maxResults: string;
    collectContext: boolean;
    urlMode: string;
    timeoutSeconds: string;
    feedbackEnabled: boolean;
    selectorBreadcrumb: string;
    selectorDialog: string;
    selectorSection: string;
    selectorIgnore: string;
    manualSearchUrlTemplate: string;
}

export interface FormResult {
    settings: Settings;
    errors: string[];
}

/** Valide le formulaire et produit des réglages normalisés ; les erreurs sont déjà traduites. */
export function buildSettingsFromForm(input: FormInput, lang: Lang, isValidSelector: (selector: string) => boolean): FormResult {
    const errors: string[] = [];

    const api = validateHttpUrl(input.apiUrl);
    if (!api.ok) {
        errors.push(t(lang, api.reason === 'empty' ? 'valApiUrlEmpty' : api.reason === 'insecure' ? 'valApiUrlInsecure' : 'valApiUrlInvalid'));
    }
    if (input.appUrl.trim() !== '' && !validateHttpUrl(input.appUrl).ok) {
        errors.push(t(lang, 'valAppUrlInvalid'));
    }

    const domains = parseDomainList(input.domains);
    if (domains.domains.length === 0 && domains.errors.length === 0) {
        errors.push(t(lang, 'valDomainsEmpty'));
    }
    for (const rejected of domains.errors) {
        const key =
            rejected.reason === 'tooBroad'
                ? 'valDomainTooBroad'
                : rejected.reason === 'insecure'
                  ? 'valDomainInsecure'
                  : 'valDomainInvalid';
        errors.push(t(lang, key, { entry: rejected.entry }));
    }

    if (input.token.trim() !== '' && !isPlausibleToken(input.token.trim())) {
        errors.push(t(lang, 'valToken'));
    }
    if (input.manualSearchUrlTemplate.trim() !== '' && !input.manualSearchUrlTemplate.includes('{query}')) {
        errors.push(t(lang, 'valTemplate'));
    }

    const selectorFields = [
        ['optSelBreadcrumb', input.selectorBreadcrumb],
        ['optSelDialog', input.selectorDialog],
        ['optSelSection', input.selectorSection],
        ['optSelIgnore', input.selectorIgnore],
    ] as const;
    for (const [label, value] of selectorFields) {
        if (value.trim() !== '' && !isValidSelector(value.trim())) {
            errors.push(t(lang, 'valSelector', { field: t(lang, label) }));
        }
    }

    const settings = normalizeSettings({
        apiUrl: input.apiUrl,
        appUrl: input.appUrl,
        allowedDomains: domains.domains,
        uiLanguage: input.uiLanguage,
        activationModifier: input.activationModifier,
        maxResults: input.maxResults,
        collectContext: input.collectContext,
        urlMode: input.urlMode,
        timeoutMs: Number(input.timeoutSeconds) * 1000,
        feedbackEnabled: input.feedbackEnabled,
        tokenPersistence: input.tokenPersistence,
        manualSearchUrlTemplate: input.manualSearchUrlTemplate.trim() || undefined,
        selectors: {
            breadcrumb: input.selectorBreadcrumb,
            dialog: input.selectorDialog,
            sectionTitle: input.selectorSection,
            ignore: input.selectorIgnore,
        },
    });
    return { settings, errors };
}

/** Origines à autoriser dans le navigateur : domaines MyAB + origine de l'API. */
export function requiredOriginPatterns(settings: Settings, domainPatterns: readonly string[]): string[] {
    const api = validateHttpUrl(settings.apiUrl);
    return [...new Set([...domainPatterns, ...(api.ok ? [api.matchPattern] : [])])];
}
