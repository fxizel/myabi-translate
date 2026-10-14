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

/** Nombre de caractères Unicode (les paires UTF-16 comptent pour un). */
export function characterLength(text: string): number {
    return Array.from(text).length;
}

/** Borne le résultat final, ellipse comprise, sans couper une paire UTF-16. */
export function truncate(text: string, max: number): string {
    const characters = Array.from(text);
    if (characters.length <= max) return text;
    if (max <= 0) return '';
    let slice = characters.slice(0, max - 1).join('');
    const lastSpace = slice.lastIndexOf(' ');
    if (lastSpace > slice.length * 0.6) slice = slice.slice(0, lastSpace);
    return slice.trimEnd() + '…';
}

/** Extrait Unicode borné, ellipses comprises. */
export function excerptAround(full: string, needle: string, max: number): string {
    const characters = Array.from(full);
    if (characters.length <= max) return full;
    const index = needle ? full.indexOf(needle) : -1;
    if (index < 0 || characterLength(needle) >= max - 2) return truncate(full, max);
    const needleIndex = characterLength(full.slice(0, index));
    const room = Math.max(0, max - 2 - characterLength(needle));
    const start = Math.max(0, needleIndex - Math.floor(room / 2));
    const end = Math.min(characters.length, start + max - 2);
    return (start > 0 ? '…' : '') + characters.slice(start, end).join('').trim() + (end < characters.length ? '…' : '');
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
