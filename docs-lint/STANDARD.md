# Topic-Doc Standard (machine-readable for Claude)

This standard targets fast, unambiguous reading by Claude.
Every rule below is enforced by `npm run docs:check` unless marked _(advisory)_.

---

## Audience

Topic docs are primarily read by Claude, not humans.
Goals: fast parse, single interpretation, low token cost.
Optimize for the model, not for prose readability.

---

## File location

`docs/topics/{topic-name}.md` — one file per work area.
Topic name: lowercase, hyphenated (e.g. `persistence-file`, `css-backend`).

---

## Required structure

```markdown
# {topic-name}

YYYY-MM-DD              ← last meaningful update

## entry                ← 1–3 most relevant entry-point files
## file map             ← exhaustive list of relevant SOURCE/RUNTIME paths
## mental model         ← one short paragraph, then bullets
## rules                ← MUST / MUST NOT, phrased as action triggers
## known issues         ← "don't assume X" warnings or "None documented."
## pending              ← concrete TODOs or "None documented."
```

Optional sections (allowed between or after required ones):
`## flow`, `## api`, `## see also`, plus topic-specific (e.g. `## envelope structure`).

Required-section order is enforced. Optional sections may sit between.

---

## Section rules

### `## entry`

- 1 to 3 numbered items. Never more.
- Each: `path/to/file.php` — short reason why this is the entry point.
- The file must appear in `## file map`.

### `## file map`

- Each line: `SOURCE=/absolute/path` or `RUNTIME=/absolute/path`.
- Path begins with `/`, rooted at repo root.
- All listed paths must exist on disk (linter checks).
- `SOURCE=` for source-controlled files.
- `RUNTIME=` for files generated/written at runtime (installed configs, data files).

### `## mental model`

- One opening paragraph (≤ 5 sentences) describing relationships and flow.
- Then bullet list of key facts not obvious from the file map.
- Avoid prose-heavy explanations — bullets win when facts are independent.

### `## rules`

- Markdown list, each item contains `MUST` or `MUST NOT`.
- Phrase as action trigger, not abstract architecture statement:
  - ❌ `All Fetch responses MUST use FetchResponse envelope`
  - ✅ `When generating a controller method that returns fetch data → MUST construct \`FetchResponse\`, never \`JsonResponse\``
- Each rule is independent and self-contained.

### `## known issues`

- Warnings for the reader. Phrase as "don't assume X":
  - ✅ `BUG-P001: don't trust \`Naming::toCamelCase\` for camelCase input — produces \`Passwordhash\` instead of \`PasswordHash\``
- Use stable IDs (e.g. `BUG-P001`, `ARCH-P002`) when an external review references them.
- Empty section: write `- None documented.` (advisory).

### `## pending`

- Concrete TODOs, action-oriented.
- Each item: short description + optional ID linking to a review.
- Empty section: write `- None documented.` (advisory).

### `## see also` (optional)

- Only if there is a concrete cross-topic dependency worth loading.
- Format: `- [\`other-topic.md\`](other-topic.md) — concrete reason`
- Linter validates that linked files exist.

---

## Style rules

### Language

- English only in topic body (advisory, not yet linter-enforced).
- Eigennamen / domain-specific German terms allowed inline (e.g. `Werkbank`, `Anmeldedaten` in code/data).

### Code blocks

- Always fenced with language tag: ` ```php `, ` ```javascript `, ` ```json `, ` ```html `, ` ```css `, ` ```scss `, ` ```ini `.
- Plain config samples without language: ` ```text ` or ` ``` ` is acceptable for ASCII-art file trees.
- No 4-space-indented code blocks.

### Tables

- Prefer over prose when comparing 3+ items along the same dimensions.
- 2–3 columns. Avoid wide tables that wrap.
- No empty cells (use `—` or `n/a`).

### Lists

- Use `-` for unordered, `1.` for ordered.
- One concept per item.

### Inline code

- Use backticks for: file paths, class names, method names, config keys, CLI commands.

---

## Linter checks

Enforced by `npm run docs:check`:

| Check | Hard fail |
|---|---|
| Title `# {topic}` on line 1 | yes |
| Date `YYYY-MM-DD` after title | yes |
| All required sections present, lowercase | yes |
| Required-section order correct | yes |
| `## file map` contains ≥1 `SOURCE=/...` | yes |
| Every `SOURCE=` / `RUNTIME=` path exists | yes |
| `## rules` items contain `MUST` / `MUST NOT` | yes |
| Code blocks use ` ```{lang} ` fence (no indented blocks) | yes |
| `## see also` Markdown links resolve to existing files | yes |
| Sections not empty (`-` items or `None documented.`) | advisory |

Run: `npm run docs:check`. Must be green before commits to topic docs.
