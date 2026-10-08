// Construit dist/<chrome|firefox>/ à partir d'un code source unique, puis (option --zip) dist/packages/*.zip.
// Usage : node scripts/build.mjs --target <chrome|firefox|all> [--watch] [--zip]
import { build, context } from 'esbuild';
import { zipSync } from 'fflate';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, watch, writeFileSync } from 'node:fs';
import { dirname, join, posix, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const option = (name) => {
    const index = args.indexOf(`--${name}`);
    return index === -1 ? undefined : (args[index + 1] ?? true);
};

const targetArg = option('target') ?? 'all';
const targets = targetArg === 'all' ? ['chrome', 'firefox'] : [targetArg];
for (const target of targets) {
    if (!['chrome', 'firefox'].includes(target)) {
        console.error(`Cible inconnue : ${target} (attendu : chrome, firefox ou all)`);
        process.exit(1);
    }
}
const watching = args.includes('--watch');
const zipping = args.includes('--zip');

const pkg = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8'));
const entryPoints = {
    content: 'src/content/inspector.ts',
    background: 'src/background/serviceWorker.ts',
    options: 'src/options/options.ts',
    popup: 'src/popup/popup.ts',
};
const staticFiles = [
    ['src/options/options.html', 'options.html'],
    ['src/options/options.css', 'options.css'],
    ['src/popup/popup.html', 'popup.html'],
    ['src/popup/popup.css', 'popup.css'],
];

function outDir(target) {
    return join(root, 'dist', target);
}

/** Fichiers non TypeScript : manifeste (version injectée depuis package.json), pages HTML/CSS, icônes. */
function copyStatic(target) {
    const out = outDir(target);
    mkdirSync(out, { recursive: true });
    const manifest = JSON.parse(readFileSync(join(root, `manifest.${target}.json`), 'utf8'));
    manifest.version = pkg.version;
    writeFileSync(join(out, 'manifest.json'), `${JSON.stringify(manifest, null, 4)}\n`);
    for (const [from, to] of staticFiles) {
        cpSync(join(root, from), join(out, to));
    }
    cpSync(join(root, 'assets', 'icons'), join(out, 'icons'), { recursive: true });
}

function esbuildOptions(target) {
    return {
        absWorkingDir: root,
        entryPoints,
        outdir: outDir(target),
        bundle: true,
        // IIFE : valable pour un content script, un service worker Chrome et une event page Firefox.
        format: 'iife',
        platform: 'browser',
        target: target === 'chrome' ? ['chrome110'] : ['firefox128'],
        sourcemap: watching ? 'inline' : false,
        minify: false,
        legalComments: 'none',
        logLevel: 'info',
    };
}

function listFiles(directory) {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);
        return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

/** Archive reproductible : ordre alphabétique, dates fixes, séparateurs « / ». */
function zipTarget(target) {
    const out = outDir(target);
    const files = {};
    for (const file of listFiles(out).sort()) {
        files[posix.join(...relative(out, file).split(/[\\/]/))] = new Uint8Array(readFileSync(file));
    }
    const archive = zipSync(files, { level: 9, mtime: new Date('2020-01-01T00:00:00Z') });
    const packages = join(root, 'dist', 'packages');
    mkdirSync(packages, { recursive: true });
    const name = join(packages, `myab-translation-inspector-${target}-${pkg.version}.zip`);
    writeFileSync(name, archive);
    console.log(`Package ${target} : ${relative(root, name)} (${(archive.length / 1024).toFixed(1)} Ko)`);
}

if (!existsSync(join(root, 'assets', 'icons', 'icon-128.png'))) {
    console.error('Icônes manquantes : exécutez scripts/generate-icons.ps1 (voir README).');
    process.exit(1);
}

for (const target of targets) {
    rmSync(outDir(target), { recursive: true, force: true });
    copyStatic(target);
    if (watching) {
        const ctx = await context(esbuildOptions(target));
        await ctx.watch();
        let timer;
        watch(join(root, 'src'), { recursive: true }, () => {
            clearTimeout(timer);
            timer = setTimeout(() => copyStatic(target), 100);
        });
        console.log(`Surveillance de ${target} → dist/${target} (Ctrl+C pour arrêter)`);
    } else {
        await build(esbuildOptions(target));
        console.log(`Build ${target} : dist/${target}`);
        if (zipping) {
            zipTarget(target);
        }
    }
}
