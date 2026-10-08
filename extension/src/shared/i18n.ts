import type { ActivationModifier, Lang, LangPreference, PanelError } from '../types';

const fr = {
    // Panneau
    close: 'Fermer',
    detectedText: 'Texte détecté',
    loading: 'Recherche en cours…',
    stateStrong: 'Correspondance forte',
    stateMultiple: 'Plusieurs correspondances possibles',
    stateNoExact: 'Aucune traduction exacte trouvée.',
    stateNone: 'Aucune traduction trouvée.',
    approximateResults: 'Résultats approximatifs proposés par l’API :',
    module: 'Module',
    status: 'Statut',
    proposal: 'Proposition',
    copyKey: 'Copier la clé',
    copySource: 'Copier le texte {language}',
    openTranslation: 'Ouvrir la traduction',
    reportCorrect: 'Signaler cette correspondance comme correcte',
    reported: 'Merci, signalé',
    reportFailed: 'Envoi impossible',
    copied: 'Copié',
    copyFailed: 'Copie impossible',
    manualSearch: 'Rechercher manuellement dans l’application',
    retry: 'Réessayer',
    openSettings: 'Ouvrir les paramètres',
    statusApproved: 'Validé',
    statusReview: 'En révision',
    statusPending: 'En attente',
    statusProposed: 'Proposé',
    statusRejected: 'Rejeté',
    statusDraft: 'Brouillon',
    statusObsolete: 'Obsolète',
    langGerman: 'allemand',
    langFrench: 'français',
    langItalian: 'italien',
    langEnglish: 'anglais',
    // Erreurs
    errorNoText: 'Aucun texte exploitable à cet endroit (élément vide ou purement décoratif).',
    errorNotConfigured: 'Extension non configurée : renseignez l’URL de l’API et le token dans les paramètres.',
    errorDomain: 'Ce domaine n’est pas autorisé dans les paramètres de l’extension.',
    errorPermission: 'L’extension n’a pas l’autorisation d’accéder à l’API. Ouvrez les paramètres et enregistrez-les à nouveau.',
    errorNetwork: 'API inaccessible. Vérifiez votre connexion et l’URL configurée.',
    errorTimeout: 'L’API met trop de temps à répondre. Réessayez dans un instant.',
    errorUnauthorized: 'Authentification refusée (401) : le token est absent, invalide ou expiré. Mettez-le à jour dans les paramètres.',
    errorForbidden: 'Accès refusé (403) : ce token n’a pas le droit d’utiliser la recherche.',
    errorNotFound: 'Point d’accès introuvable (404) : vérifiez l’URL de l’API dans les paramètres.',
    errorRateLimited: 'Trop de requêtes (429). Réessayez dans {seconds} s.',
    errorRateLimitedNoDelay: 'Trop de requêtes (429). Réessayez dans un instant.',
    errorServer: 'Le serveur de traductions a rencontré une erreur ({status}). Réessayez plus tard.',
    errorHttp: 'Requête refusée par l’API ({status}).',
    errorInvalid: 'Réponse inattendue de l’API.',
    errorInsecure: 'Connexion non sécurisée refusée : l’API doit répondre en HTTPS.',
    errorAborted: 'Recherche annulée.',
    errorContext: 'L’extension a été rechargée : rechargez cette page.',
    // Popup
    popupToggle: 'Mode inspecteur',
    popupHint: 'Maintenez {modifier} et cliquez sur un texte de MyAB.',
    popupActive: 'Actif sur cette page.',
    popupInactive: 'Inactif sur cette page : domaine non configuré, ou page à recharger.',
    popupNotConfigured: 'Configuration incomplète (API, token ou domaine).',
    modifierAlt: 'Alt',
    modifierAltShift: 'Alt + Maj',
    modifierAltCtrl: 'Alt + Ctrl',
    // Options
    optTitle: 'MyAB Translation Inspector — Paramètres',
    optIntro: 'Retrouvez la clé technique d’un texte affiché dans MyAB. L’extension ne modifie jamais MyAB.',
    optSectionApi: 'API de gestion des traductions',
    optApiUrl: 'URL de l’API',
    optApiUrlHelp: 'Exemple : https://translations.example.test/api/browser-extension (HTTPS obligatoire).',
    optToken: 'Token / clé API',
    optTokenHelp: 'Envoyé en « Authorization: Bearer ». Jamais affiché ni journalisé.',
    optTokenSaved: 'Un token est enregistré (laisser vide pour le conserver).',
    optTokenNone: 'Aucun token enregistré.',
    optTokenClear: 'Supprimer le token enregistré',
    optTokenPersistence: 'Conservation du token',
    optPersistLocal: 'Sur cet appareil (reste après redémarrage)',
    optPersistSession: 'Session du navigateur uniquement (à ressaisir)',
    optAppUrl: 'URL de l’application (facultatif)',
    optAppUrlHelp: 'Pour les liens et la recherche manuelle. Vide = origine de l’API.',
    optSectionMyab: 'MyAB',
    optDomains: 'Domaines MyAB autorisés',
    optDomainsHelp: 'Un par ligne : myab.exemple.test ou *.exemple.test. L’extension n’agit que sur ces domaines ; le navigateur demandera l’autorisation.',
    optSectionBehavior: 'Comportement',
    optLanguage: 'Langue de l’interface',
    optLangAuto: 'Automatique (navigateur)',
    optModifier: 'Touche du clic inspecteur',
    optShortcut: 'Raccourci d’activation',
    optShortcutHelp: 'À modifier dans le navigateur : chrome://extensions/shortcuts (Chrome) ou about:addons › roue dentée › Gérer les raccourcis (Firefox).',
    optShortcutNone: 'non défini',
    optMaxResults: 'Nombre maximum de résultats',
    optCollect: 'Collecter le contexte de l’élément',
    optCollectHelp: 'Désactivé : seuls le texte, l’URL, le titre et la langue de la page sont envoyés.',
    optUrlMode: 'URL de page transmise',
    optUrlFull: 'Complète',
    optUrlNoQuery: 'Sans paramètres (recommandé)',
    optUrlOrigin: 'Domaine seulement',
    optTimeout: 'Délai d’attente de l’API (secondes)',
    optFeedback: 'Proposer « Signaler cette correspondance comme correcte »',
    optSectionAdvanced: 'Avancé : adapter l’extraction au DOM de MyAB',
    optAdvancedHelp: 'Sélecteurs CSS facultatifs, en plus des détections par défaut.',
    optSelBreadcrumb: 'Fil d’Ariane',
    optSelDialog: 'Fenêtre modale',
    optSelSection: 'Titre de section',
    optSelIgnore: 'Éléments à ignorer',
    optManualTemplate: 'Gabarit de recherche manuelle',
    optManualTemplateHelp: 'Variables : {appUrl} et {query}.',
    optSave: 'Enregistrer',
    optTest: 'Tester la connexion',
    optImport: 'Importer une configuration',
    optExport: 'Exporter (sans token)',
    optSaved: 'Paramètres enregistrés. Rechargez les onglets MyAB déjà ouverts.',
    optPermissionDenied: 'Autorisation refusée : les domaines et l’API doivent être autorisés pour que l’extension fonctionne.',
    optSaveFailed: 'Enregistrement impossible.',
    optTesting: 'Test en cours…',
    optTestOk: 'Connexion réussie ({count} résultat(s) pour le texte de test).',
    optImportOk: 'Configuration importée dans le formulaire : vérifiez puis enregistrez. Le token n’est jamais importé.',
    optImportInvalid: 'Fichier de configuration illisible.',
    valApiUrlEmpty: 'L’URL de l’API est obligatoire.',
    valApiUrlInvalid: 'L’URL de l’API est invalide.',
    valApiUrlInsecure: 'L’URL de l’API doit utiliser HTTPS (HTTP n’est toléré que pour localhost).',
    valAppUrlInvalid: 'L’URL de l’application est invalide ou non HTTPS.',
    valDomainsEmpty: 'Indiquez au moins un domaine MyAB.',
    valDomainInvalid: '« {entry} » n’est pas un domaine valide.',
    valDomainTooBroad: '« {entry} » est trop large (utilisez par exemple *.exemple.test).',
    valDomainInsecure: '« {entry} » : seul HTTPS est accepté (HTTP uniquement pour localhost).',
    valToken: 'Le token contient des espaces ou des caractères non autorisés.',
    valSelector: 'Sélecteur CSS invalide : {field}.',
    valTemplate: 'Le gabarit de recherche doit contenir {query}.',
} as const;

export type MessageKey = keyof typeof fr;

const de: Record<MessageKey, string> = {
    close: 'Schliessen',
    detectedText: 'Erkannter Text',
    loading: 'Suche läuft…',
    stateStrong: 'Starke Übereinstimmung',
    stateMultiple: 'Mehrere mögliche Übereinstimmungen',
    stateNoExact: 'Keine exakte Übersetzung gefunden.',
    stateNone: 'Keine Übersetzung gefunden.',
    approximateResults: 'Von der API vorgeschlagene ungefähre Ergebnisse:',
    module: 'Modul',
    status: 'Status',
    proposal: 'Vorschlag',
    copyKey: 'Schlüssel kopieren',
    copySource: 'Text kopieren ({language})',
    openTranslation: 'Übersetzung öffnen',
    reportCorrect: 'Diese Übereinstimmung als korrekt melden',
    reported: 'Danke, gemeldet',
    reportFailed: 'Senden nicht möglich',
    copied: 'Kopiert',
    copyFailed: 'Kopieren nicht möglich',
    manualSearch: 'Manuell in der Anwendung suchen',
    retry: 'Erneut versuchen',
    openSettings: 'Einstellungen öffnen',
    statusApproved: 'Freigegeben',
    statusReview: 'In Prüfung',
    statusPending: 'Ausstehend',
    statusProposed: 'Vorgeschlagen',
    statusRejected: 'Abgelehnt',
    statusDraft: 'Entwurf',
    statusObsolete: 'Veraltet',
    langGerman: 'Deutsch',
    langFrench: 'Französisch',
    langItalian: 'Italienisch',
    langEnglish: 'Englisch',
    errorNoText: 'Hier gibt es keinen verwertbaren Text (leeres oder rein dekoratives Element).',
    errorNotConfigured: 'Erweiterung nicht konfiguriert: API-URL und Token in den Einstellungen angeben.',
    errorDomain: 'Diese Domain ist in den Einstellungen der Erweiterung nicht freigegeben.',
    errorPermission: 'Die Erweiterung darf nicht auf die API zugreifen. Einstellungen öffnen und erneut speichern.',
    errorNetwork: 'API nicht erreichbar. Verbindung und konfigurierte URL prüfen.',
    errorTimeout: 'Die API antwortet zu langsam. Bitte gleich nochmals versuchen.',
    errorUnauthorized: 'Authentifizierung abgelehnt (401): Token fehlt, ist ungültig oder abgelaufen. In den Einstellungen aktualisieren.',
    errorForbidden: 'Zugriff verweigert (403): Dieses Token darf die Suche nicht verwenden.',
    errorNotFound: 'Endpunkt nicht gefunden (404): API-URL in den Einstellungen prüfen.',
    errorRateLimited: 'Zu viele Anfragen (429). Erneut versuchen in {seconds} s.',
    errorRateLimitedNoDelay: 'Zu viele Anfragen (429). Bitte gleich nochmals versuchen.',
    errorServer: 'Der Übersetzungsserver meldet einen Fehler ({status}). Später erneut versuchen.',
    errorHttp: 'Anfrage von der API abgelehnt ({status}).',
    errorInvalid: 'Unerwartete Antwort der API.',
    errorInsecure: 'Unsichere Verbindung abgelehnt: Die API muss über HTTPS antworten.',
    errorAborted: 'Suche abgebrochen.',
    errorContext: 'Die Erweiterung wurde neu geladen: Seite neu laden.',
    popupToggle: 'Inspektor-Modus',
    popupHint: '{modifier} gedrückt halten und auf einen MyAB-Text klicken.',
    popupActive: 'Auf dieser Seite aktiv.',
    popupInactive: 'Auf dieser Seite inaktiv: Domain nicht konfiguriert oder Seite neu laden.',
    popupNotConfigured: 'Konfiguration unvollständig (API, Token oder Domain).',
    modifierAlt: 'Alt',
    modifierAltShift: 'Alt + Umschalt',
    modifierAltCtrl: 'Alt + Strg',
    optTitle: 'MyAB Translation Inspector — Einstellungen',
    optIntro: 'Findet den technischen Schlüssel eines in MyAB angezeigten Textes. Die Erweiterung verändert MyAB nie.',
    optSectionApi: 'API der Übersetzungsverwaltung',
    optApiUrl: 'API-URL',
    optApiUrlHelp: 'Beispiel: https://translations.example.test/api/browser-extension (HTTPS erforderlich).',
    optToken: 'Token / API-Schlüssel',
    optTokenHelp: 'Wird als «Authorization: Bearer» gesendet. Nie angezeigt oder protokolliert.',
    optTokenSaved: 'Ein Token ist gespeichert (leer lassen, um es zu behalten).',
    optTokenNone: 'Kein Token gespeichert.',
    optTokenClear: 'Gespeichertes Token löschen',
    optTokenPersistence: 'Aufbewahrung des Tokens',
    optPersistLocal: 'Auf diesem Gerät (bleibt nach Neustart)',
    optPersistSession: 'Nur Browser-Sitzung (neu eingeben)',
    optAppUrl: 'URL der Anwendung (optional)',
    optAppUrlHelp: 'Für Links und manuelle Suche. Leer = Ursprung der API.',
    optSectionMyab: 'MyAB',
    optDomains: 'Erlaubte MyAB-Domains',
    optDomainsHelp: 'Eine pro Zeile: myab.beispiel.test oder *.beispiel.test. Die Erweiterung wirkt nur auf diesen Domains; der Browser fragt nach der Berechtigung.',
    optSectionBehavior: 'Verhalten',
    optLanguage: 'Sprache der Oberfläche',
    optLangAuto: 'Automatisch (Browser)',
    optModifier: 'Taste für den Inspektor-Klick',
    optShortcut: 'Tastenkürzel zum Aktivieren',
    optShortcutHelp: 'Im Browser ändern: chrome://extensions/shortcuts (Chrome) oder about:addons › Zahnrad › Tastenkürzel verwalten (Firefox).',
    optShortcutNone: 'nicht definiert',
    optMaxResults: 'Maximale Anzahl Ergebnisse',
    optCollect: 'Kontext des Elements erfassen',
    optCollectHelp: 'Deaktiviert: Es werden nur Text, URL, Titel und Sprache der Seite gesendet.',
    optUrlMode: 'Übermittelte Seiten-URL',
    optUrlFull: 'Vollständig',
    optUrlNoQuery: 'Ohne Parameter (empfohlen)',
    optUrlOrigin: 'Nur Domain',
    optTimeout: 'Zeitlimit der API (Sekunden)',
    optFeedback: '«Diese Übereinstimmung als korrekt melden» anbieten',
    optSectionAdvanced: 'Erweitert: Extraktion an das MyAB-DOM anpassen',
    optAdvancedHelp: 'Optionale CSS-Selektoren zusätzlich zur Standarderkennung.',
    optSelBreadcrumb: 'Brotkrumen-Navigation',
    optSelDialog: 'Modales Fenster',
    optSelSection: 'Abschnittstitel',
    optSelIgnore: 'Zu ignorierende Elemente',
    optManualTemplate: 'Vorlage für die manuelle Suche',
    optManualTemplateHelp: 'Variablen: {appUrl} und {query}.',
    optSave: 'Speichern',
    optTest: 'Verbindung testen',
    optImport: 'Konfiguration importieren',
    optExport: 'Exportieren (ohne Token)',
    optSaved: 'Einstellungen gespeichert. Bereits offene MyAB-Tabs neu laden.',
    optPermissionDenied: 'Berechtigung verweigert: Domains und API müssen freigegeben sein, damit die Erweiterung funktioniert.',
    optSaveFailed: 'Speichern nicht möglich.',
    optTesting: 'Test läuft…',
    optTestOk: 'Verbindung erfolgreich ({count} Ergebnis(se) für den Testtext).',
    optImportOk: 'Konfiguration ins Formular importiert: prüfen und speichern. Das Token wird nie importiert.',
    optImportInvalid: 'Konfigurationsdatei nicht lesbar.',
    valApiUrlEmpty: 'Die API-URL ist erforderlich.',
    valApiUrlInvalid: 'Die API-URL ist ungültig.',
    valApiUrlInsecure: 'Die API-URL muss HTTPS verwenden (HTTP nur für localhost).',
    valAppUrlInvalid: 'Die Anwendungs-URL ist ungültig oder nicht HTTPS.',
    valDomainsEmpty: 'Mindestens eine MyAB-Domain angeben.',
    valDomainInvalid: '«{entry}» ist keine gültige Domain.',
    valDomainTooBroad: '«{entry}» ist zu weit gefasst (z. B. *.beispiel.test verwenden).',
    valDomainInsecure: '«{entry}»: nur HTTPS erlaubt (HTTP nur für localhost).',
    valToken: 'Das Token enthält Leerzeichen oder unzulässige Zeichen.',
    valSelector: 'Ungültiger CSS-Selektor: {field}.',
    valTemplate: 'Die Suchvorlage muss {query} enthalten.',
};

const en: Record<MessageKey, string> = {
    close: 'Close',
    detectedText: 'Detected text',
    loading: 'Searching…',
    stateStrong: 'Strong match',
    stateMultiple: 'Several possible matches',
    stateNoExact: 'No exact translation found.',
    stateNone: 'No translation found.',
    approximateResults: 'Approximate results suggested by the API:',
    module: 'Module',
    status: 'Status',
    proposal: 'Proposal',
    copyKey: 'Copy key',
    copySource: 'Copy {language} text',
    openTranslation: 'Open translation',
    reportCorrect: 'Report this match as correct',
    reported: 'Thanks, reported',
    reportFailed: 'Could not send',
    copied: 'Copied',
    copyFailed: 'Copy failed',
    manualSearch: 'Search manually in the application',
    retry: 'Retry',
    openSettings: 'Open settings',
    statusApproved: 'Approved',
    statusReview: 'In review',
    statusPending: 'Pending',
    statusProposed: 'Proposed',
    statusRejected: 'Rejected',
    statusDraft: 'Draft',
    statusObsolete: 'Obsolete',
    langGerman: 'German',
    langFrench: 'French',
    langItalian: 'Italian',
    langEnglish: 'English',
    errorNoText: 'No usable text here (empty or purely decorative element).',
    errorNotConfigured: 'Extension not configured: set the API URL and token in the settings.',
    errorDomain: 'This domain is not allowed in the extension settings.',
    errorPermission: 'The extension is not allowed to reach the API. Open the settings and save them again.',
    errorNetwork: 'API unreachable. Check your connection and the configured URL.',
    errorTimeout: 'The API is taking too long to respond. Try again in a moment.',
    errorUnauthorized: 'Authentication refused (401): the token is missing, invalid or expired. Update it in the settings.',
    errorForbidden: 'Access denied (403): this token is not allowed to use search.',
    errorNotFound: 'Endpoint not found (404): check the API URL in the settings.',
    errorRateLimited: 'Too many requests (429). Try again in {seconds} s.',
    errorRateLimitedNoDelay: 'Too many requests (429). Try again in a moment.',
    errorServer: 'The translation server reported an error ({status}). Try again later.',
    errorHttp: 'Request rejected by the API ({status}).',
    errorInvalid: 'Unexpected response from the API.',
    errorInsecure: 'Insecure connection refused: the API must answer over HTTPS.',
    errorAborted: 'Search cancelled.',
    errorContext: 'The extension was reloaded: reload this page.',
    popupToggle: 'Inspector mode',
    popupHint: 'Hold {modifier} and click a MyAB text.',
    popupActive: 'Active on this page.',
    popupInactive: 'Inactive on this page: domain not configured, or page needs a reload.',
    popupNotConfigured: 'Incomplete configuration (API, token or domain).',
    modifierAlt: 'Alt',
    modifierAltShift: 'Alt + Shift',
    modifierAltCtrl: 'Alt + Ctrl',
    optTitle: 'MyAB Translation Inspector — Settings',
    optIntro: 'Find the technical key of a text shown in MyAB. The extension never modifies MyAB.',
    optSectionApi: 'Translation management API',
    optApiUrl: 'API URL',
    optApiUrlHelp: 'Example: https://translations.example.test/api/browser-extension (HTTPS required).',
    optToken: 'Token / API key',
    optTokenHelp: 'Sent as “Authorization: Bearer”. Never displayed or logged.',
    optTokenSaved: 'A token is stored (leave empty to keep it).',
    optTokenNone: 'No token stored.',
    optTokenClear: 'Delete the stored token',
    optTokenPersistence: 'Token retention',
    optPersistLocal: 'On this device (kept after restart)',
    optPersistSession: 'Browser session only (re-enter it)',
    optAppUrl: 'Application URL (optional)',
    optAppUrlHelp: 'For links and manual search. Empty = API origin.',
    optSectionMyab: 'MyAB',
    optDomains: 'Allowed MyAB domains',
    optDomainsHelp: 'One per line: myab.example.test or *.example.test. The extension only acts on these domains; the browser will ask for permission.',
    optSectionBehavior: 'Behaviour',
    optLanguage: 'Interface language',
    optLangAuto: 'Automatic (browser)',
    optModifier: 'Inspector click key',
    optShortcut: 'Activation shortcut',
    optShortcutHelp: 'Change it in the browser: chrome://extensions/shortcuts (Chrome) or about:addons › gear › Manage Extension Shortcuts (Firefox).',
    optShortcutNone: 'not set',
    optMaxResults: 'Maximum number of results',
    optCollect: 'Collect element context',
    optCollectHelp: 'Disabled: only the text, URL, title and language of the page are sent.',
    optUrlMode: 'Page URL sent',
    optUrlFull: 'Full',
    optUrlNoQuery: 'Without parameters (recommended)',
    optUrlOrigin: 'Domain only',
    optTimeout: 'API timeout (seconds)',
    optFeedback: 'Offer “Report this match as correct”',
    optSectionAdvanced: 'Advanced: adapt extraction to the MyAB DOM',
    optAdvancedHelp: 'Optional CSS selectors, in addition to the default detection.',
    optSelBreadcrumb: 'Breadcrumbs',
    optSelDialog: 'Modal dialog',
    optSelSection: 'Section title',
    optSelIgnore: 'Elements to ignore',
    optManualTemplate: 'Manual search template',
    optManualTemplateHelp: 'Variables: {appUrl} and {query}.',
    optSave: 'Save',
    optTest: 'Test connection',
    optImport: 'Import configuration',
    optExport: 'Export (without token)',
    optSaved: 'Settings saved. Reload MyAB tabs that are already open.',
    optPermissionDenied: 'Permission denied: the domains and the API must be allowed for the extension to work.',
    optSaveFailed: 'Could not save.',
    optTesting: 'Testing…',
    optTestOk: 'Connection successful ({count} result(s) for the test text).',
    optImportOk: 'Configuration loaded into the form: review, then save. The token is never imported.',
    optImportInvalid: 'Unreadable configuration file.',
    valApiUrlEmpty: 'The API URL is required.',
    valApiUrlInvalid: 'The API URL is invalid.',
    valApiUrlInsecure: 'The API URL must use HTTPS (HTTP is only tolerated for localhost).',
    valAppUrlInvalid: 'The application URL is invalid or not HTTPS.',
    valDomainsEmpty: 'Enter at least one MyAB domain.',
    valDomainInvalid: '“{entry}” is not a valid domain.',
    valDomainTooBroad: '“{entry}” is too broad (use e.g. *.example.test).',
    valDomainInsecure: '“{entry}”: only HTTPS is accepted (HTTP only for localhost).',
    valToken: 'The token contains spaces or disallowed characters.',
    valSelector: 'Invalid CSS selector: {field}.',
    valTemplate: 'The search template must contain {query}.',
};

const DICTIONARIES: Record<Lang, Record<MessageKey, string>> = { fr, de, en };

export function t(lang: Lang, key: MessageKey, params: Record<string, string | number> = {}): string {
    const template = DICTIONARIES[lang][key];
    return template.replace(/\{(\w+)\}/g, (match, name: string) => (name in params ? String(params[name]) : match));
}

export function resolveLanguage(preference: LangPreference, browserLanguage: string): Lang {
    if (preference !== 'auto') {
        return preference;
    }
    const base = browserLanguage.toLowerCase().split(/[-_]/)[0];
    return base === 'de' || base === 'en' ? base : 'fr';
}

/** Nom de la langue source, adapté à la langue de l'interface (« allemand », « Deutsch », « German »). */
export function languageName(lang: Lang, code: string): string {
    const keys: Record<string, MessageKey> = {
        de: 'langGerman',
        fr: 'langFrench',
        it: 'langItalian',
        en: 'langEnglish',
    };
    const key = keys[code.toLowerCase()];
    return key ? t(lang, key) : code.toUpperCase();
}

export function modifierLabel(lang: Lang, modifier: ActivationModifier): string {
    const keys: Record<ActivationModifier, MessageKey> = {
        alt: 'modifierAlt',
        altShift: 'modifierAltShift',
        altCtrl: 'modifierAltCtrl',
    };
    return t(lang, keys[modifier]);
}

const STATUS_KEYS: Record<string, MessageKey> = {
    approved: 'statusApproved',
    validated: 'statusApproved',
    review: 'statusReview',
    in_review: 'statusReview',
    pending: 'statusPending',
    proposed: 'statusProposed',
    rejected: 'statusRejected',
    draft: 'statusDraft',
    obsolete: 'statusObsolete',
};

/** Libellé lisible d'un statut ; un statut inconnu est affiché tel que reçu. */
export function statusLabel(lang: Lang, status: string): string {
    const key = STATUS_KEYS[status.toLowerCase()];
    return key ? t(lang, key) : status;
}

/** Message compréhensible pour une erreur de recherche. */
export function errorMessage(lang: Lang, error: PanelError): string {
    const status = error.status ?? 0;
    switch (error.kind) {
        case 'noText':
            return t(lang, 'errorNoText');
        case 'notConfigured':
            return t(lang, 'errorNotConfigured');
        case 'domainNotAllowed':
            return t(lang, 'errorDomain');
        case 'permission':
            return t(lang, 'errorPermission');
        case 'network':
            return t(lang, 'errorNetwork');
        case 'timeout':
            return t(lang, 'errorTimeout');
        case 'unauthorized':
            return t(lang, 'errorUnauthorized');
        case 'forbidden':
            return t(lang, 'errorForbidden');
        case 'notFound':
            return t(lang, 'errorNotFound');
        case 'rateLimited':
            return error.retryAfterSec
                ? t(lang, 'errorRateLimited', { seconds: error.retryAfterSec })
                : t(lang, 'errorRateLimitedNoDelay');
        case 'server':
            return t(lang, 'errorServer', { status });
        case 'http':
            return t(lang, 'errorHttp', { status });
        case 'insecure':
            return t(lang, 'errorInsecure');
        case 'aborted':
            return t(lang, 'errorAborted');
        case 'contextInvalidated':
            return t(lang, 'errorContext');
        case 'invalidResponse':
        default:
            return t(lang, 'errorInvalid');
    }
}
