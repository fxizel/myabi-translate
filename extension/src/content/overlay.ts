import type { Lang, PanelResult, SearchRequest } from '../types';
import { errorMessage, languageName, statusLabel, t, type MessageKey } from '../shared/i18n';
import type { PanelErrorMessage, PanelLoadingMessage, PanelResultsMessage } from '../shared/messages';
import { OVERLAY_ATTRIBUTE } from './dom';
import { OVERLAY_CSS } from './overlayStyles';

export interface OverlayHandlers {
    onClose(): void;
    onRetry(request: SearchRequest): void;
    onOpenSettings(): void;
    onFeedback(request: SearchRequest, result: PanelResult): Promise<boolean>;
}

export interface OverlayOptions {
    /** « closed » en production (le JavaScript de MyAB ne peut pas lire l'overlay) ; « open » pour les tests. */
    shadowMode?: 'open' | 'closed';
}

const RETRYABLE = new Set(['network', 'timeout', 'rateLimited', 'server', 'http', 'invalidResponse']);
const SETTINGS_ERRORS = new Set(['notConfigured', 'unauthorized', 'forbidden', 'notFound', 'permission', 'domainNotAllowed']);
/** Les clics dans l'overlay ne doivent pas atteindre les écouteurs de MyAB (par exemple « clic extérieur ferme la modale »). */
const ISOLATED_EVENTS = ['mousedown', 'mouseup', 'click', 'dblclick', 'pointerdown', 'pointerup', 'touchstart', 'touchend'];

type Child = Node | string | null | undefined | false;

function create<K extends keyof HTMLElementTagNameMap>(
    doc: Document,
    tag: K,
    className = '',
    ...children: Child[]
): HTMLElementTagNameMap[K] {
    const element = doc.createElement(tag);
    if (className) {
        element.className = className;
    }
    for (const child of children) {
        if (child) {
            element.append(child);
        }
    }
    return element;
}

function applyStyles(root: ShadowRoot, css: string): void {
    try {
        const sheet = new CSSStyleSheet();
        sheet.replaceSync(css);
        root.adoptedStyleSheets = [sheet];
        return;
    } catch {
        // Environnement sans feuilles constructibles : repli sur un élément <style>.
    }
    const style = root.ownerDocument.createElement('style');
    style.textContent = css;
    root.appendChild(style);
}

/**
 * Surlignage de l'élément sélectionné (dans toutes les frames) et panneau de résultats (frame du haut).
 * Tout le contenu provenant de l'API est inséré via textContent / append : jamais de innerHTML.
 */
export class Overlay {
    private host: HTMLElement | null = null;
    private root: ShadowRoot | null = null;
    private panel: HTMLElement | null = null;
    private body: HTMLElement | null = null;
    private box: HTMLElement | null = null;
    private highlighted: Element | null = null;
    private frame = 0;
    private currentRequestId = '';
    private lang: Lang = 'fr';

    constructor(
        private readonly doc: Document,
        private readonly handlers: OverlayHandlers,
        private readonly options: OverlayOptions = {},
    ) {}

    isActive(): boolean {
        return this.panel !== null || this.highlighted !== null;
    }

    /** Vrai si l'événement vient de l'overlay (chemin composé, y compris à travers un Shadow DOM fermé). */
    isOverlayEvent(path: readonly EventTarget[]): boolean {
        return this.host !== null && path.includes(this.host);
    }

    /* ---- Surlignage ---- */

    highlight(element: Element): void {
        const root = this.ensureRoot();
        this.highlighted = element;
        if (!this.box) {
            this.box = create(this.doc, 'div', 'highlight');
        }
        if (!this.box.parentNode) {
            root.appendChild(this.box);
        }
        const view = this.doc.defaultView;
        view?.addEventListener('scroll', this.schedulePosition, true);
        view?.addEventListener('resize', this.schedulePosition);
        this.position();
    }

    clearHighlight(): void {
        const view = this.doc.defaultView;
        view?.removeEventListener('scroll', this.schedulePosition, true);
        view?.removeEventListener('resize', this.schedulePosition);
        if (this.frame && view) {
            view.cancelAnimationFrame?.(this.frame);
        }
        this.frame = 0;
        this.highlighted = null;
        this.box?.remove();
        this.box = null;
    }

    private readonly schedulePosition = (): void => {
        const view = this.doc.defaultView;
        if (this.frame || !view) {
            return;
        }
        const run = (): void => {
            this.frame = 0;
            this.position();
        };
        this.frame = view.requestAnimationFrame ? view.requestAnimationFrame(run) : view.setTimeout(run, 16);
    };

    private position(): void {
        if (!this.box || !this.highlighted) {
            return;
        }
        if (!this.highlighted.isConnected) {
            this.clearHighlight();
            return;
        }
        const rect = this.highlighted.getBoundingClientRect();
        const style = this.box.style;
        style.left = `${rect.left - 2}px`;
        style.top = `${rect.top - 2}px`;
        style.width = `${rect.width + 4}px`;
        style.height = `${rect.height + 4}px`;
    }

    /* ---- Panneau ---- */

    showLoading(message: PanelLoadingMessage): void {
        this.lang = message.lang;
        this.currentRequestId = message.requestId;
        this.render(
            message.request.text,
            create(this.doc, 'div', 'hint', create(this.doc, 'span', 'spinner'), t(this.lang, 'loading')),
        );
    }

    showResults(message: PanelResultsMessage): void {
        if (message.requestId !== this.currentRequestId) {
            return; // réponse d'une recherche déjà remplacée
        }
        this.lang = message.lang;
        const { lang } = this;
        const content = create(this.doc, 'div', '');
        content.style.display = 'contents';

        const bannerKey: Record<string, MessageKey> = {
            strong: 'stateStrong',
            multiple: 'stateMultiple',
            noExact: 'stateNoExact',
            none: 'stateNone',
        };
        const banner = create(this.doc, 'div', `banner ${message.state}`);
        banner.setAttribute('role', 'status');
        banner.textContent = t(lang, bannerKey[message.state] ?? 'stateNone');
        content.append(banner);

        if (message.state === 'noExact' && message.results.length > 0) {
            content.append(create(this.doc, 'div', 'hint', t(lang, 'approximateResults')));
        }
        if (message.results.length > 0) {
            const list = create(this.doc, 'ol', 'results');
            message.results.forEach((result, index) => list.append(this.renderResult(result, message, index)));
            content.append(list);
        }
        content.append(this.renderFooter(message.manualSearchUrl));
        this.render(message.request.text, content);
    }

    showError(message: PanelErrorMessage): void {
        if (message.error.kind === 'noText' || message.error.kind === 'contextInvalidated') {
            // Erreurs locales (aucune recherche en cours) : elles s'affichent toujours.
            this.currentRequestId = message.requestId;
        } else if (message.requestId !== this.currentRequestId) {
            return;
        }
        this.lang = message.lang;
        const { lang } = this;
        const content = create(this.doc, 'div', '');
        content.style.display = 'contents';
        const banner = create(this.doc, 'div', 'banner error');
        banner.setAttribute('role', 'alert');
        banner.textContent = errorMessage(lang, message.error);
        content.append(banner);

        const actions = create(this.doc, 'div', 'actions');
        const { request } = message;
        if (request && RETRYABLE.has(message.error.kind)) {
            const retry = create(this.doc, 'button', 'primary');
            retry.type = 'button';
            retry.textContent = t(lang, 'retry');
            retry.addEventListener('click', () => this.handlers.onRetry(request));
            actions.append(retry);
        }
        if (SETTINGS_ERRORS.has(message.error.kind)) {
            const settings = create(this.doc, 'button', 'primary');
            settings.type = 'button';
            settings.textContent = t(lang, 'openSettings');
            settings.addEventListener('click', () => this.handlers.onOpenSettings());
            actions.append(settings);
        }
        if (actions.childNodes.length > 0) {
            content.append(actions);
        }
        if (message.manualSearchUrl) {
            content.append(this.renderFooter(message.manualSearchUrl));
        }
        this.render(request?.text ?? '', content);
    }

    close(): void {
        this.clearHighlight();
        this.host?.remove();
        this.host = null;
        this.root = null;
        this.panel = null;
        this.body = null;
        this.currentRequestId = '';
    }

    /* ---- Construction du DOM ---- */

    private ensureRoot(): ShadowRoot {
        if (!this.host || !this.root) {
            const host = this.doc.createElement('myab-translation-inspector');
            host.setAttribute(OVERLAY_ATTRIBUTE, '');
            const root = host.attachShadow({ mode: this.options.shadowMode ?? 'closed' });
            applyStyles(root, OVERLAY_CSS);
            for (const type of ISOLATED_EVENTS) {
                host.addEventListener(type, (event) => event.stopPropagation());
            }
            this.host = host;
            this.root = root;
        }
        this.mount();
        return this.root;
    }

    /** Dans un <dialog> modal natif, tout le reste de la page est inerte : l'overlay doit y être inséré. */
    private mount(): void {
        if (!this.host) {
            return;
        }
        let parent: Element = this.doc.documentElement;
        try {
            parent = this.doc.querySelector('dialog:modal') ?? parent;
        } catch {
            // Pseudo-classe :modal non reconnue.
        }
        if (this.host.parentNode !== parent) {
            parent.appendChild(this.host);
        }
    }

    private ensurePanel(): HTMLElement {
        const root = this.ensureRoot();
        if (this.panel && this.body) {
            return this.body;
        }
        const close = create(this.doc, 'button', 'icon');
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', t(this.lang, 'close'));
        close.addEventListener('click', () => this.handlers.onClose());

        const header = create(this.doc, 'div', 'header', create(this.doc, 'span', 'title', 'MyAB Translation Inspector'), close);
        const body = create(this.doc, 'div', 'body');
        body.setAttribute('aria-live', 'polite');
        const panel = create(this.doc, 'section', 'panel', header, body);
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'MyAB Translation Inspector');
        root.appendChild(panel);
        this.panel = panel;
        this.body = body;
        return body;
    }

    private render(detectedText: string, content: Node): void {
        const body = this.ensurePanel();
        this.mount();
        body.replaceChildren();
        if (detectedText) {
            body.append(
                create(
                    this.doc,
                    'div',
                    '',
                    create(this.doc, 'div', 'label', t(this.lang, 'detectedText')),
                    create(this.doc, 'div', 'detected', detectedText),
                ),
            );
        }
        body.append(content);
    }

    private renderFooter(manualSearchUrl: string | null): HTMLElement {
        const footer = create(this.doc, 'div', 'footer');
        if (manualSearchUrl) {
            const link = create(this.doc, 'a', 'link');
            link.href = manualSearchUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.textContent = `${t(this.lang, 'manualSearch')} ↗`;
            footer.append(link);
        }
        return footer;
    }

    private renderResult(result: PanelResult, message: PanelResultsMessage, index: number): HTMLElement {
        const { lang } = this;
        const tier = result.score >= 0.9 ? 'high' : result.score >= 0.7 ? 'mid' : 'low';
        const card = create(this.doc, 'li', `card${index === 0 ? ' top' : ''}`);

        const head = create(this.doc, 'div', 'card-head', create(this.doc, 'span', `score ${tier}`, result.scoreLabel), create(this.doc, 'span', 'key', result.key));
        if (result.status) {
            const statusClass = result.status.toLowerCase().replace(/[^a-z0-9_-]/g, '-');
            head.append(create(this.doc, 'span', `chip ${statusClass}`, statusLabel(lang, result.status)));
        }
        card.append(head);

        const details = create(this.doc, 'dl', '');
        const row = (term: string, value: string): void => {
            if (value) {
                details.append(create(this.doc, 'dt', '', term), create(this.doc, 'dd', '', value));
            }
        };
        const targetLanguage = (result.targetLanguage || message.request.lang || 'fr').toUpperCase();
        row((result.sourceLanguage || '??').toUpperCase(), result.sourceText);
        row(targetLanguage, result.currentTranslation);
        if (result.proposedTranslation && result.proposedTranslation !== result.currentTranslation) {
            row(t(lang, 'proposal'), result.proposedTranslation);
        }
        row(t(lang, 'module'), result.module);
        card.append(details);

        const actions = create(this.doc, 'div', 'actions');
        actions.append(this.copyButton(t(lang, 'copyKey'), result.key));
        if (result.sourceText) {
            actions.append(this.copyButton(t(lang, 'copySource', { language: languageName(lang, result.sourceLanguage) }), result.sourceText));
        }
        if (result.openUrl) {
            const open = create(this.doc, 'a', 'button primary');
            open.href = result.openUrl;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            open.textContent = t(lang, 'openTranslation');
            actions.append(open);
        }
        if (message.feedbackEnabled) {
            actions.append(this.feedbackButton(message.request, result));
        }
        card.append(actions);
        return card;
    }

    private copyButton(label: string, value: string): HTMLButtonElement {
        const button = create(this.doc, 'button', '');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', () => {
            void this.copy(value).then((copied) => {
                button.textContent = t(this.lang, copied ? 'copied' : 'copyFailed');
                this.doc.defaultView?.setTimeout(() => {
                    button.textContent = label;
                }, 1500);
            });
        });
        return button;
    }

    private feedbackButton(request: SearchRequest, result: PanelResult): HTMLButtonElement {
        const label = t(this.lang, 'reportCorrect');
        const button = create(this.doc, 'button', '');
        button.type = 'button';
        button.textContent = label;
        button.addEventListener('click', () => {
            button.disabled = true;
            void this.handlers.onFeedback(request, result).then((sent) => {
                button.textContent = t(this.lang, sent ? 'reported' : 'reportFailed');
                button.disabled = sent;
                if (!sent) {
                    this.doc.defaultView?.setTimeout(() => {
                        button.textContent = label;
                    }, 2000);
                }
            });
        });
        return button;
    }

    private async copy(value: string): Promise<boolean> {
        try {
            await this.doc.defaultView?.navigator.clipboard.writeText(value);
            return true;
        } catch {
            // Presse-papiers asynchrone refusé : repli sur execCommand.
        }
        try {
            const area = this.doc.createElement('textarea');
            area.value = value;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            (this.root ?? this.doc.body).appendChild(area);
            area.select();
            const copied = this.doc.execCommand('copy');
            area.remove();
            return copied;
        } catch {
            return false;
        }
    }
}
