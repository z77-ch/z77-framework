#!/usr/bin/env node
/*
 * build-js — produce the `.min.js` that production serves.
 *
 * `JavascriptManager` serves `name.min.js` in production and `name.js` in
 * debug (docs/topics/js-build.md, JS-BUILD-002).
 *
 * ONE minifier, two ways to run it (owner decision 2026-11-11): the owner's VS
 * Code extension «autominify» (minify on save) and this script give the SAME
 * bytes — both run terser with the extension's options `{ mangle: false }`, and
 * this script prefers the terser the extension bundles. The assistant minifies
 * with it after every change to a `*.js`; `php tests/js-min-markers.php` checks
 * the result either way.
 *
 *   npm run build:js -- <file.js> …   minify the named sources (the usual call)
 *   npm run build:js                  every source under packages/…/res/assets/js
 *   npm run build:js -- --check       write nothing, exit 1 if one differs
 *
 * terser is looked up in: the autominify extension, a local node_modules, the
 * global npm root — deliberately not a devDependency: a project never runs it.
 */

import { createRequire } from 'node:module';
import { homedir } from 'node:os';
import { readdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const PACKAGES = join(ROOT, 'packages');

/*
 * A third-party bundle that already ships minified is COPIED, never run
 * through terser again: re-mangling a foreign build can break code that
 * relies on its own function names (lottie stringifies functions to start a
 * web worker). The marker sits in the file it is about — no list to maintain
 * elsewhere, and it explains itself where a reader meets it.
 */
const VENDOR_MARKER = '@z77-js: vendor';
const MARKER_WINDOW = 400; // bytes — the marker belongs in the first line

const check = process.argv.includes('--check');
const named = process.argv.slice(2).filter((arg) => !arg.startsWith('--')).map((arg) => resolve(arg));

/** Every `*.js` under a package's `res/assets/js`, excluding `*.min.js`. */
function sources(dir, found = []) {
    let entries;
    try {
        entries = readdirSync(dir, { withFileTypes: true });
    } catch {
        return found;
    }
    for (const entry of entries) {
        const path = join(dir, entry.name);
        if (entry.isDirectory()) {
            sources(path, found);
        } else if (entry.name.endsWith('.js') && !entry.name.endsWith('.min.js')) {
            found.push(path);
        }
    }
    return found;
}

/** `packages/<pkg>/res/assets/js` and `packages/kernel/<pkg>/res/assets/js`. */
function assetDirs() {
    const dirs = [];
    for (const pkg of readdirSync(PACKAGES, { withFileTypes: true })) {
        if (!pkg.isDirectory()) continue;
        const own = join(PACKAGES, pkg.name, 'res', 'assets', 'js');
        if (exists(own)) dirs.push(own);
        for (const sub of readdirSync(join(PACKAGES, pkg.name), { withFileTypes: true })) {
            if (!sub.isDirectory()) continue;
            const nested = join(PACKAGES, pkg.name, sub.name, 'res', 'assets', 'js');
            if (exists(nested)) dirs.push(nested);
        }
    }
    return dirs;
}

function exists(path) {
    try {
        return statSync(path).isDirectory();
    } catch {
        return false;
    }
}

/*
 * The extension's terser first: the same version as a save in VS Code, so both
 * ways write identical bytes. Then a local or a global install.
 */
function terserModule() {
    const roots = [];
    const extensions = join(homedir(), '.vscode', 'extensions');
    if (exists(extensions)) {
        for (const name of readdirSync(extensions).sort().reverse()) {
            if (name.startsWith('caffeineonice.autominify-')) roots.push(join(extensions, name, 'node_modules'));
        }
    }
    const prefix = process.env.npm_config_prefix;
    roots.push(
        join(ROOT, 'node_modules'),
        ...(prefix ? [join(prefix, 'node_modules'), join(prefix, 'lib', 'node_modules')] : []),
        ...(process.env.APPDATA ? [join(process.env.APPDATA, 'npm', 'node_modules')] : []),
        '/usr/local/lib/node_modules',
        '/usr/lib/node_modules',
    );

    for (const root of roots) {
        if (exists(join(root, 'terser'))) {
            return createRequire(join(root, 'noop.js'))('terser');
        }
    }
    console.error('terser not found — install the VS Code extension «autominify» or: npm i -g terser');
    process.exit(2);
}

const terser = terserModule();

// The extension's default options, on purpose: no mangling, so the globals the
// templates call (`_Z77`, `_z77CollectFormData`) and every local keep their
// names — what a save in VS Code writes. No source map — production ships none
// and the versioned file name (`name_at-{mtime}.min.js`) would have to carry one.
const OPTIONS = { mangle: false };

async function minify(source) {
    return (await terser.minify(source, OPTIONS)).code;
}

const rows = [];
let changed = 0;
let failed = 0;

const all = assetDirs().flatMap((dir) => sources(dir));
for (const path of named) {
    if (!all.includes(path)) {
        console.error(`not a source under packages/…/res/assets/js: ${relative(ROOT, path)}`);
        process.exit(2);
    }
}

{
    for (const src of (named.length ? named : all)) {
        const target = src.slice(0, -3) + '.min.js';
        const source = readFileSync(src, 'utf8');
        const vendor = source.slice(0, MARKER_WINDOW).includes(VENDOR_MARKER);

        let output;
        try {
            output = vendor ? source : await minify(source);
        } catch (error) {
            rows.push([relative(ROOT, target), 'FEHLER', String(error.message).trim().split('\n')[0]]);
            failed++;
            continue;
        }

        let current = null;
        try {
            current = readFileSync(target, 'utf8');
        } catch { /* not written yet */ }

        if (current === output) {
            rows.push([relative(ROOT, target), vendor ? 'vendor' : 'aktuell', '']);
            continue;
        }

        changed++;
        const saved = Math.round((1 - output.length / source.length) * 100);
        const note = vendor
            ? 'unveraendert kopiert (vendor)'
            : `${source.length} -> ${output.length} B (${saved}% kleiner)`;

        if (check) {
            rows.push([relative(ROOT, target), 'VERALTET', note]);
            continue;
        }

        // Only written when it actually differs: the served file name carries
        // the mtime (`name_at-{mtime}.min.js`) and the installer records the
        // published sha1 (ADR-046) — a no-op rewrite would churn both.
        writeFileSync(target, output);
        rows.push([relative(ROOT, target), 'geschrieben', note]);
    }
}

const width = Math.max(...rows.map(([path]) => path.length));
for (const [path, state, note] of rows.sort()) {
    console.log(`${path.padEnd(width)}  ${state.padEnd(12)}  ${note}`);
}

console.log('');
if (failed) {
    console.error(`${failed} Datei(en) konnten nicht minifiziert werden.`);
    process.exit(1);
}
if (check && changed) {
    console.error(`${changed} .min.js sind veraltet — "npm run build:js" ausfuehren.`);
    process.exit(1);
}
console.log(check ? 'Alle .min.js sind aktuell.' : `${changed} Datei(en) geschrieben, ${rows.length - changed} bereits aktuell.`);
