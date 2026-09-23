#!/usr/bin/env node
/*
 * build-js — produce the `.min.js` that production serves.
 *
 * `JavascriptManager` serves `name.min.js` in production and `name.js` in
 * debug (docs/topics/js-build.md). Nothing generated those `.min.js` files
 * until now: they were minified by hand, so some were stale, five were plain
 * copies of the commented source, and four were missing (the manager then
 * falls back to the unminified file and writes a warning on every request).
 *
 * This script is the missing half of the CSS pipeline in `package.json`:
 *   npm run build:js        write every .min.js that is out of date
 *   npm run build:js -- --check   write nothing, exit 1 if one is out of date
 *
 * It needs terser on PATH (`npm i -g terser`) — deliberately not a
 * devDependency: it is a maintainer tool, a project never runs it.
 */

import { execFileSync } from 'node:child_process';
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
 * terser is run as a node script, not through a shell: on Windows the PATH
 * entry is a `.cmd` shim, and `shell: true` would concatenate our arguments
 * into a command line instead of passing them (node DEP0190). Resolving the
 * package's own entry point avoids both the shim and the quoting.
 */
function terserEntry() {
    const prefix = process.env.npm_config_prefix;
    const roots = [
        join(ROOT, 'node_modules'),                                  // local, if a project ever adds it
        ...(prefix ? [join(prefix, 'node_modules'), join(prefix, 'lib', 'node_modules')] : []),
        ...(process.env.APPDATA ? [join(process.env.APPDATA, 'npm', 'node_modules')] : []),
        '/usr/local/lib/node_modules',
        '/usr/lib/node_modules',
    ];

    for (const root of roots) {
        const entry = join(root, 'terser', 'bin', 'terser');
        try {
            statSync(entry);
            return entry;
        } catch { /* keep looking */ }
    }
    console.error('terser not found — install it with: npm i -g terser');
    process.exit(2);
}

const TERSER = terserEntry();

function minify(path) {
    // Defaults on purpose: terser mangles local scopes only, so the globals
    // the templates call (`_Z77`, `_z77CollectFormData`) keep their names, and
    // property names (`el.name`, `file.name`) are never touched.
    // No source map — production ships none today and the versioned file name
    // (`name_at-{mtime}.min.js`) would have to carry one too.
    return execFileSync(process.execPath, [TERSER, path, '--compress', '--mangle'], {
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });
}

const rows = [];
let changed = 0;
let failed = 0;

for (const dir of assetDirs()) {
    for (const src of sources(dir)) {
        const target = src.slice(0, -3) + '.min.js';
        const source = readFileSync(src, 'utf8');
        const vendor = source.slice(0, MARKER_WINDOW).includes(VENDOR_MARKER);

        let output;
        try {
            output = vendor ? source : minify(src);
        } catch (error) {
            rows.push([relative(ROOT, target), 'FEHLER', String(error.stderr || error.message).trim().split('\n')[0]]);
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
