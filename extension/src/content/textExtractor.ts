import { hasMeaningfulText, normalizeText, truncate, uniqueStrings } from '../shared/text';
import {
    OVERLAY_ATTRIBUTE,
    closestComposed,
    isElementNode,
    isFormControl,
    isInsideOverlay,
    parentOf,
    safeMatches,
} from './dom';

export const DEFAULT_MAX_TEXT_LENGTH = 500;

/** Textes présents dans le DOM mais invisibles à l'écran (lecteurs d'écran). */
const DEFAULT_IGNORED = '.sr-only, .visually-hidden, .screenreader-only, .screen-reader-text, .sr-only-focusable';

/** Contenus jamais lus : scripts, médias, et surtout valeurs saisies ou listes d'options (données métier). */
const SKIPPED_TAGS = new Set([
    'script', 'style', 'noscript', 'template', 'svg', 'canvas', 'iframe', 'object', 'embed',
    'select', 'textarea', 'input', 'option', 'datalist', 'audio', 'video', 'math',
]);

/** Repli quand `getComputedStyle` ne renseigne pas `display` (environnements sans moteur de rendu). */
const BLOCK_TAGS = new Set([
    'address', 'article', 'aside', 'blockquote', 'br', 'caption', 'dd', 'details', 'dialog', 'div', 'dl', 'dt',
    'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li',
    'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'ul',
    'legend', 'body', 'html',
]);

const INTERACTIVE_TAGS = new Set(['a', 'button', 'label', 'summary', 'option']);
const INTERACTIVE_ROLES = new Set([
    'button', 'link', 'tab', 'menuitem', 'menuitemcheckbox', 'menuitemradio', 'option', 'treeitem', 'checkbox',
    'radio', 'switch', 'gridcell', 'cell', 'columnheader', 'rowheader',
]);

const TEXT_ATTRIBUTES = ['aria-label', 'title', 'data-original-title', 'data-bs-original-title', 'alt', 'placeholder'];

export interface ExtractOptions {
    /** Sélecteur CSS d'éléments à ignorer (réglage « Éléments à ignorer »). */
    ignoreSelector?: string;
    maxLength?: number;
}

export type TextSource = 'content' | 'value' | 'label' | 'attribute';

export interface ResolvedText {
    text: string;
    /** Autres lectures plausibles : texte de l'élément le plus profond, texte direct de l'élément. */
    alternatives: string[];
    source: TextSource;
    attribute?: string;
    /** Élément « logique » qui porte le texte (surligné, décrit dans le contexte). */
    container: Element;
    /** Élément réellement cliqué. */
    target: Element;
}

export type ResolveFailure = 'ignored' | 'empty' | 'decorative' | 'tooLong';
export type ResolveResult = { ok: true; value: ResolvedText } | { ok: false; reason: ResolveFailure };

/* ---- Visibilité et texte visible -------------------------------------------------------------------------------- */

function displayOf(element: Element): string {
    const view = element.ownerDocument.defaultView;
    const computed = view ? view.getComputedStyle(element).display : '';
    return computed || (BLOCK_TAGS.has(element.localName) ? 'block' : 'inline');
}

function isInlineDisplay(display: string): boolean {
    return display === 'inline' || display === 'contents';
}

export function isHidden(element: Element): boolean {
    if (element.getAttribute('aria-hidden') === 'true' || (element as HTMLElement).hidden === true) {
        return true;
    }
    const view = element.ownerDocument.defaultView;
    if (!view) {
        return false;
    }
    const style = view.getComputedStyle(element);
    return style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse';
}

function shouldSkip(element: Element, options: ExtractOptions): boolean {
    return (
        SKIPPED_TAGS.has(element.localName) ||
        element.hasAttribute(OVERLAY_ATTRIBUTE) ||
        safeMatches(element, DEFAULT_IGNORED) ||
        safeMatches(element, options.ignoreSelector) ||
        isHidden(element)
    );
}

/** Enfants « rendus » : contenu d'un Shadow DOM ouvert, nœuds distribués d'un <slot>. */
function renderedChildren(node: Node): Node[] {
    if (isElementNode(node)) {
        if (node.shadowRoot) {
            return Array.from(node.shadowRoot.childNodes);
        }
        if (node.localName === 'slot') {
            const assigned = (node as HTMLSlotElement).assignedNodes({ flatten: true });
            if (assigned.length) {
                return assigned;
            }
        }
    }
    return Array.from(node.childNodes);
}

/**
 * Texte visible d'un nœud, normalisé : sous-éléments inclus, masqués / décoratifs (aria-hidden, svg…) exclus,
 * valeurs de champs jamais lues. Les éléments non inline sont séparés par une espace.
 */
export function getVisibleText(root: Node, options: ExtractOptions = {}, budget = 2000): string {
    const parts: string[] = [];
    let size = 0;
    const walk = (node: Node): void => {
        if (size > budget) {
            return;
        }
        if (node.nodeType === 3) {
            const data = node.nodeValue ?? '';
            parts.push(data);
            size += data.length;
            return;
        }
        if (!isElementNode(node)) {
            if (node.nodeType === 11) {
                renderedChildren(node).forEach(walk);
            }
            return;
        }
        if (shouldSkip(node, options)) {
            return;
        }
        const separate = !isInlineDisplay(displayOf(node));
        if (separate) parts.push(' ');
        renderedChildren(node).forEach(walk);
        if (separate) parts.push(' ');
    };
    walk(root);
    return normalizeText(parts.join(''));
}

/** Texte des seuls nœuds texte enfants directs (sans les sous-éléments). */
export function getDirectText(element: Element): string {
    return normalizeText(
        Array.from(element.childNodes)
            .filter((node) => node.nodeType === 3)
            .map((node) => node.nodeValue ?? '')
            .join(' '),
    );
}

/* ---- Libellés de formulaire ------------------------------------------------------------------------------------- */

function textOfId(origin: Element, id: string): string {
    const root = origin.getRootNode() as Document | ShadowRoot;
    const referenced = typeof root.getElementById === 'function' ? root.getElementById(id) : null;
    return normalizeText(referenced?.textContent);
}

/** Libellé accessible d'un champ : aria-labelledby, <label>, puis aria-label. */
export function getFormLabel(control: Element): string {
    const labelledBy = control.getAttribute('aria-labelledby');
    if (labelledBy) {
        const text = normalizeText(
            labelledBy
                .split(/\s+/)
                .map((id) => textOfId(control, id))
                .join(' '),
        );
        if (hasMeaningfulText(text)) {
            return text;
        }
    }
    const labels = (control as HTMLInputElement).labels;
    if (labels && labels.length > 0) {
        const text = normalizeText(Array.from(labels).map((label) => getVisibleText(label)).join(' '));
        if (hasMeaningfulText(text)) {
            return text;
        }
    }
    return normalizeText(control.getAttribute('aria-label'));
}

/* ---- Résolution du texte cliqué --------------------------------------------------------------------------------- */

function isInteractive(element: Element): boolean {
    if (INTERACTIVE_TAGS.has(element.localName)) {
        return true;
    }
    const role = element.getAttribute('role');
    return role !== null && INTERACTIVE_ROLES.has(role);
}

/** Limite « naturelle » d'un texte : élément interactif ou non inline. On ne la franchit pas en remontant. */
function isTextBoundary(element: Element): boolean {
    return isInteractive(element) || !isInlineDisplay(displayOf(element));
}

function countTextBearingChildren(parent: Element, options: ExtractOptions): number {
    let count = 0;
    for (const child of Array.from(parent.children)) {
        if (hasMeaningfulText(getVisibleText(child, options, 200))) {
            count += 1;
            if (count > 1) {
                break;
            }
        }
    }
    return count;
}

/**
 * Remonte depuis un élément inline (<b>, <span>…) jusqu'à l'élément qui porte la phrase entière
 * (« Enregistrer <b>maintenant</b> » → le bouton), mais s'arrête dès que le parent contient plusieurs
 * libellés indépendants (<td><span>A</span><span>B</span></td> reste sur le <span> cliqué).
 */
function climbInline(start: Element, options: ExtractOptions): Element {
    let current = start;
    for (let step = 0; step < 6; step += 1) {
        if (isTextBoundary(current)) {
            break;
        }
        const parent = parentOf(current);
        if (!parent || parent === current.ownerDocument.body || parent === current.ownerDocument.documentElement) {
            break;
        }
        if (!isInteractive(parent) && countTextBearingChildren(parent, options) > 1) {
            break;
        }
        current = parent;
    }
    return current;
}

/** Élément sans texte (icône, svg) : cherche, sur 3 niveaux, un ancêtre court qui porte un libellé unique. */
function findTextBearingAncestor(start: Element, options: ExtractOptions): Element | null {
    let current = parentOf(start);
    for (let step = 0; step < 3 && current; step += 1) {
        if (current === current.ownerDocument.body || current === current.ownerDocument.documentElement) {
            return null;
        }
        const text = getVisibleText(current, options, 400);
        if (hasMeaningfulText(text) && text.length <= 120 && countTextBearingChildren(current, options) <= 1) {
            return current;
        }
        current = parentOf(current);
    }
    return null;
}

function attributeText(start: Element): { text: string; attribute: string; element: Element } | null {
    let current: Element | null = start;
    for (let step = 0; step < 4 && current; step += 1) {
        const labelledBy = current.getAttribute('aria-labelledby');
        if (labelledBy) {
            const text = normalizeText(
                labelledBy
                    .split(/\s+/)
                    .map((id) => textOfId(current as Element, id))
                    .join(' '),
            );
            if (hasMeaningfulText(text)) {
                return { text, attribute: 'aria-labelledby', element: current };
            }
        }
        for (const attribute of TEXT_ATTRIBUTES) {
            const text = normalizeText(current.getAttribute(attribute));
            if (hasMeaningfulText(text)) {
                return { text, attribute, element: current };
            }
        }
        current = parentOf(current);
    }
    return null;
}

function resolveControl(control: Element, maxLength: number, target: Element): ResolveResult {
    const type = (control.getAttribute('type') ?? 'text').toLowerCase();
    if (control.localName === 'input' && type === 'hidden') {
        return { ok: false, reason: 'ignored' };
    }
    let text = '';
    let source: TextSource = 'label';
    let attribute: string | undefined;

    if (control.localName === 'input' && ['button', 'submit', 'reset'].includes(type)) {
        // La valeur d'un bouton est son libellé ; celle d'un champ de saisie n'est JAMAIS lue.
        text = normalizeText((control as HTMLInputElement).value);
        source = 'value';
    } else if (control.localName === 'input' && type === 'image') {
        text = normalizeText(control.getAttribute('alt'));
        source = 'attribute';
        attribute = 'alt';
    }
    if (!hasMeaningfulText(text)) {
        text = getFormLabel(control);
        source = 'label';
        attribute = undefined;
    }
    if (!hasMeaningfulText(text)) {
        for (const name of ['title', 'placeholder']) {
            const value = normalizeText(control.getAttribute(name));
            if (hasMeaningfulText(value)) {
                text = value;
                source = 'attribute';
                attribute = name;
                break;
            }
        }
    }
    if (!hasMeaningfulText(text)) {
        return { ok: false, reason: 'empty' };
    }
    const value: ResolvedText = { text: truncate(text, maxLength), alternatives: [], source, container: control, target };
    if (attribute) {
        value.attribute = attribute;
    }
    return { ok: true, value };
}

/**
 * Détermine le texte à rechercher pour l'élément cliqué.
 * Ordre : champ de formulaire (libellé) → texte visible de l'élément logique → attributs (aria-label, title, alt…).
 */
export function resolveText(target: Element, options: ExtractOptions = {}): ResolveResult {
    const maxLength = options.maxLength ?? DEFAULT_MAX_TEXT_LENGTH;
    if (isInsideOverlay(target) || closestComposed(target, options.ignoreSelector ?? '')) {
        return { ok: false, reason: 'ignored' };
    }
    if (isFormControl(target)) {
        return resolveControl(target, maxLength, target);
    }

    const own = getVisibleText(target, options);
    let container: Element = target;
    if (hasMeaningfulText(own)) {
        container = climbInline(target, options);
    } else {
        container = findTextBearingAncestor(target, options) ?? target;
    }
    let text = container === target ? own : getVisibleText(container, options);
    if (text.length > maxLength) {
        if (hasMeaningfulText(own) && own.length <= maxLength) {
            text = own;
            container = target;
        } else if (countTextBearingChildren(container, options) <= 1) {
            // Un seul long paragraphe : on le tronque. Un bloc structurel (page, section…) est refusé.
            text = truncate(text, maxLength);
        } else {
            return { ok: false, reason: 'tooLong' };
        }
    }

    if (hasMeaningfulText(text)) {
        const candidates = [own, getDirectText(target), getDirectText(container)].filter(
            (candidate) => hasMeaningfulText(candidate) && candidate.length <= maxLength,
        );
        return {
            ok: true,
            value: {
                text,
                alternatives: uniqueStrings(candidates, 3, [text]),
                source: 'content',
                container,
                target,
            },
        };
    }

    const fallback = attributeText(target);
    if (fallback) {
        return {
            ok: true,
            value: {
                text: truncate(fallback.text, maxLength),
                alternatives: [],
                source: 'attribute',
                attribute: fallback.attribute,
                container: fallback.element,
                target,
            },
        };
    }
    return { ok: false, reason: text || own ? 'decorative' : 'empty' };
}
