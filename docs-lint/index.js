#!/usr/bin/env node

const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");
const TOPICS_DIR = path.join(ROOT, "docs", "topics");
const HANDBOOK_DIR = path.join(ROOT, "docs", "01-handbook");
// The client-name denylist NAMES the clients, so it must not be published itself: it lives in the
// gitignored docs/_local/ (one maintainer's machine). Without the file the name check is skipped;
// the path and URL checks still run.
const HANDBOOK_CONFIG = path.join(ROOT, "docs", "_local", "docs-lint-clients.json");

/**
 * Handbook files are published with the framework, so they must not carry
 * content that only makes sense on the maintainer's machine or names a client.
 * These folders are gitignored working places (docs/_local/ = machine-specific
 * maintainer notes, docs/temp/ = drafts): mentioning them as a place is fine,
 * LINKING into them produces a link that is dead for every reader of the
 * published docs.
 */
const INTERNAL_DOC_DIRS = [
    path.join(ROOT, "docs", "_local"),
    path.join(ROOT, "docs", "temp")
];

const INTERNAL_PATTERNS = [
    { re: /(?<![A-Za-z0-9_])[A-Z]:\\/, what: "absolute machine path (drive letter)" },
    { re: /\b[A-Za-z]:\/Users\b/i, what: "absolute machine path (C:/Users)" },
    { re: /%USERPROFILE%/i, what: "absolute machine path (%USERPROFILE%)" },
    { re: /claude\.ai\/(?:code\/)?artifacts?\b/i, what: "claude.ai artifact URL" }
];

const HEADER_DATE_PATTERN = /^\*\*(Last updated|Date):\*\*\s*\d{4}-\d{2}-\d{2}/;

/**
 * `RUNTIME=` paths point at files an INSTALLATION writes, and every one of them
 * currently lives in `skeleton/` — a local dev installation that is not in the
 * repository (only `composer.json`, `composer.lock` and `dev.bat` are). Without
 * it, checking those paths would fail for everyone who just cloned, which turns
 * a deterministic linter into noise on day one.
 *
 * So: when the skeleton is not installed, its RUNTIME paths are SKIPPED and
 * counted, and the run says so. `SOURCE=` paths are always checked — they are
 * repository files and their absence is a real defect.
 *
 * Installed is decided by `skeleton/vendor`, the thing `composer install`
 * creates. Rebuild the tree with `composer install` inside `skeleton/` and the
 * paths are checked again with no change here.
 *
 * Even on an installed skeleton a missing RUNTIME path is ADVISORY, not a
 * violation: some runtime files only come into being through an action beyond
 * `composer install` — admin provisioning (`backendUsers.json`), the GeoIP
 * download job (`GeoLite2-Country.mmdb`), hand-written feature config. Their
 * existence depends on which features this machine has exercised, and a check
 * that is red forever gets ignored. The linter reports them as notes and stays
 * green; only SOURCE paths fail the run.
 */
const SKELETON_PREFIX = "skeleton/";
const skeletonInstalled = fs.existsSync(path.join(ROOT, "skeleton", "vendor"));
let skippedRuntimePaths = 0;

const REQUIRED_SECTIONS = [
    "entry",
    "file map",
    "mental model",
    "rules",
    "known issues",
    "pending"
];

const DATE_PATTERN = /^\d{4}-\d{2}-\d{2}$/;
const ALLOWED_FENCE_LANGS = new Set([
    "php", "javascript", "js", "json", "html", "css", "scss",
    "ini", "bash", "shell", "sh", "text", "markdown", "md"
]);

function lintFile(filePath) {
    const violations = [];
    const advisories = [];
    const raw = fs.readFileSync(filePath, "utf-8");
    const lines = raw.split(/\r?\n/);
    const fileDir = path.dirname(filePath);

    const title = lines[0] || "";
    if (!/^# \S/.test(title)) {
        violations.push({
            line: 1,
            msg: `first line must be a title \`# {topic-name}\`, got: ${JSON.stringify(title)}`
        });
    }

    let dateIdx = -1;
    for (let i = 1; i < lines.length; i++) {
        const t = lines[i].trim();
        if (t.length === 0) continue;
        dateIdx = i;
        break;
    }
    if (dateIdx === -1) {
        violations.push({ line: 2, msg: "missing date line after title" });
    } else {
        const dateLine = lines[dateIdx].trim();
        if (!DATE_PATTERN.test(dateLine)) {
            violations.push({
                line: dateIdx + 1,
                msg: `expected date \`YYYY-MM-DD\` after title, got: ${JSON.stringify(dateLine)}`
            });
        }
    }

    const headings = [];
    for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/^##\s+(.+?)\s*$/);
        if (m) headings.push({ line: i + 1, raw: m[1], lower: m[1].toLowerCase() });
    }

    for (const h of headings) {
        if (h.raw !== h.lower && REQUIRED_SECTIONS.includes(h.lower)) {
            violations.push({
                line: h.line,
                msg: `heading \`## ${h.raw}\` must be lowercase \`## ${h.lower}\``
            });
        }
    }

    const seen = {};
    for (const sec of REQUIRED_SECTIONS) seen[sec] = null;
    for (const h of headings) {
        if (REQUIRED_SECTIONS.includes(h.raw) && seen[h.raw] === null) {
            seen[h.raw] = h.line;
        }
    }

    for (const sec of REQUIRED_SECTIONS) {
        if (seen[sec] === null) {
            violations.push({ line: 0, msg: `missing required section \`## ${sec}\`` });
        }
    }

    const presentLines = REQUIRED_SECTIONS
        .map(sec => ({ sec, line: seen[sec] }))
        .filter(x => x.line !== null);
    for (let i = 1; i < presentLines.length; i++) {
        if (presentLines[i].line < presentLines[i - 1].line) {
            violations.push({
                line: presentLines[i].line,
                msg: `section \`## ${presentLines[i].sec}\` must appear after \`## ${presentLines[i - 1].sec}\` (currently before)`
            });
        }
    }

    checkFileMap(lines, seen, violations, advisories);
    checkRules(lines, seen, violations);
    checkSeeAlso(lines, headings, fileDir, violations);
    checkCodeFences(lines, violations);
    checkEmptySections(lines, headings, violations);

    return { violations, advisories };
}

function checkFileMap(lines, seen, violations, advisories) {
    if (seen["file map"] === null) return;
    const sectionLines = extractSection(lines, "file map");
    const sourceLines = sectionLines.filter(l => /^\s*(SOURCE|RUNTIME)\s*=\s*\S+/.test(l));
    if (sourceLines.filter(l => /^\s*SOURCE\s*=/.test(l)).length === 0) {
        violations.push({
            line: seen["file map"],
            msg: "`## file map` must contain at least one `SOURCE=/...` entry"
        });
    }
    for (const l of sourceLines) {
        const m = l.match(/^\s*(SOURCE|RUNTIME)\s*=\s*(\S+)\s*$/);
        if (!m) continue;
        const kind = m[1];
        const rel = m[2].replace(/^\//, "");
        if (kind === "RUNTIME" && !skeletonInstalled && rel.startsWith(SKELETON_PREFIX)) {
            skippedRuntimePaths++;
            continue;
        }
        const abs = path.join(ROOT, rel);
        if (!fs.existsSync(abs)) {
            // RUNTIME files may legitimately be absent (created by a later
            // action, not by `composer install`) — advisory. SOURCE files are
            // repository content — their absence fails the run.
            (kind === "RUNTIME" ? advisories : violations).push({
                line: findLineInFile(lines, l),
                msg: kind === "RUNTIME"
                    ? `${kind} path does not exist: \`${m[2]}\` (advisory — created at runtime, not by composer install)`
                    : `${kind} path does not exist: \`${m[2]}\``
            });
        }
    }
}

function checkRules(lines, seen, violations) {
    if (seen["rules"] === null) return;
    const sectionLines = extractSection(lines, "rules");
    const ruleItems = sectionLines.filter(l => /^\s*-\s+\S/.test(l));
    if (ruleItems.length === 0) {
        violations.push({
            line: seen["rules"],
            msg: "`## rules` must contain at least one list item"
        });
        return;
    }
    for (const item of ruleItems) {
        if (!/\bMUST(\s+NOT)?\b/.test(item)) {
            violations.push({
                line: findLineInFile(lines, item),
                msg: `rule item missing \`MUST\` / \`MUST NOT\`: ${item.trim()}`
            });
        }
    }
}

function checkSeeAlso(lines, headings, fileDir, violations) {
    const seeAlso = headings.find(h => h.raw === "see also");
    if (!seeAlso) return;
    const sectionLines = extractSection(lines, "see also");
    const linkPattern = /\[([^\]]+)\]\(([^)]+)\)/g;
    for (const l of sectionLines) {
        let match;
        while ((match = linkPattern.exec(l)) !== null) {
            const target = match[2].split("#")[0];
            if (!target || /^https?:\/\//.test(target)) continue;
            const abs = path.resolve(fileDir, target);
            if (!fs.existsSync(abs)) {
                violations.push({
                    line: findLineInFile(lines, l),
                    msg: `see-also link target does not exist: \`${target}\``
                });
            }
        }
    }
}

function checkCodeFences(lines, violations) {
    let inFence = false;
    let fenceStartLine = 0;
    for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/^```(\S*)\s*$/);
        if (!m) continue;
        if (!inFence) {
            inFence = true;
            fenceStartLine = i + 1;
            const lang = m[1].toLowerCase();
            if (lang === "") {
                violations.push({
                    line: i + 1,
                    msg: "code fence missing language tag (use ```{lang} — e.g. ```php, ```text)"
                });
            } else if (!ALLOWED_FENCE_LANGS.has(lang)) {
                violations.push({
                    line: i + 1,
                    msg: `unknown code-fence language: \`${m[1]}\` (allowed: ${[...ALLOWED_FENCE_LANGS].join(", ")})`
                });
            }
        } else {
            inFence = false;
        }
    }
    if (inFence) {
        violations.push({
            line: fenceStartLine,
            msg: "unclosed code fence"
        });
    }
}

function checkEmptySections(lines, headings, violations) {
    for (let i = 0; i < headings.length; i++) {
        const h = headings[i];
        const next = headings[i + 1];
        const startLine = h.line;
        const endLine = next ? next.line - 1 : lines.length;
        let hasContent = false;
        for (let j = startLine; j < endLine; j++) {
            const t = lines[j].trim();
            if (t.length > 0) { hasContent = true; break; }
        }
        if (!hasContent) {
            violations.push({
                line: h.line,
                msg: `section \`## ${h.raw}\` is empty — add content or \`- None documented.\``
            });
        }
    }
}

function extractSection(lines, sectionName) {
    const out = [];
    let inSection = false;
    for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/^##\s+(.+?)\s*$/);
        if (m) {
            if (m[1] === sectionName) {
                inSection = true;
                continue;
            }
            if (inSection) break;
            continue;
        }
        if (inSection) out.push(lines[i]);
    }
    return out;
}

function findLineInFile(lines, target) {
    for (let i = 0; i < lines.length; i++) {
        if (lines[i] === target) return i + 1;
    }
    return 0;
}

// ---------------------------------------------------------------------------
// Handbook rule set (docs/01-handbook/**/*.md)
//
// Handbook files have a FREE structure — none of the topic rules above apply.
// They are checked for what breaks or leaks once the docs are published:
// links that do not resolve, and internal-only content.
// ---------------------------------------------------------------------------

function loadHandbookConfig(configPath = HANDBOOK_CONFIG) {
    if (!fs.existsSync(configPath)) return { clientNames: [] };
    const cfg = JSON.parse(fs.readFileSync(configPath, "utf-8"));
    return {
        clientNames: (cfg.clientNames || []).map(n => String(n).trim()).filter(n => n.length > 0)
    };
}

function escapeRegExp(s) {
    return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

/**
 * Marks each line as inside / outside a fenced code block (``` or ~~~).
 * A fence closes on the same character with at least the opening length.
 */
function fenceMask(lines) {
    const mask = new Array(lines.length).fill(false);
    let open = null;
    for (let i = 0; i < lines.length; i++) {
        const m = lines[i].match(/^\s{0,3}(`{3,}|~{3,})/);
        if (open === null) {
            if (m) { open = m[1]; mask[i] = true; }
        } else {
            mask[i] = true;
            if (m && m[1][0] === open[0] && m[1].length >= open.length
                && lines[i].trim() === m[1]) {
                open = null;
            }
        }
    }
    return mask;
}

function stripCodeSpans(line) {
    return line.replace(/(`+)[\s\S]*?\1/g, m => " ".repeat(m.length));
}

/**
 * GitHub's heading anchor (github-slugger): rendered text, lowercased,
 * everything that is not a letter, mark, number, connector punctuation
 * (`_`), space or hyphen removed, each space turned into `-`.
 */
function githubSlug(headingText) {
    let t = headingText
        .replace(/<[^>]+>/g, "")
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, "$1")
        .replace(/`([^`]*)`/g, "$1")
        .replace(/(^|[^\p{L}\p{N}_])_([^_]+)_(?=[^\p{L}\p{N}_]|$)/gu, "$1$2");
    return t.trim().toLowerCase()
        .replace(/[^\p{L}\p{M}\p{N}\p{Pc} -]/gu, "")
        .replace(/ /g, "-");
}

const slugCache = new Map();

/** All anchors a Markdown file offers: ATX heading slugs (with GitHub's -1, -2 de-duplication) and explicit `<a id|name>`. */
function anchorsOf(filePath) {
    if (slugCache.has(filePath)) return slugCache.get(filePath);
    const lines = fs.readFileSync(filePath, "utf-8").split(/\r?\n/);
    const mask = fenceMask(lines);
    const anchors = new Set();
    const counts = {};
    for (let i = 0; i < lines.length; i++) {
        if (mask[i]) continue;
        const h = lines[i].match(/^\s{0,3}#{1,6}\s+(.*?)(?:\s+#+)?\s*$/);
        if (h) {
            const base = githubSlug(h[1]);
            const n = counts[base] || 0;
            anchors.add(n === 0 ? base : `${base}-${n}`);
            counts[base] = n + 1;
        }
        for (const a of lines[i].matchAll(/<a\s+[^>]*(?:id|name)\s*=\s*"([^"]+)"/gi)) {
            anchors.add(a[1]);
        }
    }
    slugCache.set(filePath, anchors);
    return anchors;
}

function isInside(abs, dir) {
    const rel = path.relative(dir, abs);
    return rel === "" || (!rel.startsWith("..") && !path.isAbsolute(rel));
}

/** Inline links / images `[t](target)` and reference definitions `[id]: target`, outside code. */
function linkTargets(line) {
    const out = [];
    const clean = stripCodeSpans(line);
    for (const m of clean.matchAll(/!?\[[^\]]*\]\(\s*(<[^>]*>|[^\s)]+)(?:\s+["'(][^)]*)?\s*\)/g)) {
        out.push(m[1].replace(/^<|>$/g, ""));
    }
    const def = clean.match(/^\s{0,3}\[[^\]]+\]:\s+(<[^>]*>|\S+)/);
    if (def) out.push(def[1].replace(/^<|>$/g, ""));
    return out;
}

function checkHandbookLinks(lines, mask, filePath, violations) {
    const fileDir = path.dirname(filePath);
    for (let i = 0; i < lines.length; i++) {
        if (mask[i]) continue;
        for (const target of linkTargets(lines[i])) {
            // external (http:, https:, mailto:, …) — not resolvable offline
            if (/^[a-z][a-z0-9+.-]*:/i.test(target)) continue;
            const hashAt = target.indexOf("#");
            let pathPart = hashAt === -1 ? target : target.slice(0, hashAt);
            const anchor = hashAt === -1 ? null : target.slice(hashAt + 1);
            try { pathPart = decodeURIComponent(pathPart); } catch (e) { /* keep raw */ }

            const abs = pathPart === ""
                ? filePath
                : (pathPart.startsWith("/") ? path.join(ROOT, pathPart) : path.resolve(fileDir, pathPart));

            if (INTERNAL_DOC_DIRS.some(d => isInside(abs, d))) {
                violations.push({
                    line: i + 1,
                    msg: `link into internal-only \`${relPath(INTERNAL_DOC_DIRS.find(d => isInside(abs, d)))}/\`: \`${target}\` (mention the place as text, do not link it)`
                });
                continue;
            }
            if (!fs.existsSync(abs)) {
                violations.push({ line: i + 1, msg: `link target does not exist: \`${target}\`` });
                continue;
            }
            if (anchor && abs.toLowerCase().endsWith(".md") && fs.statSync(abs).isFile()) {
                let slug = anchor;
                try { slug = decodeURIComponent(anchor); } catch (e) { /* keep raw */ }
                if (!anchorsOf(abs).has(slug.toLowerCase()) && !anchorsOf(abs).has(slug)) {
                    violations.push({ line: i + 1, msg: `anchor does not resolve: \`${target}\` (no heading with slug \`${slug}\`)` });
                }
            }
        }
    }
}

function checkInternalContent(lines, clientRe, violations) {
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        for (const p of INTERNAL_PATTERNS) {
            const m = line.match(p.re);
            if (m) {
                violations.push({ line: i + 1, msg: `internal-only content — ${p.what}: \`${snippet(line, m.index)}\`` });
            }
        }
        if (clientRe) {
            const names = new Set([...line.matchAll(clientRe)].map(m => m[0].toLowerCase()));
            for (const n of names) {
                violations.push({ line: i + 1, msg: `internal-only content — client name \`${n}\` (denylist in docs/_local/docs-lint-clients.json)` });
            }
        }
    }
}

function snippet(line, at) {
    const s = line.slice(Math.max(0, at - 10), at + 40).trim();
    return s.replace(/`/g, "'");
}

function checkHeaderDate(lines, mask, advisories) {
    for (let i = 0; i < lines.length; i++) {
        if (mask[i]) continue;
        if (/^##\s/.test(lines[i])) break;
        if (HEADER_DATE_PATTERN.test(lines[i].trim())) return;
    }
    advisories.push({
        line: 0,
        msg: "no `**Last updated:** YYYY-MM-DD` (or `**Date:** YYYY-MM-DD`) line in the header (advisory)"
    });
}

function lintHandbookFile(filePath, config = loadHandbookConfig()) {
    const violations = [];
    const advisories = [];
    const lines = fs.readFileSync(filePath, "utf-8").split(/\r?\n/);
    const mask = fenceMask(lines);
    const clientRe = config.clientNames.length > 0
        ? new RegExp(`\\b(?:${config.clientNames.map(escapeRegExp).join("|")})\\b`, "gi")
        : null;

    checkHandbookLinks(lines, mask, filePath, violations);
    checkInternalContent(lines, clientRe, violations);
    checkHeaderDate(lines, mask, advisories);

    violations.sort((a, b) => a.line - b.line);
    return { violations, advisories };
}

function listMarkdown(dir, recursive) {
    const out = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const abs = path.join(dir, entry.name);
        if (entry.isDirectory() && recursive) out.push(...listMarkdown(abs, true));
        else if (entry.isFile() && entry.name.endsWith(".md")) out.push(abs);
    }
    return out.sort();
}

// ---------------------------------------------------------------------------

function relPath(p) {
    return p.replace(ROOT + path.sep, "").replace(/\\/g, "/");
}

function runSet(label, files, lint) {
    let violations = 0;
    let advisories = 0;
    let clean = 0;
    console.log(`== ${label}`);
    for (const file of files) {
        const r = lint(file);
        if (r.violations.length === 0) {
            clean++;
            console.log(`OK  ${relPath(file)}`);
        } else {
            violations += r.violations.length;
            console.log(`FAIL ${relPath(file)}`);
            for (const v of r.violations) {
                const loc = v.line > 0 ? `:${v.line}` : "";
                console.log(`     ${relPath(file)}${loc}  ${v.msg}`);
            }
        }
        advisories += r.advisories.length;
        for (const a of r.advisories) {
            const loc = a.line > 0 ? `:${a.line}` : "";
            console.log(`     note ${relPath(file)}${loc}  ${a.msg}`);
        }
    }
    console.log("");
    return { label, files: files.length, clean, violations, advisories };
}

function main() {
    for (const dir of [TOPICS_DIR, HANDBOOK_DIR]) {
        if (!fs.existsSync(dir)) {
            console.error(`docs dir not found: ${dir}`);
            process.exit(2);
        }
    }

    const handbookConfig = loadHandbookConfig();
    const results = [
        runSet("topics", listMarkdown(TOPICS_DIR, false), lintFile),
        runSet("handbook", listMarkdown(HANDBOOK_DIR, true), f => lintHandbookFile(f, handbookConfig))
    ];

    if (skippedRuntimePaths > 0) {
        console.log(`note: skeleton not installed — ${skippedRuntimePaths} RUNTIME path(s) skipped (composer install in skeleton/ to check them)`);
    }
    for (const r of results) {
        console.log(`${(r.label + ":").padEnd(10)}${r.clean}/${r.files} files clean, ${r.violations} violations, ${r.advisories} advisories`);
    }

    if (results.some(r => r.violations > 0)) process.exit(1);
}

if (require.main === module) {
    main();
}

module.exports = { lintFile, lintHandbookFile, loadHandbookConfig, githubSlug, anchorsOf };
