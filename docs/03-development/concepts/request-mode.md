# Concept: Request Mode — Page vs. Fetch

**Status:** `[IMPLEMENTED]`
**Date:** 2026-04-28

---

## Problem

Every incoming HTTP request reaches `Request::runParsing()`. Not all requests are equal:

- A browser navigation expects a complete HTML document — skeleton, navigation, CSS/JS pipeline
- A JavaScript `fetch()` call expects a data or partial response — no skeleton needed

The old approach used `IS_AJAX_HTTP_REQUEST` (a query parameter hack) and `IS_XML_HTTP_REQUEST`
(the legacy jQuery `X-Requested-With` header, unused by modern `fetch()`). Both were global
PHP constants — untestable, unreliable, and obsolete.

---

## Decision

Introduce a `RequestMode` enum with two cases:

| Mode | Meaning |
|---|---|
| `Page` | Browser navigation — expects a full HTML document |
| `Fetch` | JavaScript-initiated sub-request — expects data or partial HTML |

`RequestMode` is resolved once in `Request::__construct()` and stored as `$this->mode`.
It is exposed via `Request::getMode(): RequestMode`.

---

## Detection

Detection uses the `Sec-Fetch-Mode` header, which browsers set automatically on every request
since Chrome 76 / Firefox 90 / Safari 16.4. No JavaScript configuration required.

| `Sec-Fetch-Mode` value | Meaning | Mode |
|---|---|---|
| `navigate` | Browser navigating to a new page | `Page` |
| `same-origin` | `fetch()` to own server | `Fetch` |
| `cors` | `fetch()` cross-origin | `Fetch` |
| *(absent)* | Older browser, crawler, or non-browser client | `Page` |

**No fallback.** z77 is a website framework, not an API backend. Non-browser clients
(Postman, mobile apps, server-to-server) are not supported via this routing — API access
is treated as a separate concern. Crawlers (Googlebot etc.) do not send `Sec-Fetch-Mode`
and correctly fall through to `Page`, which is the desired behavior (full HTML response).

```php
private function resolveRequestMode(): RequestMode
{
    $fetchMode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';

    if ($fetchMode !== '' && $fetchMode !== 'navigate') {
        return RequestMode::Fetch;
    }

    // NOTE: Accept-header fallback intentionally removed.
    // z77 does not serve as an API backend — API access is a separate concern.
    // Non-browser clients (Postman, mobile apps) are not supported via this routing.

    return RequestMode::Page;
}
```

**Static assets** (images, scripts, stylesheets) also send `Sec-Fetch-Mode: no-cors`, but
they never reach `Request.php` — `.htaccess` serves them directly. No conflict.

---

## Impact on runParsing()

`RequestMode` is the first branch in `Request::runParsing()` — before any navigation lookup:

```
runParsing():
  1. extractLanguage()          always, regardless of mode
  2. mode === Fetch?
       YES → parsePathSegments() directly — no navigation lookup, no Router call
       NO  → Page flow: navigation lookup → convention fallback
```

**Why Fetch skips navigation lookup:**
Navigation entries represent pages a user navigates to. A `fetch()` call is a direct
action call — it targets a specific controller/action via URL convention. Consulting the
navigation tree for a data request is unnecessary overhead.

---

## Impact on LayoutManager

`LayoutManager::initialize()` uses `RequestMode` to choose the skeleton template:

```php
if (DI::getRequest()->getMode() === RequestMode::Fetch) {
    $this->setSkeletonTemplate('html-fetch-skeleton');
    return $this;
}
// Page: load full layoutConfig.inc.php
```

The action itself has no knowledge of this — it always returns an `HtmlResponse`.
`LayoutManager` decides the rendering strategy transparently.

---

## Files

| File | Role |
|---|---|
| `packages/kernel/core/src/Http/RequestMode.php` | Enum: `Page`, `Fetch` |
| `packages/kernel/core/src/Http/Request.php` | `resolveRequestMode()`, `getMode()` |
| `packages/kernel/core/src/Services/LayoutManager.php` | Checks mode in `initialize()` |

---

## Replaced

| Old | Replaced by |
|---|---|
| `define('IS_XML_HTTP_REQUEST', ...)` | Removed — unreliable (jQuery-era header) |
| `define('IS_AJAX_HTTP_REQUEST', ...)` | Removed — query parameter hack |
| Global constants | `RequestMode` enum on the Request object |
