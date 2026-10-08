/** Attribut posé sur l'élément hôte de l'overlay : il n'est jamais inspecté ni lu. */
export const OVERLAY_ATTRIBUTE = 'data-myab-ti-root';

/** Évite `instanceof Element`, fragile entre mondes JavaScript (content script / page) et entre frames. */
export function isElementNode(node: Node | null | undefined): node is Element {
    return !!node && node.nodeType === 1;
}

export function safeMatches(element: Element, selector: string | undefined): boolean {
    if (!selector) {
        return false;
    }
    try {
        return element.matches(selector);
    } catch {
        return false;
    }
}

export function safeQuery(root: ParentNode, selector: string | undefined): Element | null {
    if (!selector) {
        return null;
    }
    try {
        return root.querySelector(selector);
    } catch {
        return null;
    }
}

export function safeQueryAll(root: ParentNode, selector: string | undefined): Element[] {
    if (!selector) {
        return [];
    }
    try {
        return Array.from(root.querySelectorAll(selector));
    } catch {
        return [];
    }
}

/** Parent élément, en franchissant la frontière d'un Shadow DOM (retourne alors l'hôte). */
export function parentOf(element: Element): Element | null {
    if (element.parentElement) {
        return element.parentElement;
    }
    const root = element.getRootNode();
    return root.nodeType === 11 && 'host' in root ? (root as ShadowRoot).host : null;
}

/**
 * `closest` qui traverse les Shadow DOM. Plusieurs sélecteurs possibles : un sélecteur invalide
 * (par exemple saisi dans les options) est ignoré sans désactiver les autres.
 */
export function closestComposed(start: Element | null, selectors: string | readonly string[]): Element | null {
    const list = typeof selectors === 'string' ? [selectors] : selectors;
    let current = start;
    while (current) {
        if (list.some((selector) => safeMatches(current as Element, selector))) {
            return current;
        }
        current = parentOf(current);
    }
    return null;
}

export function isFormControl(element: Element): boolean {
    return element.localName === 'input' || element.localName === 'select' || element.localName === 'textarea';
}

export function isInsideOverlay(element: Element): boolean {
    return closestComposed(element, `[${OVERLAY_ATTRIBUTE}]`) !== null;
}
