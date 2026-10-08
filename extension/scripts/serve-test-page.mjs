// Sert test-pages/ sur http://localhost:5173 (HTTP toléré par l'extension uniquement pour localhost).
// Avec --mock : démarre aussi l'API factice sur http://localhost:8787/api/browser-extension (scripts/mock-api.mjs).
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { dirname, extname, join, normalize, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createMockServer } from './mock-api.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'test-pages');
const port = Number(process.env.PORT ?? 5173);
const mockPort = Number(process.env.MOCK_PORT ?? 8787);
const types = { '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8' };

createServer(async (request, response) => {
    const pathname = decodeURIComponent(new URL(request.url ?? '/', 'http://localhost').pathname);
    const file = normalize(join(root, pathname === '/' ? 'index.html' : pathname));
    if (file !== root && !file.startsWith(root + sep)) {
        response.writeHead(403).end('Forbidden');
        return;
    }
    try {
        const body = await readFile(file);
        response.writeHead(200, { 'Content-Type': types[extname(file)] ?? 'application/octet-stream', 'Cache-Control': 'no-store' });
        response.end(body);
    } catch {
        response.writeHead(404).end('Not found');
    }
}).listen(port, '127.0.0.1', () => {
    console.log(`Page de test : http://localhost:${port}/  (ajoutez « localhost » aux domaines autorisés)`);
});

if (process.argv.includes('--mock')) {
    createMockServer().listen(mockPort, '127.0.0.1', () => {
        console.log(`API factice  : http://localhost:${mockPort}/api/browser-extension  (token « ok »)`);
        console.log('Importez extension/config.mock.json dans les Paramètres, ou saisissez ces valeurs à la main.');
    });
}
