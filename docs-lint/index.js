#!/usr/bin/env node

const fs = require("fs");
const path = require("path");

const ROOT = path.resolve(__dirname, "..");
const TOPICS_DIR = path.join(ROOT, "docs", "topics");

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

    checkFileMap(lines, seen, violations);
    checkRules(lines, seen, violations);
    checkSeeAlso(lines, headings, fileDir, violations);
    checkCodeFences(lines, violations);
    checkEmptySections(lines, headings, violations);

    return violations;
}

function checkFileMap(lines, seen, violations) {
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
        const abs = path.join(ROOT, rel);
        if (!fs.existsSync(abs)) {
            violations.push({
                line: findLineInFile(lines, l),
                msg: `${kind} path does not exist: \`${m[2]}\``
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

function relPath(p) {
    return p.replace(ROOT + path.sep, "").replace(/\\/g, "/");
}

function main() {
    if (!fs.existsSync(TOPICS_DIR)) {
        console.error(`topics dir not found: ${TOPICS_DIR}`);
        process.exit(2);
    }

    const files = fs.readdirSync(TOPICS_DIR)
        .filter(f => f.endsWith(".md"))
        .map(f => path.join(TOPICS_DIR, f))
        .sort();

    let totalViolations = 0;
    let cleanFiles = 0;

    for (const file of files) {
        const violations = lintFile(file);
        if (violations.length === 0) {
            cleanFiles++;
            console.log(`OK  ${relPath(file)}`);
        } else {
            totalViolations += violations.length;
            console.log(`FAIL ${relPath(file)}`);
            for (const v of violations) {
                const loc = v.line > 0 ? `:${v.line}` : "";
                console.log(`     ${relPath(file)}${loc}  ${v.msg}`);
            }
        }
    }

    console.log("");
    console.log(`${cleanFiles}/${files.length} files clean, ${totalViolations} violations`);

    if (totalViolations > 0) process.exit(1);
}

main();
