# z77/module-frontend

The public website of a z77 installation: routing to pages through the navigation and its
aliases, the page layout (header, main navigation, footer), the language switch, the content
block renderers, the public contact form, and the admin overlay that lets a signed-in editor
switch to the backend or edit a page in place.

Read-only split from [z77-ch/z77-framework](https://github.com/z77-ch/z77-framework). Do not
commit here — issues and pull requests go to the monorepo.

## What it does

- **Starter pages** — `Main/IndexController` renders seven pages (home, about, services,
  contact, thanks, legal notice, privacy policy). Each page reads ONE content document per
  language (`data/content/<slug>.<lang>.json`, seeded on the first install, then the
  customer's), shaped by the blueprint of the same slug in `frontendConfig['contentBlueprints']`
  (ADR-044). The templates hold no text — they assemble section partials
  (`partials/sections/*`). A deleted page answers 404; updates never bring it back.
- **Content blocks** — renderers registered under `frontendConfig['contentBlocks']`: hero,
  feature grid, prose section, text section, cards, image + text, call to action, form intro,
  footer ([`block-types.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/block-types.md)).
  The same blocks are edited in the backend (Webseiten › Inhalte) and on the page itself.
- **Navigation and languages** — menus come from the navigation slots `frontend-main`
  («Hauptnavigation»), `frontend-meta` («Fusszeile») and `frontend-route` (routable, not in a
  menu); URLs from the navigation aliases (`/about`, `/contact`, …) with a language prefix for
  every language but the default (e.g. `/en/…`) and
  translated route slugs.
- **Contact form** — a public form with CSRF, bot and rate checks through the kernel `PublicFormHandler` (`ContactFormDefinition`,
  `public-form.js`; a geo guard is opt-in per form, `withGeoGuard()`); recipients are set in the backend (Service › E-Mail). See
  [`forms.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/forms.md).
- **Admin overlay** — for a signed-in editor or admin on a full page: the way to the backend,
  the switch «Seite bearbeiten» (in-place editing of a page's blocks), routing info and the
  partial-label overlay for admins, «Abmelden». Rendered only when allowed; guests and members
  get no markup.
- **Preview bar** — shown while a content variant set is previewed.
- **Web statistics beacon** — `/frontend/main/stats/event` counts events the project declares
  (a PDF opened, an outbound link); page views are counted server-side by the kernel, without
  cookies ([`stats.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/stats.md)).
- **KI-Bot** — with `z77/kibot` installed and `config/client/kibot.inc.php` present, the
  routes `/frontend/main/kibot/{config,ask}` serve the website chat widget.
- **Lottie** — an optional `<lottie-figure>` web component for decorative animations
  ([`lottie.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/lottie.md)).
- **Styles** — SCSS per breakpoint (`mobile`, `tablet`, `desktop`, nav variants), compiled to
  `res/assets/css/` ([`css-frontend.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/css-frontend.md)).

## Routes

| Route | Controller / action | Role |
|---|---|---|
| `/`, `/home`, `/about`, `/services`, `/contact`, `/thanks`, `/legal`, `/privacy` (aliases, per language) | `main/index/{home,about,services,contact,thanks,legal,privacy}` | guest |
| `/frontend/main/index/check` | contact form field check (POST, fetch) | guest |
| `/frontend/main/admin-panel/toggle-content-edit` | the overlay's «Seite bearbeiten» switch | editor |
| `/frontend/main/admin-panel/toggle-partial-labels` | partial-label overlay (DEBUG only) | admin |
| `/frontend/main/stats/event` | statistics beacon, always 204 | guest |
| `/frontend/main/kibot/{config,ask}` | website KI-Bot (fetch only, 404 when not configured) | guest |

The module baseline is `superUser`; the `controllers.main['*']` wildcard opens every action
to guests, and `AdminPanelController` deviates upward. Full pages are page-cached
(`cache.ttl` 86400 s, the contact form excluded); editor and admin sessions never use the cache.

## Requirements

- PHP ≥ 8.2, `z77/kernel` ^1.0.
- Optional: `z77/kibot` for the website bot; `z77/module-backend` for editing the content.

## Enabling it in a project

Don't start a project with `composer require`. Use the
[z77-skeleton](https://github.com/z77-ch/z77-skeleton) template — **Use this template** →
`composer install`. It wires the four web-stack packages (kernel, module-frontend,
module-backend, module-dms), the installer, and the override structure.
`composer require z77/module-frontend` only adds this module to an **existing** z77 project.

A project's own pages, templates, blocks and styles go into `override/` (CE principle): a
project controller extends `AbstractFrontendController` (so editors keep the admin overlay),
a template of the same path replaces the shipped one, extra block renderers and blueprints go
into the project's `frontendConfig`. How to add a page:
[`create-page.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/create-page.md).

## Not built yet

- A new page with its own content document still needs a controller action and a blueprint
  (code); pages cannot be created from the backend alone.
- The frontend loads the Inter web font from Google Fonts (`partials/head/fonts.tpl.php`),
  against the system-font decision — open in `css-frontend.md` (CSS-FRONTEND-FONTS-001).

## Documentation

[`content.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/content.md),
[`navigation.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/navigation.md),
[`block-types.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/block-types.md),
[`css-frontend.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/css-frontend.md),
[`i18n.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/i18n.md),
[`backend.md`](https://github.com/z77-ch/z77-framework/blob/main/docs/topics/backend.md) «frontend admin overlay».

## License

MIT — see the monorepo. Author: Peter Ruepp, [z77.ch](https://z77.ch).
