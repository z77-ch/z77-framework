# Bauplan — Project pendenzen on the dashboard («Projekt-Pendenzen»)

**Status:** `[IN PROGRESS]` — decided; the owner accepted every recommendation D1–D8
(2026-11-19: «alle Empfehlungen»). **Built 2026-11-19 (phase A of «Ersteinrichtung», owner: «vor
dem Release, bauen, so schnell wie möglich»):** the registry `projectChecks`, the check interface,
the bridge contributor and the first-setup checks of financial, mandator and article — see
[«Ersteinrichtung (setup checks)»](#ersteinrichtung-setup-checks). Open: phase B (setup checks 4,
5, 7, 9, 10), then P1–P4 below (kernel health checks, content markers, screen, install step).
**Date:** 2026-11-19
**Origin:** owner proposal 2026-11-19; direction (two kinds of entries on the ADR-052 dashboard)
accepted by the owner the same day.
**Builds on:** [ADR-052](../02-decisions/adr-052-backend-dashboard-registry.md) (dashboard
registry, `canReach()`), [ADR-030](../02-decisions/adr-030-system-configuration.md)
(`canonicalBaseUrl`), [ADR-036](../02-decisions/adr-036-config-split-vendor-client.md)
(config vs data), [ADR-044](../02-decisions/adr-044-content-blueprints.md) (blueprints, keyed
blocks), [ADR-050](../02-decisions/adr-050-module-navigation-seeds.md) (navigation seeds).

---

## Summary (read this, then the decisions)

**What.** The installation tells its operator what is still unfinished about the PROJECT
itself — not business work (that is ADR-052 today), but setup debt: «mail links go to the
production domain while you are on `next`», «the imprint still has placeholders», «the privacy
policy has no section for the contact form», «debug is on at the live address».

**Why.** Today these are remembered by the developer or found by the client. Each one is a
legal or operational risk that the server could have named itself.

**Two kinds of entries, both on the existing dashboard:**

1. **Derived checks** — computed from the state on every dashboard load. They never go stale:
   fixed means gone. Each check is a small class with an id; modules contribute their own
   (Rule 9). Kept: 9 checks (table in §1). Dropped: 6 candidates (§1, with reasons).
2. **Manual list** — what no code can detect («have the imprint checked by a lawyer», «test mail
   delivery on the host»). Written by the AI assistant at installation (new step in
   `INSTALL-AI.md`), stored in `data/`, shown on the dashboard as one line «Projekt-Pendenzen
   offen (n)», ticked off on a new screen «Service › Projekt-Pendenzen».

**Open owner decisions** (details in §8):

| # | Question | Recommendation |
|---|---|---|
| D1 | Own small registry `projectChecks`, or plain dashboard contributors? | Own registry, bridged by ONE contributor |
| D2 | Placeholder marker in starter content | `[[ANPASSEN: …]]`, both languages; also recognise the two current prefixes |
| D3 | Privacy section detection | By block `key` in the `privacy` document; new starter slots `stats`, `member`, `kibot` |
| D4 | «Nicht zutreffend» for a derived check | Yes — SUPER_USER, reversible, listed on the screen |
| D5 | Admin accounts without 2FA | Not a derived check (2FA is optional by your decision); a manual item instead |
| D6 | Manual list: storage and how the AI writes it | `data/framework/project/tasks.json`, written directly by the AI; no CLI |
| D7 | Roles | Dashboard: `canReach()` of the target; screen and ticking ADMIN |
| D8 | Cache | Only the content scan, keyed by file mtimes; everything else live |

**Migration:** none (no database table; one new data file, created on first write; one
navigation seed entry, add-only by key). **Effort:** medium, ~4 build days in four phases (§7).

---

## Ersteinrichtung (setup checks)

**Owner 2026-11-19:** a freshly installed business suite tells a non-expert, on the dashboard,
what to set up next. Every step is a DERIVED check (computed from the data, gone when done). Not
all at once: in SETUP ORDER, only the next N open steps (N = `dashboardSetupSteps` in
`backendConfig`, optional, default 3), each one short German line linking to the screen that fixes
it (screen text minimal, Rule 8); the rest appear as the earlier ones are done. Non-setup warnings
(backup, failed jobs …) stay as they are.

### The sequence (slot = `order()`)

| # | Slot | Id | Module | Open when | Line | Target | Phase |
|---|---|---|---|---|---|---|---|
| 1 | 10 | `financial.chart` | financial | the chart of accounts is empty | «Kontenplan übernehmen» | Kontenplan | **A, built** |
| 2 | 20 | `mandator.accounts` | mandator | no mandator saved / an account with a KMU start value (`MandatorAccounts::DEFAULTS`) empty or not postable | «Mandant erfassen» / «Mandant: Konten zuweisen» | Mandant | **A, built** |
| 3 | 30 | `financial.fiscal-year` | financial | no fiscal year at all | «Geschäftsjahr eröffnen» | Geschäftsjahre | **A, built** |
| 4 | 40 | `vat.accounts` | vat | an active shipped VAT code whose account is missing in the chart | e.g. «MWST-Codes: Konto fehlt im Kontenplan» | MWST-Codes (`finance/tax-code/list`) | **B, built** |
| 5 | 50 | `debtor.payment-target` | debtor | no active payment target with an IBAN → no QR invoice | «Zahlungsziel mit IBAN erfassen» | Zahlungsziele (`finance/payment-target/list`) | **B, built** |
| 6 | 60 | `article.first` | article | no active article → no order | «Artikel erfassen» | Artikel | **A, built** |
| 7 | 70 | `receipt.payment-method` | receipt (creditor reads it) | no active payment method («Zahlungsart») | «Zahlungsart erfassen» | Zahlungsarten (`finance/payment-method/list`) | **B, built** |
| 8 | 80 | `financial.bank-ledger` | financial | an active bank account whose ledger account is not postable / active | «Bankkonto «…»: Konto im Kontenplan fehlt» | Bankkonten | **A, built** |
| 8 | 81 | `financial.bank-mixed` | financial | an active mixed bank account, mandator `private` / `bank-transfer` empty or not postable | «Mandant: Konten Privat und Übertrag zuweisen» | Mandant | **A, built** |
| 9 | 90 | `mail.sender` | backend (kernel mail) | no sender (`fromAddress`), or transport `file`, or mail `enabled = false` | e.g. «E-Mail-Absender erfassen» | E-Mail (`service/email-settings/list`) | **B, built** |
| 10 | 100 | `creditor.ai-key` | creditor (kibot) | AI receipt recognition enabled but no key file | «KI-Schlüssel fehlt» | Kreditorenjournal (no AI settings screen; the switch lives there) | **B, built** |
| 11 | 110 | `mandator.logo` | mandator | «Logo» or «Logo (E-Mail)» empty or the file not found (`LocalFiles::path()`) | «Firmenlogo für Belege / E-Mails hinterlegen» | Mandant | **A, built** |
| 12 | 120 | `financial.opening` | financial | the open year of today has a CLOSED predecessor and no opening | «Eröffnung {code} nicht erstellt» | Geschäftsjahre | **A, built** |

Free slots between the tens are for later steps; equal slots keep registration order.

**Decisions of phase A (2026-11-19):**

- **Existing dashboard lines moved, not duplicated.** «Mandant nicht erfasst» left
  `MandatorDashboard` (it is step 2's «Mandant erfassen»); «Eröffnung {code} nicht erstellt» left
  `FinancialDashboard` (it is step 12, same condition). «Kein offenes Geschäftsjahr für heute»
  STAYS in `FinancialDashboard`, urgent and unlimited, but only while at least one year exists — a
  running installation without an open year for today cannot post, that must never wait behind
  the setup limit; with no year at all the installation is fresh and step 3 says it. Why moved
  instead of kept: a line that exists twice disappears only half when done; the setup order needs
  each step in one place.
- **Step 12 keeps the P5 condition** (predecessor CLOSED, 2026-10-22: an opening over an open
  predecessor is only provisional, the bookkeeper's call). The owner's list says «while a previous
  year exists» — **confirmed by the owner 2026-11-19: only after the predecessor is closed.**
- **Step 8 is two checks** (80, 81): each check has ONE target, and the two fixes live on two
  screens (Bankkonten, Mandant).
- **Step 6 is article's**, not order's: it owns the data and the target screen.
- Every phase-A target is ADMIN: an editor sees no setup step.

### Contract for a setup check (phase B builds 4, 5, 7, 9, 10 with it)

Interface `Z77\Shared\ProjectHealth\ProjectCheck` (kernel shared): `id()` · `category()`
(`CheckCategory::Setup` / `Health`) · `order()` · `target()` (`[module, group, controller,
action]`) · `severity()` · `label()` · `isOpen()`. For a setup step extend
`Z77\Shared\ProjectHealth\SetupCheck` (category Setup, severity todo) and write five methods:

```php
namespace Z77\Module\Vat\ProjectHealth;

final class VatAccountsCheck extends \Z77\Shared\ProjectHealth\SetupCheck
{
    public function id(): string    { return 'vat.accounts'; }             // unique, `<module>.<what>`, never renamed
    public function order(): int    { return 40; }                         // the slot of the table above
    public function target(): array { return ['backend', 'finance', 'tax-code', 'list']; }
    public function label(): string { return 'MWST-Codes: Konto fehlt im Kontenplan'; }
    public function isOpen(): bool  { /* ONE cheap query via DI::getUnifiedEntityManager() */ }
}
```

Registered in the module's OWN config (a project: extension file
`App/Config/projectChecksConfig.inc.php`):

```php
'projectChecks' => [\Z77\Module\Vat\ProjectHealth\VatAccountsCheck::class],
```

Rules (the bridge relies on them):

- no constructor arguments (an optional one for the harness is fine — `OpeningBalanceCheck(?today)`);
- `target()` and `order()` are static facts, no I/O — the bridge asks `canReach()` on the target
  BEFORE `isOpen()` and asks no step behind the limit;
- `isOpen()` is ONE small query or file read; never load a list to decide;
- `label()` and `severity()` are read only after `isOpen()` answered true — `isOpen()` may keep
  what it found in a property for the label;
- the check never checks access; a failure may throw (the bridge shows «Projekt-Prüfung «id»
  nicht verfügbar» in its slot and logs it);
- the target must be a page of the module's navigation seed (the dashboard places the line on
  that tile) and the module's own ACL decides who sees it.

**What a phase-B agent does per check** (no file of phase A is touched, except as noted):

1. the class in `packages/module-<x>/src/ProjectHealth/`, extending `SetupCheck`, slot from the
   table;
2. `'projectChecks' => [...]` in `packages/module-<x>/src/App/Config/<x>Config.inc.php`;
3. if the module's `DashboardContributor` already says the same thing, REMOVE that line there
   (step 5: debtor's «Kein Zahlungsziel mit IBAN» in `DebtorDashboard`; then adjust
   `tests/backend-dashboard.php` M3 / M8) — never show a step twice;
4. a test in the MODULE's own harness (e.g. `tests/module-vat.php`): open on the state that needs
   it, silent after the fix, id / slot / target — `tests/project-health.php` deliberately asks only
   mandator, article and financial, so a new step does not move its assertions;
5. the module topic doc (its dashboard / setup section) and one CHANGELOG line;
6. this table: mark the row «built».

Step 9 belongs to module-backend (kernel mail has no module config; module-backend owns the
service screens — the `ServiceDashboard` pattern): class `Z77\Module\Backend\ProjectHealth\…`,
key `projectChecks` in `backendConfig` (the bridge class is not touched).

---

## 1. Derived checks — kept and dropped

Every candidate below was checked against the code. «Deployed» and «production door» are
defined in §2.

### Kept

| Id | Fires when | Weight | Label (dashboard) | Target | Owner of the check |
|---|---|---|---|---|---|
| `host.canonical` | `canonicalBaseUrl` is set and the request host ≠ its host (lower-case, port and `www.` stripped) | todo | «Mail-Links führen auf {host}» | Projekt-Pendenzen | kernel (registered by module-backend) |
| `host.https` | deployed, and `canonicalBaseUrl` starts with `http://` | todo | «Adresse ohne https» | Projekt-Pendenzen | kernel |
| `debug.production` | `DEBUG` on the production door | urgent | «Debug-Modus auf der Live-Adresse» | Projekt-Pendenzen | kernel |
| `noindex.production` | `SEO_NOINDEX` on the production door | urgent | «Live-Adresse für Suchmaschinen gesperrt» | Projekt-Pendenzen | kernel |
| `noindex.next` | deployed, NOT the production door, `SEO_NOINDEX` off | todo | «Testadresse ohne noindex» | Projekt-Pendenzen | kernel |
| `mail.delivery` | deployed, and `config/client/mail.inc.php` has `enabled = false` (urgent), `transport = 'file'` (urgent) or an empty `fromAddress` (todo) | urgent / todo | «E-Mail-Versand ausgeschaltet» · «E-Mails werden nicht zugestellt» · «Absender-Adresse fehlt» | Service › E-Mail | kernel |
| `content.placeholders` | a live document of a blueprint slug still carries a placeholder marker (D2) | todo | «{Seitentitel}: Platzhalter ({n})» per document | Webseiten › Inhalte | kernel |
| `privacy.{feature}` | a feature is ON and the `privacy` document lacks the matching section (D3) | todo | «Datenschutz: Abschnitt «{label}» fehlt» | Webseiten › Inhalte | the module that owns the feature (below) |
| cron heartbeat | deployed, and `data/framework/jobs/last-pass.json` older than 15 min or missing | urgent | «Cron läuft nicht» | Service › Jobs | **not a project check** — an extension of `ServiceDashboard` (it owns jobs already) |

`privacy.{feature}` — the features and how «ON» is read (no text understanding anywhere):

| Check | ON when | Registered by |
|---|---|---|
| `privacy.contact` | the live `contact` document exists and is `active` | module-frontend |
| `privacy.stats` | a module with `'public' => true` is installed (the statistic counts every public module, [`stats.md`](../topics/stats.md)) | module-frontend |
| `privacy.kibot` | `KibotEndpoint::fromConfig()` returns an endpoint (visitor questions go to a third party abroad) | module-frontend (owns `KibotController`) |
| `privacy.member` | `ModuleManager::hasModule('member')` | module-member |

Sources verified: `Request::getHost()` (`packages/kernel/core/src/Http/Request.php`), the
constants `DEBUG`, `SEO_NOINDEX`, `CANONICAL_BASE_URL` (`Bootstrap.php`), `Mailer::create()` /
`isConfigured()` and the config keys `enabled`, `transport`, `fromAddress`
(`packages/kernel/shared/src/Mail/Mailer.php`), `JobRunner::lastPass()` and
`JobController::HEARTBEAT_STALE_SECONDS = 900` (private today — moves to `JobRunner` so both
readers share it), `ContentRepository::findBySlug()`, `ContentExtensions::blueprints()`,
`KibotEndpoint::fromConfig()`, `ModuleManager::hasModule()`. Routes: `content/content`,
`service/email-settings`, `service/job` (navigation seed `navigation.d/kernel.json`).

**Why `host.canonical` is safe (SEC-005).** The Host header is the client's — but this check
only compares it for display on that client's own screen; it never builds a URL. A forged Host
can only change what the forger himself sees.

**`host.canonical` on `next` is permanent by design**: `shared/config/client/systemConfig.inc.php`
is shared by both doors, so after go-live `next` always differs
([`new-project-manual.md`](../01-handbook/new-project-manual.md) §4c, «After this»). The entry
is the hint the owner asked for — it says where mail links go. It cannot fire on the
production door.

### Dropped

| Candidate | Why dropped |
|---|---|
| `canonicalBaseUrl` empty | Already a Störer on every backend page (`partials/shell/system-banner.tpl.php`) and an installer notice (`Install::canonicalBaseUrlNotice()`); a dashboard line would repeat it |
| Admin accounts without 2FA | 2FA is optional by owner decision (2026-11-01, [`login.md`](../topics/login.md) BACKEND-2FA-001) — a line that never goes away for a deliberate choice is noise. Offered as a manual item instead (D5) |
| Backup too old / none | Exists (`ServiceDashboard::backupEntries()`) |
| Failed jobs | Exists (`ServiceDashboard::jobEntries()`) |
| Newsletter section in the privacy policy | No newsletter feature exists — `module-mailing` is the document outbox, not a newsletter |
| Mail `transport = 'mail'` on cyon (MAIL-SPAM-001) | The hosting provider is not detectable; a manual item («Mail-Versand auf dem Host prüfen») covers it |

Candidates for a later stage (not in this plan): AI receipt recognition in module-creditor
sends supplier invoices to a third party (a privacy question for the client's bookkeeping, not
the website); GeoIP database missing → the stats privacy paragraph's country clause must go
(STATS-004).

---

## 2. Facts computed once per load

One value object `InstallationFacts` (kernel shared), built ONCE per dashboard load by the
bridge contributor and handed to every check — so no check reads the request or the
constants itself, and every check is pure in the harness:

| Fact | Source |
|---|---|
| `requestHost` | `DI::getRequest()->getHost()`, normalised (lower-case, port and `www.` stripped — the rule of `StatsClassifier::bareHost()`, private today; one shared helper, also for the stats proposal «count only the canonical host») |
| `canonicalHost`, `canonicalScheme` | `CANONICAL_BASE_URL`, same normalisation; `''` when unset |
| `deployed` | `basename(dirname(ABS_BASE_PATH)) === 'releases'` — on a server `ABS_BASE_PATH` is the symlink-resolved release directory ([`release-structure.md`](../01-handbook/release-structure.md) «How the current / next mechanism works»). A legacy flat deploy reads as not deployed: the production-only checks stay silent there (documented limit) |
| `productionDoor` | `deployed && requestHost === canonicalHost` |
| `debug`, `noindex` | `DEBUG`, `SEO_NOINDEX` (both release-local, ADR-035 — per door, which is exactly what the checks need) |

Local development (`deployed = false`): only `host.canonical`, `content.placeholders` and
`privacy.*` can fire — the ones a developer should see before the first deploy.

---

## 3. Where the checks live (D1)

> **Built differently in phase A (2026-11-19):** `ProjectCheck` is `id()` · `category()` ·
> `order()` · `target()` · `severity()` · `label()` · `isOpen()` (one line per check, see
> [«Ersteinrichtung»](#ersteinrichtung-setup-checks)), not `entries(InstallationFacts)`. For P1 the
> kernel health checks add what they need on top: `InstallationFacts` as a service the checks ask
> (not a parameter), `help()` for the screen (P3). A finding with several lines
> (`content.placeholders` per document) is then one check whose label carries the count, or one
> check per document — decide in P1.

```text
kernel shared  Z77\Shared\ProjectHealth\
  ProjectCheck          interface: id(): string · help(): string · entries(InstallationFacts): iterable<DashboardEntry>
  ProjectChecks         registry: config key `projectChecks` + extension files projectChecksConfig.inc.php
  InstallationFacts     value (§2), pure constructor for the harness
  Checks\…              the kernel checks of §1 (host, https, debug, noindex ×2, mail, placeholders)
  PrivacySectionCheck   abstract base: featureOn(): bool · sectionKey(): string · label(): string
kernel shared  Z77\Shared\Entities\ProjectTask, ProjectCheckDismissal   the manual list + dismissals (§5)

module-backend
  Dashboard\ProjectHealthDashboard   THE bridge: a DashboardContributor (backendConfig `dashboard`)
  Ui\Controllers\Service\ProjectHealthController   the screen (§6)
  backendConfig `projectChecks`      registers the kernel checks (the kernel has no module config;
                                     module-backend owns the service screens — the ServiceDashboard pattern)

module-frontend   frontendConfig `projectChecks` → privacy.contact, privacy.stats, privacy.kibot
module-member     memberConfig   `projectChecks` → privacy.member
```

- **Findings are `DashboardEntry` values** — no new value type. A check yields nothing when
  nothing is to do (ADR-052 contributor rules).
- **The bridge** builds the facts, asks the registry, isolates every check (a throwing check →
  one line «Projekt-Prüfung «{id}» nicht verfügbar», logged — ADR-052 §7), drops dismissed ids
  (D4), and adds one entry «Projekt-Pendenzen offen ({n})» when manual tasks are open. Access
  stays the board's job (`canReach()` on each target) — a check never filters by role.
- **Why a registry of its own and not more `DashboardContributor`s** (D1): a check needs a
  stable `id` (for «Nicht zutreffend» and for its help paragraph), the facts should be computed
  once, and the screen must list the open findings with their help — a contributor offers
  none of that. The registry is the copy of `DashboardContributors` (validation at first load,
  naming module and file); no shared base class is extracted for two copies.
- **A project adds its own check** (CE, Rule 1) with an extension file, exactly like a dashboard
  contributor — no module config copied (BOOT-CONFIG-001 does not bite: extension files are
  additive):

  ```php
  // override/z77/module/backend/src/App/Config/projectChecksConfig.inc.php
  return [\App\Health\DnsSpfCheck::class];
  ```

  A project cannot REMOVE a shipped check by config; it uses «Nicht zutreffend» (D4).

---

## 4. Placeholders and privacy sections (D2, D3, D8)

**Where the starter content lives.** `packages/kernel/core/data/content/<slug>.<lang>.default.json`
(home, about, services, contact, thanks, legal, privacy, footer × de, en), seeded to
`data/content/<slug>.<lang>.json` on the FIRST install only (`Install::STARTER_DATA_DIRS`,
[`content.md`](../topics/content.md) «Seeding»). The structure is the blueprint of the same slug
in `frontendConfig` (`contentBlueprints`).

**Marker (D2).** Every paragraph that must be replaced carries `[[ANPASSEN: …]]` — today's text
«Durch Ihre Angaben ersetzen: …» / «Replace with your details: …» becomes
`[[ANPASSEN: …]]` + the same explanation. Visible on the page on purpose (an unfinished imprint
should look unfinished). `InlineMarkdown` leaves it literal (a link needs `](`) — pinned by the
harness. The check ALSO recognises the two current prefixes, so installations seeded since
2026-10-22 benefit without touching their content (they never receive new starter documents).
One constant list in the check — the only place that knows the markers.

**Which documents are read — not everything.** Only the LIVE copy (variant `''`) of every slug
that has a blueprint (`ContentExtensions::blueprints()`) in every configured language: today 8
slugs × 2 languages = 16 files, each one O(1) `findBySlug()` load. Variants, versions and
non-blueprint documents are never read. A project's own blueprint pages are included
automatically.

**Cache (D8).** The scan result (placeholders per document + the block keys of `privacy`) sits
in `DataCache` under one key with a **signature = the mtimes of the files read** (16 `stat`
calls on a hit, no parse). Any change — backend save, page editor, a hand-copied file on the
server, a restore — changes an mtime and recomputes. `Content` is `invalidatesCache: true`, so
a backend save also clears it; the signature covers what bypasses the entity manager.
Everything else in §1 reads a constant, one config file or one small JSON — live, as ADR-052 §8
wants.

**Privacy section = block key (D3).** The `privacy` blueprint already has keyed slots
(`controller`, `visit`, `cookies`, `contact`, `thirdParty`, `abroad`, `retention`, `rights`,
`changes`). `PrivacySectionCheck` fires for a feature that is ON when, in any configured
language, the live `privacy` document has no block with the section key, or that block is
empty (title and paragraphs empty = not shown), or it still carries a marker. The key IS the
marker — no wording is ever interpreted. New slots in the starter blueprint and documents:

| Key | Label | Starter text |
|---|---|---|
| `stats` | «Statistik» | de: the generic paragraph of [`stats.md`](../topics/stats.md) «privacy paragraph», its country clause marked `[[ANPASSEN: …]]`; en: outline + marker |
| `member` | «Kundenkonto» | outline + marker |
| `kibot` | «KI-Assistent» | outline + marker |

`contact` exists. A section that does not apply is emptied (shown nowhere) — and the check of a
feature that is OFF never looks at it. Existing installations get the new slots with the next
package update (the blueprint is code); the editor shows them as empty slot cards to fill.
No `privacy` document at all (a project with its own page) → the check fires until the project
gives its blocks these keys or marks the check «Nicht zutreffend».

---

## 5. Manual list (D5, D6)

**Data, not config** (ADR-036): the list is written by the application (ticking, adding), must
be the same on both doors, belongs in the backup, and reaches the server with the first-deploy
seed of `shared/data` ([`new-project-manual.md`](../01-handbook/new-project-manual.md) §4c).
`config/client/` is for hand-maintained machine facts and is never written by the app.

```text
data/framework/project/tasks.json        collection mode, entity ProjectTask
  id          int (collection auto-increment)
  title       string, required, ≤ 120     «Impressum fachlich prüfen lassen»
  note        string, optional            one or two sentences: why, where, who
  origin      'install' | 'backend'
  created_at  ISO-8601                    created_by  string ('install' for the AI)
  done_at     ISO-8601 | ''               done_by     string
data/framework/project/dismissed.json    collection mode, entity ProjectCheckDismissal (D4)
  check_id, dismissed_at, dismissed_by
```

JSON keys snake_case, UTF-8 without BOM, written through `FileStorage` by the app
([`persistence-file.md`](../topics/persistence-file.md)). A malformed file makes the bridge throw
→ one «nicht verfügbar» line, the dashboard stands.

**How the AI writes it (D6).** New step in `INSTALL-AI.md` after step 8 («Step 11 — Project
pendenzen»): the assistant writes `data/framework/project/tasks.json` with its file-writing tool
(never a PowerShell round-trip — DATA-JSON-001), `origin: 'install'`, ids from 1. The step gives
the JSON shape, a base list to propose and to adapt with the person (only what applies), and the
rule «only what no check can detect». Base list:

- Impressum fachlich prüfen lassen · Datenschutzerklärung fachlich prüfen lassen
- E-Mail-Zustellung auf dem Server testen (SPF/DKIM, Spam-Ordner)
- 2FA für das Admin-Konto einrichten (D5)
- Logo / Markenzeichen statt «z77» hinterlegen (brand mark default, [`content.md`](../topics/content.md) pending)
- Externe Kopie der Backups festlegen · Statistik-Bericht: Schlüssel und Versand einrichten (if wanted)

No CLI command (D6): the file has six keys, the assistant can write it, and every bin is API
surface. If a later need appears (deploy scripts adding tasks), a `z77-task add` can follow.

---

## 6. Screen, roles, wording (D4, D7)

**Screen «Service › Projekt-Pendenzen»** (`ProjectHealthController`, route
`/backend/service/project-health`, navigation seed key `projekt-pendenzen` under `service` in
`navigation.d/kernel.json` — add-only by key, ADR-050, so existing installations receive it).
Follows the area recipe ([`backend-screen.md`](../01-handbook/backend-screen.md), Rule 8) — **a
board on the developer canvas before building**. Sketch, not decided:

- toolbar tabs «Offen» · «Erledigt» · «Ausgeblendet»; «+ Neu» (a task by hand);
- one standard list: derived findings first (label, «Öffnen» → its target when the user may
  reach it), then the manual tasks (title, note);
- action cell = the most frequent action of the selected row: «Erledigt» for a task,
  «Öffnen» for a finding; ⋮ «Bearbeiten», «Löschen», «Nicht zutreffend» / «Wieder anzeigen».

**Roles (D7).**

| What | Who | How |
|---|---|---|
| A derived entry on the dashboard | whoever may open its TARGET | `canReach()` (ADR-052 §6) — an editor sees «Impressum: Platzhalter» (target Inhalte is EDITOR), not «Debug-Modus …» |
| «Projekt-Pendenzen offen (n)» | ADMIN | target is the screen |
| Screen, tick, add, edit, delete a task | ADMIN | module baseline — no `backendConfig` entry needed (AUTH-B003) |
| «Nicht zutreffend» / «Wieder anzeigen» | SUPER_USER | one action entry in `backendConfig` (it hides a legal hint for everyone — governance like backup/jobs) |

**Wording.** Dashboard labels as in §1: short, the fact, no advice. The screen shows the label
and nothing more; **what it means and how to fix it is the help** (`list.help`, ADR-048). The help
is assembled from the registered checks: each `ProjectCheck::help()` returns its paragraph, so a
module's check brings its own explanation and a project check too.

---

## 7. Build phases, tests, docs

| Phase | Content | Check |
|---|---|---|
| P1 kernel + bridge | `ProjectCheck`, `ProjectChecks`, `InstallationFacts`, the kernel checks, `ProjectTask` + `ProjectCheckDismissal`, `ProjectHealthDashboard`, heartbeat line in `ServiceDashboard` | new harness green |
| P2 content | markers in the 16 starter documents, slots `stats` / `member` / `kibot` in the `privacy` blueprint and documents, the three privacy checks + `privacy.member` | starter harnesses green |
| P3 screen | board → owner → `ProjectHealthController`, templates, help, navigation seed | access + POST checks |
| P4 install + docs | `INSTALL-AI.md` step 11, docs below | `npm run docs:check` |

**Harness `tests/project-health.php`** (group `backend` in `tests/groups.php`), pure where
possible, a temp project root otherwise:

- facts: host normalisation (case, port, `www.`), `deployed` from a `…/releases/<name>` root,
  production door yes/no;
- each kernel check against hand-built facts and a temp `config/client/mail.inc.php`: fires /
  silent, weight, target route;
- placeholders: the new marker, both legacy prefixes, a variant and a version are NOT read, a
  non-blueprint slug is NOT read; the signature cache recomputes after an mtime change;
- privacy: feature off → silent; on + key missing / block empty / marker → fires; filled →
  silent; `InlineMarkdown` renders `[[ANPASSEN: x]]` literally;
- bridge: a throwing check → one «nicht verfügbar» line, the others stand; a dismissed id is
  dropped; the open-task count; a bad registry entry throws naming module and file;
- timing: all checks on a warm cache under a fixed budget (measured first, then pinned).

Existing harnesses extended: `tests/backend-dashboard.php` (heartbeat line),
`tests/installer-starter-content.php` and `tests/frontend-starter-pages.php` (every
replace-me paragraph carries the marker; the new slots render).

**Docs to touch** (in the build, not now): `backend.md` (dashboard table, the screen, the
bridge), `content.md` (markers, new privacy slots), `stats.md` (the paragraph now ships as the
`stats` slot), `mail.md`, `member.md`, `kibot.md`, `jobs.md` (each: its check), `bootstrap.md`
(`InstallationFacts` reads `CANONICAL_BASE_URL` / `DEBUG` / `SEO_NOINDEX`), `persistence-file.md`
(`data/` structure), `INSTALL-AI.md`, the handbook page `new-project-manual.md` («After this»:
the dashboard shows what is open), a new ADR for the registry + manual list + dismissal (it adds
to ADR-052 rather than changing it), `CHANGELOG.md`.

**Migration:** none. No Doctrine table; the two data files are created on first write; the
navigation entry arrives add-only by key; existing content is untouched (legacy prefixes are
recognised).

---

## 8. Owner decisions

**Decided 2026-11-19 (owner): every recommendation below is accepted as written.**

- **D1 — Registry.** (a) Own registry `projectChecks` + one bridge contributor (§3);
  (b) every check a plain `DashboardContributor` in its module, no screen. (b) is less code but
  gives no id (no dismissal, no help per check) and no list to tick. **Recommendation: (a).**
- **D2 — Marker.** `[[ANPASSEN: …]]` in German AND English documents (one token to look for;
  your word), plus recognition of «Durch Ihre Angaben ersetzen:» / «Replace with your details:».
  Alternative: a neutral `[[TODO: …]]`. **Recommendation: `[[ANPASSEN: …]]`.**
- **D3 — Privacy sections.** Detected only by the block `key` in the `privacy` document; three
  new starter slots `stats`, `member`, `kibot`; the German `stats` slot pre-filled with the
  stats.md paragraph. **Recommendation: yes.** Note: `privacy.stats` fires on EVERY installation
  with a website until the section is filled — that is correct today (the statistic counts and
  the starter privacy page does not mention it).
- **D4 — «Nicht zutreffend».** A SUPER_USER may hide a derived check (stored with who/when,
  reversible on the tab «Ausgeblendet»). Without it, a check that does not apply to a project
  (own privacy page, a deliberate state) stays forever. **Recommendation: yes.**
- **D5 — 2FA.** Not a derived check, because you decided 2FA stays optional (2026-11-01); the AI
  proposes it as a manual task. Alternative: a derived todo per admin without 2FA.
  **Recommendation: manual task.**
- **D6 — Manual list.** In `data/framework/project/tasks.json`, written by the AI directly at
  installation; ticked, added, edited on the screen; no CLI. Alternative: a `z77-task` bin.
  **Recommendation: direct file, no CLI.**
- **D7 — Roles.** Dashboard by `canReach()` of each target; screen and tasks ADMIN; dismissal
  SUPER_USER. Alternative: everything SUPER_USER (then the client's admin never sees the
  imprint hint). **Recommendation: as proposed.**
- **D8 — Cache.** Only the content scan is cached (DataCache, mtime signature); every other check
  is live. Alternative: no cache at all (16 small reads per dashboard load).
  **Recommendation: the signature cache** — it costs 16 `stat` calls and stays correct for
  hand-copied files.

## see also

- [`../topics/backend.md`](../topics/backend.md) «dashboard» — the registry and board this plan extends
- [`../topics/content.md`](../topics/content.md) «Starter pages», «Seeding» — where the markers go
- [`../topics/bootstrap.md`](../topics/bootstrap.md) — `canonicalBaseUrl`, the Störer that already covers an empty value
- [`../topics/security.md`](../topics/security.md) — SEC-005 (Host header) and the pending systemConfig editor
