# Content Template System

**Status:** `[IDEA]`
**Date:** 2026-04-05
**Context:** Originated from FAQ module (studio-vonaarburg.ch) — Jodit WYSIWYG gives clients too much freedom

---

## Problem

Clients misuse WYSIWYG editors:
- Too many blank lines
- Unauthorized formatting (bold, italic)
- Wrong structures (multiple H2s, etc.)
- Copy-paste garbage from Word/PDF

HTMLPurifier as a post-filter only partially solves the problem and is difficult to configure.

## Solution

**Prescribe structure, don't permit it.** The editor only fills in simple text fields. The framework builds clean HTML from them.

**Core principle: template and values are always separate.**

---

## Concept

### ContentTemplate Entity

Defines the HTML structure with placeholders. Created once, reused everywhere.

```
ContentTemplate
├── id
├── name        "FAQ Standard", "FAQ with List", "Service Detail"
├── slug        "faq-standard" (unique, for code reference)
└── template    HTML string with placeholders
```

### Placeholder Syntax

| Placeholder | Generates | Description |
|---|---|---|
| `{h2}` | `<input type="text">` | Single input field |
| `{p}` | `<textarea>` | Multi-line text field |
| `{ul}[{li}]` | Dynamic list | Any number of entries (+/- button) |
| `{ul:3}[{li}]` | Fixed list | Exactly 3 entries |

### Example Template "FAQ Standard"

```html
<h2>{h2}</h2>
<p>{p}</p>
```

### Example Template "FAQ with List"

```html
<h2>{h2}</h2>
<p>{p}</p>
<ul>
    {ul}[<li>{li}</li>]
</ul>
```

### Value Storage (JSON)

```json
{
    "h2": "What does a website cost?",
    "p": "The cost depends on the scope...",
    "ul": ["Point 1", "Point 2", "Point 3"]
}
```

---

## Workflow

### Editing (Backend)
1. Load template → parse placeholders
2. Generate input form (`ContentTemplateFormBuilder`)
3. Fill JSON values into generated fields
4. No WYSIWYG, only simple inputs/textareas

### Saving
- Save form values as JSON in `content_values`
- No HTML stored in the database

### Rendering (Frontend)
- `ContentTemplateRenderer`: JSON + Template → final HTML
- HTML is only generated here — not when saving

---

## Affected Components (upon implementation)

| Component | Type | Description |
|---|---|---|
| `ContentTemplate` | Entity | Template definition |
| `ContentTemplateValidator` | Validator | Required fields, slug uniqueness |
| `ContentTemplateFormBuilder` | Helper | Template → HTML form |
| `ContentTemplateRenderer` | Helper | Template + JSON → final HTML |

## Integration into FAQ Module (first use case)

- `FaqGroup` gets relation to `ContentTemplate` (all FAQs in a group follow the same template)
- `Faq.answer` (free HTML string) is replaced by `Faq.content_values` (JSON)
- `Faq.template` → FK on `ContentTemplate` (or inherited via FaqGroup)

---

## Open Questions

- [ ] Template versioning: what happens when placeholders are renamed?
- [ ] Migration: existing `answer` HTML values need to be migrated once
- [ ] Nested placeholders: e.g. `{ul}[{li_title} / {li_text}]` — in scope?
- [ ] Where are templates managed? Dedicated backend area or hardcoded?

---

## Next Steps

If concept is approved → elaborate technical specification in `specs/content-template-system.md`.
