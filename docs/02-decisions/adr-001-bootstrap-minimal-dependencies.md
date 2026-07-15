# ADR-001 — Bootstrap loads only framework infrastructure, no application dependencies

**Status:** `[APPROVED]`
**Date:** 2026-04-06

---

## Context

Every HTTP request passes through `Bootstrap`. The question was: what does Bootstrap load, and when?

A typical web application has heavy dependencies — an ORM (Doctrine), a database connection, mailers, third-party SDKs. Loading all of these on every request is expensive, even when the response could be served from cache without touching the database at all.

## Decision

`Bootstrap` loads only the minimal framework infrastructure required to determine what the response should be:

- `CacheManager` — to check whether a cached response exists
- `FileFinder` — to locate files across vendor and override paths
- `ConfigManager` — to read framework configuration

Everything else — ORM, database, application services, third-party libraries — is **not loaded by Bootstrap**. These dependencies are registered in the DI container as lazy closures and only instantiated when a component explicitly requests them.

## Reasoning

The core idea is the **cache-first path**:

```
Request arrives
  → Bootstrap (CacheManager, FileFinder, ConfigManager only)
  → Cache hit?  YES → serve cached HTML, done.
                      Doctrine was never touched.
  → Cache hit?  NO  → Router → Controller → Services → Doctrine → DB
                      → generate response → cache it → return
```

If a page is cached, the entire application stack (ORM, DB connection, service layer) is never loaded. This makes cached responses extremely fast and independent of database availability.

The DI container makes this possible: registering a service (`set()`) does not instantiate it. Instantiation only happens on the first `get()` call. Heavy dependencies declared in module services are therefore invisible to Bootstrap.

## Consequences

**Easier:**
- Cached responses are served with minimal overhead
- Bootstrap remains fast and testable in isolation
- Adding new dependencies to the application does not slow down Bootstrap
- The framework can serve static or cached pages even if the database is unavailable

**Harder:**
- Dependencies that are needed early (before routing) must be registered in Bootstrap explicitly — this must be a conscious decision
- Developers must not add heavy dependencies to Bootstrap without a clear reason

**Rule for contributors:** If you are considering adding an `import` or `DI::set()` call to `Bootstrap`, ask first: is this needed on every request, or only when the full application stack runs? If the latter, it belongs in a service, not in Bootstrap.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Load all dependencies in Bootstrap | Every request pays the full cost — DB connection, ORM setup — even for cached pages. Unacceptable. |
| Lazy-load dependencies with `require` inside methods | Works, but bypasses the DI container and makes dependencies invisible and untestable. |
| Split into multiple bootstrap stages (micro / full) | More flexibility, but added complexity with little benefit given DI already provides lazy loading. |
