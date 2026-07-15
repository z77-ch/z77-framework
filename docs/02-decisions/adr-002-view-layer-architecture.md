# ADR-002 — View Layer Architecture

**Date:** 2026-04-14
**Status:** Superseded by ADR-003 and ADR-004

---

## Context

The framework needs a rendering system that works across multiple output channels: HTML pages, PDF documents, email/newsletter bodies, and AJAX responses. The goal is a clean architecture that avoids code duplication while keeping each channel simple and independent.

---

## Decision

### Core primitive: `TemplateRenderer`

A single shared class responsible for one thing only: PHP template + context → HTML string.

```php
class TemplateRenderer {
    public function render(string $path, array $context): string
    // extract($context, EXTR_SKIP) + ob_start + require + ob_get_clean
}
```

All output channels use this internally. No magic, no custom syntax — plain PHP templates.

---

### Output channels

| Channel | Class | Status | Notes |
|---|---|---|---|
| HTML page | `HtmlView` (current `View.php`) | In progress | Full skeleton with partials, CSS/JS pipeline |
| AJAX HTML fragment | `HtmlView` with `html-ajax-skeleton` | In progress | Only `$main` rendered, no nav/header/footer |
| AJAX status / data | `renderJson()` on base controller | Planned | Simple method, not a class |
| PDF | `PdfView` | v1.1 | Own templates, own CSS, hands off to Dompdf/mPDF |
| Email / Newsletter | `MailView` | v1.1 | Own templates, CSS must be inlined (Gmail etc.) |

---

### Channel details

#### HTML page

Orchestrated by `LayoutManager` → `HtmlView`.

Template hierarchy (configured via `layoutConfig.inc.php`):
- **level `head`**: sections `meta`, `seo`, `favicon`, `styles`, `scripts`, `social`
- **level `body`**: sections `header`, `main`, `footer`

Skeleton receives named variables: `$head`, `$header`, `$main`, `$footer`, `$css`, `$js`.

CSS/JS assets are managed by `LayoutManager` (versioned filenames via `StylesheetManager`).
The action template (`$main`) is resolved dynamically from the current controller/action — never hardcoded in module config.

#### AJAX HTML fragment

`IS_AJAX_HTTP_REQUEST` constant (defined by `Request::runParsing()`) triggers `html-ajax-skeleton` instead of the full skeleton. The ajax skeleton outputs only `$main`. The client's AJAX communicator injects the response into the target div by ID. This is the primary UI update mechanism — no client-side rendering.

#### AJAX status / data (JSON)

For actions without a UI response (save confirmations, validation errors, autocomplete data, job triggers):

```php
// AbstractBaseController:
protected function renderJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
```

Not a separate class — a method on the base controller.

#### PDF (v1.1)

- Separate template directory from HTML templates
- Own CSS (PDF engines support a CSS subset only — no flexbox/grid)
- `PdfView` renders template via `TemplateRenderer`, passes result to Dompdf or mPDF
- Controller calls directly: `$pdf = new PdfView(DI::getFileFinder()); $pdf->render(...);`

#### Email / Newsletter (v1.1)

- Separate email-specific templates (email HTML structure, limited CSS support)
- CSS **must** be inlined before sending — external stylesheets are ignored by Gmail/Outlook
- Dependency to add when building: `pelago/emogrifier` or `tijsverkoyen/css-to-inline-styles`
- `MailView` returns HTML string — the mailer (Symfony Mailer / PHPMailer) handles actual sending
- Newsletter flow: `JobController` → batch of 50 recipients → `MailView::getHtml()` → Mailer → repeat every few minutes

---

### What each controller uses

```
IndexController / most controllers   → AbstractBaseController::render()     → HtmlView
InvoiceController::downloadAction()  → new PdfView(...)                     → PdfView (v1.1)
NewsletterController                 → new MailView(...)                     → MailView (v1.1)
Any AJAX action (UI update)          → AbstractBaseController::render()      → HtmlView + ajax-skeleton
Any AJAX action (status/data)        → AbstractBaseController::renderJson()  → JSON
```

---

### Template directory convention

```
res/view/
    html/           ← HTML templates (current location: res/view/templates/)
    pdf/            ← PDF-specific templates (v1.1)
    mail/           ← Email templates (v1.1)
```

---

## Consequences

- `TemplateRenderer` is a small new class to extract from the current `View.php`
- `View.php` is renamed to `HtmlView.php` for consistency with upcoming `PdfView`/`MailView`
- `LayoutManager` context methods (`setContext`/`getContext`) are dead code — remove
- `renderJson()` is added to `AbstractBaseController`
- PDF and Mail are v1.1 — architecture is prepared, implementation deferred

---

## Rejected alternatives

**Custom template syntax** (`{{var}}`, `@if`, `@foreach`) — adds a parser/compiler with weeks of work, no meaningful advantage over plain PHP for server-side templates. Twig would be the right choice if custom syntax is ever needed.

**Single template for all channels** — HTML and PDF/email require fundamentally different layouts, CSS strategies, and asset handling. Sharing templates creates chaos.

**renderAs() on one controller** — rejected in favour of dedicated controllers per channel (`InvoiceController` → PDF, `NewsletterController` → email). Each controller has one output channel. Cleaner responsibility.
