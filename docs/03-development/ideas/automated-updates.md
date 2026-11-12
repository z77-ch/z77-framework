# Automated Updates — framework updates rolled out to installations without hand work

**Status:** `[IDEA]` — parked by the owner 2026-11-12 («stehen lassen, wir kommen vielleicht
darauf zurück»)
**Date:** 2026-11-12
**Context:** Brainstorming with the owner, after [invoice-layout-settings.md](invoice-layout-settings.md).
Starting point: «Updates automatisieren über git — birgt wohl auch Gefahren».

---

## Problem

Every client installation is updated by hand: `composer update` in the project, a release
built and uploaded, the switch. With more installations this is repetitive work.

## Dangers named (2026-11-12)

- **The server pulls code itself** (git pull / composer on the server): it needs repository
  credentials and write access to its own code — the same objection as a server replacing
  its template files (a backend compromise becomes remote code execution).
- **An update without a watcher:** if it breaks, the client notices first; a database
  migration cannot always be undone.
- **All installations at once:** one faulty release hits every client together.

## Sketch of a safe path

Built on the existing release system ([`../../../.releases/RULES.md`](../../../.releases/RULES.md)):

1. the update goes into a new `releases/<name>` — never over `current`;
2. a check run tests it there;
3. only then the switch; on failure the old release stays active;
4. triggered from the developer's PC (push of a release), never pulled by the server;
5. staged: one installation first, then the others.

## Open questions

- Which check run decides «good» (smoke test, `check.php`, a health URL)?
- Database migrations: run before or after the switch, and how is one rolled back?
- Who is told, and how (mail to the developer, to the client)?
- Relation to [`../PLAN-git-rollout.md`](../PLAN-git-rollout.md) «Versionierung &
  Update-Automatismus (Ziel d)».
