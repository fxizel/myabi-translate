// Construit dist/chrome/ et, avec --zip, génère l'archive dans dist/packages/.
import { build, context } from 'esbuild';
import { zipSync } from 'fflate';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, watch, writeFileSync } from 'node:fs';
import { dirname, join, posix, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outDir = join(root, 'dist', 'chrome');
const args = process.argv.slice(2);
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

/** Fichiers non TypeScript : manifeste (version injectée depuis package.json), pages HTML/CSS, icônes. */
function copyStatic() {
    mkdirSync(outDir, { recursive: true });
    const manifest = JSON.parse(readFileSync(join(root, 'manifest.chrome.json'), 'utf8'));
    manifest.version = pkg.version;
    writeFileSync(join(outDir, 'manifest.json'), `${JSON.stringify(manifest, null, 4)}\n`);
    for (const [from, to] of staticFiles) {
        cpSync(join(root, from), join(outDir, to));
    }
    cpSync(join(root, 'assets', 'icons'), join(outDir, 'icons'), { recursive: true });
}

const esbuildOptions = {
    absWorkingDir: root,
    entryPoints,
    outdir: outDir,
    bundle: true,
    format: 'iife',
    platform: 'browser',
    target: ['chrome110'],
    sourcemap: watching ? 'inline' : false,
    minify: false,
    legalComments: 'none',
    logLevel: 'info',
};

function listFiles(directory) {
    return readdirSync(directory).flatMap((name) => {
        const path = join(directory, name);
        return statSync(path).isDirectory() ? listFiles(path) : [path];
    });
}

/** Archive reproductible : ordre alphabétique, dates fixes, séparateurs « / ». */
function zipChrome() {
    const files = {};
    for (const file of listFiles(outDir).sort()) {
        files[posix.join(...relative(outDir, file).split(/[\\/]/))] = new Uint8Array(readFileSync(file));
    }
    const archive = zipSync(files, { level: 9, mtime: new Date('2020-01-01T00:00:00Z') });
    const packages = join(root, 'dist', 'packages');
    mkdirSync(packages, { recursive: true });
    const name = join(packages, `myab-translation-inspector-chrome-${pkg.version}.zip`);
    writeFileSync(name, archive);
    console.log(`Package Chrome : ${relative(root, name)} (${(archive.length / 1024).toFixed(1)} Ko)`);
}

if (!existsSync(join(root, 'assets', 'icons', 'icon-128.png'))) {
    console.error('Icônes manquantes : exécutez scripts/generate-icons.ps1 (voir README).');
    process.exit(1);
}

rmSync(outDir, { recursive: true, force: true });
copyStatic();
if (watching) {
    const ctx = await context(esbuildOptions);
    await ctx.watch();
    let timer;
    watch(join(root, 'src'), { recursive: true }, () => {
        clearTimeout(timer);
        timer = setTimeout(copyStatic, 100);
    });
    console.log('Surveillance de Chrome → dist/chrome (Ctrl+C pour arrêter)');
} else {
    await build(esbuildOptions);
    console.log('Build Chrome : dist/chrome');
    if (zipping) {
        zipChrome();
    }
}
