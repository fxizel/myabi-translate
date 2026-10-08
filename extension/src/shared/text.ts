const INVISIBLE = /[­​-‏⁠‪-‮﻿]/g;
/** Glyphes de polices d'icônes (zone d'usage privé) : jamais du texte lisible. */
const ICON_FONT = /[-]/g;
const MEANINGFUL = /[\p{L}\p{N}]/u;
const COMBINING_MARKS = /\p{M}/gu;

/**
 * Nettoie un texte affiché : Unicode NFC, caractères invisibles et glyphes d'icônes retirés,
 * retours à la ligne / tabulations / espaces multiples réduits à une seule espace, trim.
 */
export function normalizeText(input: string | null | undefined): string {
    if (!input) {
        return '';
    }
    return input.normalize('NFC').replace(INVISIBLE, '').replace(ICON_FONT, ' ').replace(/\s+/g, ' ').trim();
}

/** Vrai si le texte contient au moins une lettre ou un chiffre (sinon : décoratif, ex. « » », « × », « … »). */
export function hasMeaningfulText(text: string): boolean {
    return MEANINGFUL.test(text);
}

/** Coupe à `max` caractères (de préférence à une limite de mot) et ajoute « … ». */
export function truncate(text: string, max: number): string {
    if (text.length <= max) {
        return text;
    }
    let cut = max;
    const code = text.charCodeAt(cut - 1);
    if (code >= 0xd800 && code <= 0xdbff) {
        cut -= 1;
    }
    let slice = text.slice(0, cut);
    const lastSpace = slice.lastIndexOf(' ');
    if (lastSpace > max * 0.6) {
        slice = slice.slice(0, lastSpace);
    }
    return `${slice.trimEnd()}…`;
}

/** Extrait d'au plus `max` caractères centré sur `needle` dans `full`. */
export function excerptAround(full: string, needle: string, max: number): string {
    if (full.length <= max) {
        return full;
    }
    const index = needle ? full.indexOf(needle) : -1;
    if (index < 0) {
        return truncate(full, max);
    }
    const room = Math.max(0, max - needle.length);
    let start = Math.max(0, index - Math.floor(room / 2));
    const end = Math.min(full.length, start + max);
    start = Math.max(0, end - max);
    let slice = full.slice(start, end).trim();
    if (start > 0) {
        slice = `…${slice}`;
    }
    if (end < full.length) {
        slice = `${slice}…`;
    }
    return slice;
}

/** Clé de comparaison : minuscules, sans accents, apostrophes typographiques unifiées. */
export function searchKey(input: string | null | undefined): string {
    return normalizeText(input)
        .toLowerCase()
        .normalize('NFD')
        .replace(COMBINING_MARKS, '')
        .replace(/[‘’‛′`´]/g, "'")
        .replace(/\s+/g, ' ');
}

/** Valeurs uniques (ordre conservé), vides ignorées, bornées à `limit`. */
export function uniqueStrings(values: readonly string[], limit: number, exclude: readonly string[] = []): string[] {
    const seen = new Set(exclude);
    const out: string[] = [];
    for (const value of values) {
        if (value && !seen.has(value)) {
            seen.add(value);
            out.push(value);
            if (out.length >= limit) {
                break;
            }
        }
    }
    return out;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** Chaîne bornée et normalisée ; tout autre type donne ''. */
export function cleanString(value: unknown, max: number): string {
    return typeof value === 'string' ? truncate(normalizeText(value), max) : '';
}
