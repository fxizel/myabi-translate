import type { ActivationModifier, Settings } from '../types';
import { getApi, type ExtensionApi, type MessageSender } from '../shared/browserApi';
import { isHostAllowed } from '../shared/domains';
import { resolveLanguage } from '../shared/i18n';
import { hasType } from '../shared/messages';
import { isRecord } from '../shared/text';
import type { PanelErrorMessage, PanelLoadingMessage, PanelResultsMessage } from '../shared/messages';
import { DEFAULT_SETTINGS, ENABLED_KEY, SETTINGS_KEY, loadEnabled, loadSettings } from '../shared/settings';
import { buildSearchRequest } from './contextExtractor';
import { isElementNode } from './dom';
import { Overlay } from './overlay';
import { resolveText } from './textExtractor';

/** Événements souris neutralisés pendant un Alt+clic, pour que MyAB ne réagisse pas (lien, bouton, menu, drag…). */
const INTERCEPTED_EVENTS = ['pointerdown', 'mousedown', 'pointerup', 'mouseup', 'click', 'dblclick'] as const;

export function modifierMatches(event: MouseEvent, modifier: ActivationModifier): boolean {
    if (!event.altKey || event.metaKey) {
        return false;
    }
    switch (modifier) {
        case 'altShift':
            return event.shiftKey && !event.ctrlKey;
        case 'altCtrl':
            return event.ctrlKey && !event.shiftKey;
        default:
            return !event.shiftKey && !event.ctrlKey;
    }
}

export class Inspector {
    private enabled = false;
    private settings: Settings = DEFAULT_SETTINGS;
    private readonly overlay: Overlay;
    private readonly frameToken = Math.random().toString(36).slice(2, 8);
    private sequence = 0;
    private readonly isTopFrame = window.self === window.top;

    constructor(
        private readonly api: ExtensionApi,
        private readonly doc: Document = document,
        /** `requireTrusted: false` n'existe que pour les tests : jsdom ne produit que des événements non « de confiance ». */
        private readonly options: { requireTrusted?: boolean } = {},
    ) {
        this.overlay = new Overlay(doc, {
            onClose: () => this.closeEverywhere(),
            onRetry: (request) => void this.send({ type: 'search', requestId: this.nextRequestId(), request }),
            onOpenSettings: () => void this.send({ type: 'openOptions' }),
            onFeedback: async (request, result) => {
                const reply = await this.send({ type: 'feedback', request, resultId: result.id, key: result.key });
                return isRecord(reply) && reply.ok === true;
            },
        });
    }

    async start(): Promise<void> {
        await this.refreshState();
        const view = this.doc.defaultView ?? window;
        for (const type of INTERCEPTED_EVENTS) {
            view.addEventListener(type, this.onPointerEvent, true);
        }
        view.addEventListener('keydown', this.onKeyDown, true);
        this.api.storage.onChanged.addListener(this.onStorageChanged);
        this.api.runtime.onMessage.addListener(this.onRuntimeMessage);
    }

    /** Retire tous les écouteurs et l'overlay : MyAB retrouve son fonctionnement normal. */
    stop(): void {
        const view = this.doc.defaultView ?? window;
        for (const type of INTERCEPTED_EVENTS) {
            view.removeEventListener(type, this.onPointerEvent, true);
        }
        view.removeEventListener('keydown', this.onKeyDown, true);
        this.api.storage.onChanged.removeListener(this.onStorageChanged);
        this.api.runtime.onMessage.removeListener(this.onRuntimeMessage);
        this.overlay.close();
    }

    /** Vrai seulement si le mode est activé ET si l'hôte courant est dans la liste des domaines autorisés. */
    private isActive(): boolean {
        return (
            this.enabled &&
            isHostAllowed(this.doc.location.hostname, this.doc.location.protocol, this.settings.allowedDomains)
        );
    }

    private async refreshState(): Promise<void> {
        try {
            this.settings = await loadSettings(this.api);
            this.enabled = await loadEnabled(this.api);
        } catch {
            this.enabled = false;
        }
        if (!this.isActive()) {
            this.overlay.close();
        }
    }

    private readonly onStorageChanged = (changes: Record<string, unknown>, area: string): void => {
        if (area === 'local' && (ENABLED_KEY in changes || SETTINGS_KEY in changes)) {
            void this.refreshState();
        }
    };

    private readonly onPointerEvent = (event: Event): void => {
        const mouse = event as MouseEvent;
        const trusted = mouse.isTrusted || this.options.requireTrusted === false;
        if (!this.isActive() || !trusted || mouse.button !== 0) {
            return;
        }
        if (!modifierMatches(mouse, this.settings.activationModifier)) {
            return;
        }
        const path = mouse.composedPath();
        if (this.overlay.isOverlayEvent(path)) {
            return;
        }
        // Capture sur window, enregistrée à document_start : on passe avant les écouteurs de la page (React, Angular, Vue…).
        mouse.preventDefault();
        mouse.stopImmediatePropagation();
        if (mouse.type === 'click') {
            this.inspect(path);
        }
    };

    private readonly onKeyDown = (event: KeyboardEvent): void => {
        if (event.key === 'Escape' && this.overlay.isActive()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            this.closeEverywhere();
        }
    };

    private readonly onRuntimeMessage = (
        message: unknown,
        _sender: MessageSender,
        sendResponse: (response?: unknown) => void,
    ): boolean | void => {
        if (!hasType(message)) {
            return undefined;
        }
        switch (message.type) {
            case 'inspector:ping':
                sendResponse({ ok: true, active: this.isActive(), top: this.isTopFrame });
                return undefined;
            case 'inspector:close':
                this.overlay.close();
                return undefined;
            case 'panel:loading':
                if (this.isTopFrame) this.overlay.showLoading(message as unknown as PanelLoadingMessage);
                return undefined;
            case 'panel:results':
                if (this.isTopFrame) this.overlay.showResults(message as unknown as PanelResultsMessage);
                return undefined;
            case 'panel:error':
                if (this.isTopFrame) this.overlay.showError(message as unknown as PanelErrorMessage);
                return undefined;
            default:
                return undefined;
        }
    };

    private nextRequestId(): string {
        this.sequence += 1;
        return `${this.frameToken}-${this.sequence}`;
    }

    private inspect(path: readonly EventTarget[]): void {
        const target = path.find((node): node is Element => isElementNode(node as Node));
        if (!target) {
            return;
        }
        const requestId = this.nextRequestId();
        const resolved = resolveText(target, { ignoreSelector: this.settings.selectors.ignore });
        if (!resolved.ok) {
            this.overlay.highlight(target);
            void this.send({ type: 'noText', requestId });
            return;
        }
        this.overlay.highlight(resolved.value.container);
        const language = resolveLanguage(this.settings.uiLanguage, this.api.i18n.getUILanguage());
        const request = buildSearchRequest(resolved.value, this.settings, language);
        void this.send({ type: 'search', requestId, request });
    }

    /** Ferme l'inspecteur dans toutes les frames de l'onglet (le panneau est dans la frame du haut). */
    private closeEverywhere(): void {
        this.overlay.close();
        void this.send({ type: 'closeInspector' });
    }

    private async send(message: object): Promise<unknown> {
        try {
            return await this.api.runtime.sendMessage(message);
        } catch {
            // Extension rechargée ou mise à jour : ce content script est orphelin, la page doit être rechargée.
            if (this.isTopFrame) {
                this.overlay.showError({
                    type: 'panel:error',
                    requestId: this.nextRequestId(),
                    lang: resolveLanguage(this.settings.uiLanguage, 'fr'),
                    error: { kind: 'contextInvalidated' },
                    manualSearchUrl: null,
                });
            }
            return undefined;
        }
    }
}

// Point d'entrée du content script. Hors extension (tests important ce module) getApi() lève : rien ne démarre.
try {
    void new Inspector(getApi()).start();
} catch {
    // API WebExtensions indisponible.
}
