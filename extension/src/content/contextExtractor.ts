import type { ContextPayload, Lang, SearchRequest, SelectorSettings, UrlMode } from '../types';
import { excerptAround, hasMeaningfulText, normalizeText, truncate } from '../shared/text';
import { sanitizeUrl } from '../shared/url';
import { closestComposed, isFormControl, parentOf, safeMatches, safeQuery, safeQueryAll } from './dom';
import {
    getFormLabel,
    getVisibleText,
    isHidden,
    type ExtractOptions,
    type ResolvedText,
} from './textExtractor';

export interface ContextSettings {
    collectContext: boolean;
    urlMode: UrlMode;
    selectors: SelectorSettings;
    maxResults: number;
}

const SURROUNDING_MAX = 500;
const PARENT_TEXT_MAX = 300;
const SIBLING_TEXT_MAX = 80;
const HEADING_SELECTOR = 'h1, h2, h3, h4, h5, h6, [role="heading"], legend, caption, summary';
const DIALOG_SELECTOR = 'dialog[open], [role="dialog"], [role="alertdialog"], [aria-modal="true"]';
/** Par ordre de priorité, chacun essayé séparément. */
const DIALOG_TITLE_SELECTORS = ['.modal-title', '[class*="dialog-title"]', '[role="heading"]', 'h1', 'h2', 'h3', 'h4', 'header'];
/** Essayés un par un : un sélecteur non géré par un moteur n'invalide pas les autres. */
const BREADCRUMB_SELECTORS = [
    '[class*="breadcrumb"]',
    'nav[aria-label*="Ariane"]',
    'nav[aria-label*="readcrumb"]',
    'nav[aria-label*="rotkrumen"]',
    'nav[aria-label*="ariane" i]',
    '[data-testid*="breadcrumb" i]',
];
const REGION_SELECTOR = 'section, [role="region"], [role="group"], [role="tabpanel"], [role="form"], form';

function extractOptions(selectors: SelectorSettings): ExtractOptions {
    return selectors.ignore ? { ignoreSelector: selectors.ignore } : {};
}

/** « fr-CH » → « fr » : langue du libellé, d'après le lang le plus proche de l'élément. */
export function pageLanguage(element: Element): string {
    const owner = closestComposed(element, '[lang]');
    const raw = owner?.getAttribute('lang') || element.ownerDocument.documentElement.lang || '';
    return (raw.split(/[-_]/)[0] ?? '').toLowerCase().slice(0, 12);
}

/** Cellule de données : on ne remonte jamais au contenu de la ligne (données métier). */
function isDataCell(element: Element): boolean {
    return (
        closestComposed(element, 'td, [role="gridcell"], [role="cell"]') !== null ||
        closestComposed(element, 'tbody th, [role="rowheader"]') !== null
    );
}

function collectDataAttributes(elements: readonly Element[]): Record<string, string> {
    const out: Record<string, string> = {};
    for (const element of elements) {
        for (const attribute of Array.from(element.attributes)) {
            if (!attribute.name.startsWith('data-') || attribute.name.startsWith('data-myab-ti')) {
                continue;
            }
            if (attribute.name in out || Object.keys(out).length >= 20) {
                continue;
            }
            out[attribute.name] = truncate(normalizeText(attribute.value), 100);
        }
    }
    return out;
}

function collectAriaAttributes(element: Element): Record<string, string> {
    const out: Record<string, string> = {};
    for (const attribute of Array.from(element.attributes)) {
        if (!attribute.name.startsWith('aria-') || ['aria-label', 'aria-hidden'].includes(attribute.name)) {
            continue;
        }
        if (Object.keys(out).length >= 10) {
            break;
        }
        out[attribute.name] = truncate(normalizeText(attribute.value), 100);
    }
    return out;
}

function associatedControl(container: Element, target: Element): Element | null {
    for (const candidate of [target, container]) {
        if (isFormControl(candidate)) {
            return candidate;
        }
    }
    const label = closestComposed(container, 'label') as HTMLLabelElement | null;
    return label?.control ?? null;
}

function nearestRegionLabel(element: Element, options: ExtractOptions): string {
    if (element.localName === 'fieldset') {
        const legend = Array.from(element.children).find((child) => child.localName === 'legend');
        const text = legend ? getVisibleText(legend, options, 300) : '';
        if (hasMeaningfulText(text)) {
            return truncate(text, 120);
        }
    }
    if (!element.matches(REGION_SELECTOR) && !element.matches('[role="tabpanel"]')) {
        return '';
    }
    const labelledBy = element.getAttribute('aria-labelledby');
    if (labelledBy) {
        const root = element.getRootNode() as Document | ShadowRoot;
        const text = normalizeText(
            labelledBy
                .split(/\s+/)
                .map((id) => root.getElementById?.(id)?.textContent ?? '')
                .join(' '),
        );
        if (hasMeaningfulText(text)) {
            return truncate(text, 120);
        }
    }
    const label = normalizeText(element.getAttribute('aria-label'));
    return hasMeaningfulText(label) ? truncate(label, 120) : '';
}

/** Dernier titre qui précède la branche de l'élément, en remontant (frères précédents puis région étiquetée). */
function findSectionTitle(element: Element, customSelector: string, options: ExtractOptions): string {
    const headingsIn = (root: Element): Element[] => [
        ...safeQueryAll(root, HEADING_SELECTOR),
        ...safeQueryAll(root, customSelector),
    ];
    const isHeading = (candidate: Element): boolean =>
        safeMatches(candidate, HEADING_SELECTOR) || safeMatches(candidate, customSelector);

    let branch: Element | null = element;
    for (let hops = 0; branch && hops < 12; hops += 1) {
        for (let sibling = branch.previousElementSibling; sibling; sibling = sibling.previousElementSibling) {
            // Le sibling est lui-même un titre, ou un bandeau court qui en contient un (le plus proche en dernier).
            const candidates = isHeading(sibling) ? [sibling] : headingsIn(sibling).reverse();
            for (const heading of candidates) {
                if (isHidden(heading)) {
                    continue;
                }
                const text = getVisibleText(heading, options, 300);
                const compact = heading === sibling || getVisibleText(sibling, options, 400).length <= 150;
                if (hasMeaningfulText(text) && compact) {
                    return truncate(text, 120);
                }
            }
        }
        const parent: Element | null = parentOf(branch);
        if (!parent) {
            break;
        }
        const label = nearestRegionLabel(parent, options);
        if (label) {
            return label;
        }
        branch = parent;
    }
    return '';
}

function dialogTitle(dialog: Element, options: ExtractOptions): string {
    const labelledBy = dialog.getAttribute('aria-labelledby');
    if (labelledBy) {
        const root = dialog.getRootNode() as Document | ShadowRoot;
        const text = normalizeText(
            labelledBy
                .split(/\s+/)
                .map((id) => root.getElementById?.(id)?.textContent ?? '')
                .join(' '),
        );
        if (hasMeaningfulText(text)) {
            return truncate(text, 120);
        }
    }
    const label = normalizeText(dialog.getAttribute('aria-label'));
    if (hasMeaningfulText(label)) {
        return truncate(label, 120);
    }
    for (const selector of DIALOG_TITLE_SELECTORS) {
        for (const heading of safeQueryAll(dialog, selector)) {
            const text = getVisibleText(heading, options, 300);
            if (hasMeaningfulText(text)) {
                return truncate(text, 120);
            }
        }
    }
    return '';
}

function findDialog(element: Element, selectors: SelectorSettings): Element | null {
    const list = selectors.dialog ? [DIALOG_SELECTOR, selectors.dialog] : [DIALOG_SELECTOR];
    const ancestor = closestComposed(element, list);
    if (ancestor) {
        return ancestor;
    }
    // Modal actif ailleurs dans le document (clic sur un élément hors de la boîte de dialogue).
    const doc = element.ownerDocument;
    return (
        [safeQuery(doc, 'dialog[open]'), safeQuery(doc, '[aria-modal="true"]')].find(
            (candidate) => candidate !== null && !isHidden(candidate),
        ) ?? null
    );
}

function findBreadcrumbs(doc: Document, selectors: SelectorSettings, options: ExtractOptions): string[] {
    const list = selectors.breadcrumb ? [selectors.breadcrumb, ...BREADCRUMB_SELECTORS] : BREADCRUMB_SELECTORS;
    for (const selector of list) {
        const container = safeQueryAll(doc, selector).find((candidate) => !isHidden(candidate));
        if (!container) {
            continue;
        }
        const items = safeQueryAll(container, 'li');
        const nodes = items.length ? items : safeQueryAll(container, 'a, [aria-current], span');
        const texts: string[] = [];
        for (const node of nodes) {
            const text = truncate(getVisibleText(node, options, 200), 60);
            if (hasMeaningfulText(text) && !texts.includes(text)) {
                texts.push(text);
            }
            if (texts.length >= 8) {
                break;
            }
        }
        if (texts.length) {
            return texts;
        }
    }
    return [];
}

/** En-tête de colonne d'une cellule de tableau (attribut `headers`, sinon cellule d'en-tête de même rang). */
function findTableColumn(element: Element, options: ExtractOptions): string {
    const cell = closestComposed(element, 'td, th');
    if (!cell) {
        return '';
    }
    const root = cell.getRootNode() as Document | ShadowRoot;
    const headerIds = (cell.getAttribute('headers') ?? '').split(/\s+/).filter(Boolean);
    if (headerIds.length) {
        const text = normalizeText(headerIds.map((id) => root.getElementById?.(id)?.textContent ?? '').join(' '));
        if (hasMeaningfulText(text)) {
            return truncate(text, 80);
        }
    }
    const table = closestComposed(cell, 'table') as HTMLTableElement | null;
    const index = (cell as HTMLTableCellElement).cellIndex;
    if (!table || typeof index !== 'number' || index < 0) {
        return '';
    }
    const headerRows = table.tHead ? Array.from(table.tHead.rows) : Array.from(table.rows).slice(0, 1);
    const headerRow = headerRows[headerRows.length - 1];
    const header = headerRow?.cells[index];
    if (!header || header === cell) {
        return '';
    }
    const text = getVisibleText(header, options, 200);
    return hasMeaningfulText(text) ? truncate(text, 80) : '';
}

function neighbourTexts(container: Element, text: string, options: ExtractOptions): string[] {
    const before: string[] = [];
    const after: string[] = [];
    const read = (sibling: Element): string => {
        const value = truncate(getVisibleText(sibling, options, 200), SIBLING_TEXT_MAX);
        return hasMeaningfulText(value) && value !== text ? value : '';
    };
    for (let sibling = container.previousElementSibling; sibling && before.length < 3; sibling = sibling.previousElementSibling) {
        const value = read(sibling);
        if (value) before.unshift(value);
    }
    for (let sibling = container.nextElementSibling; sibling && after.length < 3; sibling = sibling.nextElementSibling) {
        const value = read(sibling);
        if (value) after.push(value);
    }
    return [...before, ...after];
}

/** Premier ancêtre qui apporte du texte en plus du libellé, jamais la page entière. */
function surroundingTextOf(container: Element, text: string, options: ExtractOptions): string {
    const doc = container.ownerDocument;
    let node = parentOf(container);
    let last = '';
    for (let hops = 0; node && hops < 4; hops += 1) {
        if (node === doc.body || node === doc.documentElement) {
            break;
        }
        const candidate = getVisibleText(node, options, 1200);
        last = candidate;
        if (candidate.length >= text.length + 30) {
            break;
        }
        node = parentOf(node);
    }
    return last ? excerptAround(last, text, SURROUNDING_MAX) : '';
}

/**
 * Construit le contexte envoyé à l'API. Jamais de HTML, jamais de valeur de champ de saisie.
 * Avec `collectContext` désactivé : texte, URL, titre et langue uniquement.
 */
export function buildContext(resolved: ResolvedText, settings: ContextSettings): ContextPayload {
    const { container, target, text } = resolved;
    const doc = container.ownerDocument;
    const options = extractOptions(settings.selectors);

    const payload: ContextPayload = {
        text,
        pageUrl: sanitizeUrl(doc.location?.href ?? '', settings.urlMode),
        pageTitle: truncate(normalizeText(doc.title), 200),
        surroundingText: '',
        elementTag: '',
        elementId: '',
        elementClasses: [],
        ariaLabel: '',
        title: '',
        dataAttributes: {},
        lang: pageLanguage(container),
    };
    if (!settings.collectContext) {
        return payload;
    }

    payload.elementTag = container.localName;
    payload.elementId = truncate(container.id, 100);
    payload.elementClasses = Array.from(container.classList)
        .map((name) => truncate(name, 60))
        .slice(0, 20);
    payload.ariaLabel = truncate(normalizeText(container.getAttribute('aria-label')), 200);
    payload.title = truncate(
        normalizeText(container.getAttribute('title') ?? container.getAttribute('data-original-title')),
        200,
    );
    payload.dataAttributes = collectDataAttributes(target === container ? [container] : [container, target]);
    if (resolved.alternatives.length) {
        payload.alternativeTexts = resolved.alternatives;
    }
    const role = container.getAttribute('role');
    if (role) payload.role = truncate(role, 40);
    const ariaAttributes = collectAriaAttributes(container);
    if (Object.keys(ariaAttributes).length) payload.ariaAttributes = ariaAttributes;

    const control = associatedControl(container, target);
    if (control) {
        const label = getFormLabel(control);
        if (hasMeaningfulText(label)) payload.formLabel = truncate(label, 200);
        const name = control.getAttribute('name');
        if (name) payload.elementName = truncate(name, 100);
        if (control.localName === 'input') {
            payload.inputType = (control.getAttribute('type') ?? 'text').toLowerCase();
        }
    }

    const cell = isDataCell(container);
    if (!cell) {
        const parent = parentOf(container);
        if (parent && parent !== doc.body && parent !== doc.documentElement) {
            const parentText = getVisibleText(parent, options, 1200);
            if (hasMeaningfulText(parentText)) {
                payload.parentText = excerptAround(parentText, text, PARENT_TEXT_MAX);
            }
        }
        const siblings = neighbourTexts(container, text, options);
        if (siblings.length) payload.siblingTexts = siblings;
        payload.surroundingText = surroundingTextOf(container, text, options);
    } else {
        payload.surroundingText = '';
    }

    const column = findTableColumn(container, options);
    if (column) payload.tableColumn = column;

    const section = findSectionTitle(container, settings.selectors.sectionTitle, options);
    if (section) payload.sectionTitle = section;

    const dialog = findDialog(container, settings.selectors);
    if (dialog) {
        payload.inDialog = true;
        const title = dialogTitle(dialog, options);
        if (title) payload.dialogTitle = title;
    }

    const breadcrumbs = findBreadcrumbs(doc, settings.selectors, options);
    if (breadcrumbs.length) payload.breadcrumbs = breadcrumbs;
    return payload;
}

export function buildSearchRequest(resolved: ResolvedText, settings: ContextSettings, uiLanguage: Lang): SearchRequest {
    return { ...buildContext(resolved, settings), maxResults: settings.maxResults, uiLanguage };
}
