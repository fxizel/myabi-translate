// Faux serveur de l'API de gestion des traductions, pour tester l'extension sans backend.
//
//   Texte cliqué (résultats)                  Token saisi dans les Paramètres (comportement)
//   ─────────────────────────────────────     ───────────────────────────────────────────────
//   « Commande »    correspondance forte      ok / n'importe quoi : réponse normale
//   « Statut »      plusieurs possibles       empty               : toujours « aucun résultat »
//   « Enregistrer » aucune exacte             401 ou expired      : 401 token expiré
//   « Valider »     une seule exacte (0,75)   403                 : 403 interdit
//   « Événement »   Ereignis + clé très longue
//   « xss »         contenu hostile (HTML)    429                 : 429 avec Retry-After: 5
//   AUTRE TEXTE     réponse générée à partir  500                 : 500 erreur serveur
//   (par ex. MyAB)  du texte (clé en MAJUSC.) slow                : réponse après 15 s (délai dépassé)
//                   : 1 exacte + 1 approx.    badjson             : 200 mais corps HTML
//                                             down                : connexion coupée (réseau)
//
// Fonctionne donc sur n'importe quelle page, y compris le vrai MyAB : seule l'API est simulée.
//
// Usage autonome : node scripts/mock-api.mjs   (port 8787, ou PORT=…)
import { createServer } from 'node:http';
import { pathToFileURL } from 'node:url';

const BASE_PATH = '/api/browser-extension';

const result = (overrides) => ({
    id: 1,
    key: 'KEY',
    sourceLanguage: 'de',
    sourceText: '',
    currentTranslation: '',
    module: '',
    status: 'approved',
    score: 0.5,
    ...overrides,
});

/** Réponses par texte (clé : texte minuscule). `origin` sert à construire les liens (même origine que l'API). */
function scenarios(origin) {
    const link = (id) => `${origin}/translations/${id}`;
    return {
        commande: [
            result({ id: 1245, key: 'PURCHASE_ORDER', sourceText: 'Bestellung', currentTranslation: 'Commande', proposedTranslation: 'Commande fournisseur', module: 'Purchasing', score: 0.96, translationUrl: link(1245) }),
            result({ id: 678, key: 'ORDER', sourceText: 'Auftrag', currentTranslation: 'Commande', module: 'Sales', status: 'review', score: 0.71, translationUrl: link(678) }),
        ],
        statut: [
            result({ id: 31, key: 'ORDER_STATUS', sourceText: 'Status', currentTranslation: 'Statut', module: 'Sales', score: 0.92, translationUrl: link(31) }),
            result({ id: 32, key: 'STATUS', sourceText: 'Zustand', currentTranslation: 'Statut', module: 'Common', status: 'pending', score: 0.88, translationUrl: link(32) }),
            result({ id: 33, key: 'DELIVERY_STATUS', sourceText: 'Lieferstatus', currentTranslation: 'Statut de livraison', module: 'Logistics', status: 'proposed', score: 0.55, translationUrl: link(33) }),
        ],
        enregistrer: [
            result({ id: 41, key: 'SAVE_AND_CLOSE', sourceText: 'Speichern und schliessen', currentTranslation: 'Enregistrer et fermer', module: 'Common', score: 0.82, translationUrl: link(41) }),
            result({ id: 42, key: 'SAVE_DRAFT', sourceText: 'Entwurf speichern', currentTranslation: 'Enregistrer le brouillon', module: 'Common', status: 'review', score: 0.64, translationUrl: link(42) }),
            result({ id: 43, key: 'SAVED', sourceText: 'Gespeichert', currentTranslation: 'Enregistré', module: 'Common', status: 'rejected', score: 0.4, translationUrl: link(43) }),
        ],
        // Clé longue, réaliste (cherchée avec ou sans accent : « événement », « Evenement »…).
        evenement: [
            result({ id: 2210, key: 'modulesadministrationManagementcourseCoursesOverviewConfig.Event', sourceText: 'Ereignis', currentTranslation: 'Événement', module: 'Administration', score: 0.97, translationUrl: link(2210) }),
            result({ id: 2211, key: 'modulescalendarCalendarEventsList.Event', sourceText: 'Veranstaltung', currentTranslation: 'Événement', module: 'Calendar', status: 'review', score: 0.74, translationUrl: link(2211) }),
        ],
        valider: [
            result({ id: 51, key: 'VALIDATE', sourceText: 'Bestätigen', currentTranslation: 'Valider', module: 'Common', score: 0.75, translationUrl: link(51) }),
        ],
        xss: [
            result({ id: 61, key: '<img src=x onerror=alert(1)>', sourceText: '<script>alert(1)</script>', currentTranslation: 'xss', module: '"><b>gras</b>', status: 'x" onmouseover="alert(1)', score: 0.99, translationUrl: 'javascript:alert(1)' }),
            result({ id: 62, key: 'EVIL_LINK', sourceText: 'Phishing', currentTranslation: 'xss', module: 'Evil', score: 0.9, translationUrl: 'https://evil.example.com/translations/62' }),
        ],
    };
}

/** Réponse plausible pour un texte quelconque : une clé dérivée du texte (exacte) et une variante approximative. */
function generated(text, origin) {
    const slug =
        text
            .normalize('NFD')
            .replace(/\p{M}/gu, '')
            .toUpperCase()
            .replace(/[^A-Z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 40) || 'TEXT';
    const id = [...text].reduce((hash, char) => (hash * 31 + char.codePointAt(0)) % 100000, 7);
    return [
        result({ id, key: slug, sourceText: `[DE] ${text}`, currentTranslation: text, module: 'Mock', score: 0.94, translationUrl: `${origin}/translations/${id}` }),
        result({ id: id + 1, key: `${slug}_VARIANT`, sourceText: `[DE] ${text} (Variante)`, currentTranslation: `${text} (variante)`, module: 'Mock', status: 'review', score: 0.62, translationUrl: `${origin}/translations/${id + 1}` }),
    ];
}

function json(response, status, body, headers = {}) {
    response.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers });
    response.end(JSON.stringify(body));
}

function html(response, title, body) {
    const escape = (value) => String(value).replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);
    response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
    response.end(`<!doctype html><meta charset="utf-8"><title>${escape(title)}</title><body style="font:16px system-ui;margin:2rem"><h1>${escape(title)}</h1><p>${escape(body)}</p>`);
}

async function readJson(request) {
    const chunks = [];
    for await (const chunk of request) chunks.push(chunk);
    try {
        return JSON.parse(Buffer.concat(chunks).toString('utf8'));
    } catch {
        return null;
    }
}

/**
 * @param {{ slowMs?: number, log?: (line: string) => void }} [options]
 * @returns {import('node:http').Server & { requests: Array<Record<string, unknown>> }}
 */
export function createMockServer(options = {}) {
    const slowMs = options.slowMs ?? 15_000;
    const log = options.log ?? ((line) => console.log(line));
    const requests = [];

    const server = createServer(async (request, response) => {
        const url = new URL(request.url ?? '/', 'http://localhost');
        const origin = `http://${request.headers.host ?? 'localhost'}`;

        // Pages ouvertes depuis le panneau (« Ouvrir la traduction », « Rechercher manuellement »).
        if (request.method === 'GET' && url.pathname.startsWith('/translations/')) {
            return html(response, `Traduction ${url.pathname.split('/').pop()}`, 'Page factice de l’application de gestion.');
        }
        if (request.method === 'GET' && url.pathname === '/terms') {
            return html(response, 'Recherche manuelle', `Recherche : ${url.searchParams.get('q') ?? ''}`);
        }
        if (request.method === 'GET' && url.pathname === '/__requests') {
            return json(response, 200, requests);
        }
        if (request.method !== 'POST' || ![`${BASE_PATH}/search`, `${BASE_PATH}/feedback`].includes(url.pathname)) {
            return json(response, 404, { message: 'Not found' });
        }

        const authorization = request.headers.authorization ?? '';
        const token = authorization.startsWith('Bearer ') ? authorization.slice(7).trim() : '';
        const mode = token.toLowerCase();
        const body = await readJson(request);
        const endpoint = url.pathname.slice(BASE_PATH.length + 1);
        // Le token n'est jamais enregistré ni affiché : seul le « mode » demandé l'est.
        const entry = { endpoint, mode: token ? mode : '(sans token)', body };
        requests.push(entry);
        if (requests.length > 50) requests.shift();
        log(`[mock] ${endpoint} mode=${entry.mode} texte=${JSON.stringify(body?.text ?? null)} champs=${body ? Object.keys(body).length : 0}`);

        if (!token) return json(response, 401, { message: 'Unauthenticated.' });
        if (mode === '401' || mode === 'expired') return json(response, 401, { message: 'Token expired.' });
        if (mode === '403') return json(response, 403, { message: 'Forbidden.' });
        if (mode === '429') return json(response, 429, { message: 'Too many requests.' }, { 'Retry-After': '5' });
        if (mode === '500') return json(response, 500, { message: 'Server error.' });
        if (mode === 'down') return request.socket.destroy();
        if (mode === 'badjson') {
            response.writeHead(200, { 'Content-Type': 'text/html' });
            return response.end('<html><body>Page de connexion</body></html>');
        }
        if (mode === 'slow') await new Promise((resolve) => setTimeout(resolve, slowMs));

        if (endpoint === 'feedback') return json(response, 200, { ok: true });
        if (!body || typeof body.text !== 'string') return json(response, 422, { message: 'The text field is required.' });

        const limit = Number.isInteger(body.maxResults) ? body.maxResults : 5;
        const text = body.text.trim();
        const known = scenarios(origin)[text.toLowerCase().normalize('NFD').replace(/\p{M}/gu, '')];
        const results = (mode === 'empty' ? [] : (known ?? generated(text, origin))).slice(0, limit);
        return json(response, 200, { query: body.text, results });
    });

    server.requests = requests;
    return server;
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href) {
    const port = Number(process.env.PORT ?? 8787);
    createMockServer().listen(port, '127.0.0.1', () => {
        console.log(`API factice : http://localhost:${port}${BASE_PATH}   (token « ok » ; voir l'en-tête de scripts/mock-api.mjs)`);
    });
}
