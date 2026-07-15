# Vision — z77 Framework

**Status:** `[CURRENT]`
**Date:** 2026-04-05

## Why a new framework?

The existing Webdreams MVC Framework (wdv-6.2.2) has proven itself over the years, but carries technical debt and limitations that hinder new projects. z77 is the successor — built on the same proven principles, but cleanly documented, modular, and extensible.

## Goals

- **Cleanliness over speed** — fewer features done right, rather than many done poorly
- **Fully documented** — every architectural decision is traceable
- **Developer-friendly** — Claude Code can read, understand, and extend the code
- **Client-safe** — backend inputs are structured and controlled (no WYSIWYG chaos)
- **Reusable** — components (templates, widgets, etc.) are context-independent

## Differentiation from predecessor (wdv-6.2.2)

| Aspect | wdv-6.2.2 | z77 |
|---|---|---|
| Documentation | Implicit in code | Explicit in Markdown |
| Content input | Free WYSIWYG | Structured templates |
| Configuration | PHP constants | Configuration objects |
| Testing strategy | None | Defined |

## Core Principles

1. **Template ≠ Value** — structure and content are always separate
2. **CE-first** — Client Extensions override framework defaults (proven principle retained)
3. **Explicit over implicit** — no magic, everything traceable
4. **Markdown-first documentation** — readable for humans and AI
