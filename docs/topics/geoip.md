# geoip

2026-08-27

## entry

1. `packages/kernel/shared/src/GeoIp/CountryLookup.php` — the one question: «which country is this IP in?»
2. `packages/kernel/shared/src/GeoIp/GeoIpUpdateJob.php` — keeps the database current, which is a licence obligation
3. `packages/kernel/shared/src/GeoIp/MmdbReader.php` — the MMDB binary format, hand-written

## file map

SOURCE=/packages/kernel/shared/src/GeoIp/CountryLookup.php
SOURCE=/packages/kernel/shared/src/GeoIp/MmdbReader.php
SOURCE=/packages/kernel/shared/src/GeoIp/GeoIpUpdateJob.php
SOURCE=/packages/module-backend/src/App/Config/backendConfig.inc.php
SOURCE=/tests/form-geo-guard.php

RUNTIME=/skeleton/data/framework/geoip/GeoLite2-Country.mmdb
RUNTIME=/skeleton/config/geoip.inc.php

## mental model

A country code, or nothing. That is the whole surface, and the «or nothing»
is half of it: this is an OPTIONAL layer that sits in the path of a public
registration form, so every failure mode has to end in `null` rather than in
an exception, an outage or a locked-out customer.

- **`CountryLookup` is the seam; `MmdbReader` is the file format.** Consumers
  ask the first and never the second. Any `*.mmdb` in `data/framework/geoip/`
  is taken (sorted, first readable one wins), so the vendor's own file name
  survives an update and a broken file does not block the directory.
- **Null is a normal answer**, not a failure: a private address, an
  unassigned range, a database that was never installed and a file that turned
  out to be corrupt all mean «we do not know» to a caller. Nothing in
  `CountryLookup` throws.
- **The database belongs to the INSTALLATION, never to the repository.** It
  is not in git, not in the framework package, not under `public/`, and it is
  not deployed — every installation fetches its own. That is the licence, not
  a preference.
- **The address comes from `REMOTE_ADDR`.** ⚠️ Never `X-Forwarded-For` or
  `Client-IP`: those are claims by the sender, and a country derived from them
  is whatever the sender wants it to be. Behind a real reverse proxy, that
  proxy's own trusted-header handling belongs here deliberately — not a
  blanket trust.
- **The lookup is a fact, the country RULE is a policy — and the policy lives
  with the forms.** `CountryLookup` answers; `PublicFormHandler::withGeoGuard()`
  decides, `Z77\Shared\Forms\CountryBlocklist` holds the list
  (`data/framework/forms/blocked-countries.json` — installation data, not
  config), and Backend → Service → «Formular-Protokoll» is where the evidence
  and the switch share a page. This topic keeps the database and the licence;
  gate, log, blocklist and surface are documented in
  [`forms.md`](forms.md). The list is deliberately NOT under
  `data/framework/geoip/`: that directory holds licence-bound third-party
  data (no redistribution, no backup ride-along), the blocklist is the
  operator's own record.

## the three licence obligations (GeoLite EULA)

They are not footnotes — each one is a line of code somewhere.

| Obligation | Where it lives |
|---|---|
| No redistribution | `data/` is gitignored and never deployed; the `.mmdb` is in no package |
| Keep current, destroy the previous version within 30 days | `GeoIpUpdateJob` REPLACES the file and removes every other `*.mmdb` |
| Attribution wherever results are shown | `CountryLookup::ATTRIBUTION`, rendered at the foot of the view that shows countries |

⚠️ The database must not be used to identify a person, household or street
address. Country level is all this layer will ever answer, and the edition
fetched is `GeoLite2-Country` for exactly that reason — a city edition would
go further than what was declared at signup.

## the update job

```php
'geoip-update' => [
    'class'           => \Z77\Shared\GeoIp\GeoIpUpdateJob::class,
    'label'           => 'GeoIP-Datenbank erneuern',
    'runAs'           => AuthRole::CRON_JOB,
    'maxAttempts'     => 2,
    'defaultSchedule' => 'weekly@mon,04:20',
],
```

The class lives in the kernel beside `CountryLookup`; it is REGISTERED in
`backendConfig` (like `BackupJob` and `form-log-cleanup`) — the geo guard is a
kernel capability of every public form, so no single module owns the consumer
any more. Until 2026-08-28 the entry sat in `memberConfig`; a project override
of that file that still carries it double-declares the job key, which the
registry refuses fail-fast — delete it from the override.

⚠️ **It ships a `defaultSchedule`, and that is not a violation of the
«a job that deletes data ships none» rule.** That rule is about the
INSTALLATION's data. This job replaces a file it downloaded itself, and doing
so on time is a licence obligation — a duty that waits for an operator to
remember it is not a duty being met. A job that both deletes and must run is
two jobs; `member-cleanup` (deletes, no schedule) and `geoip-update` (must
run, scheduled) are the worked example.

**What a run does, in order:** read `config/geoip.inc.php` → no key, say so
and stop → database younger than `maxAgeDays`, say so and stop → not enough
time left in this pass, return `again()` → download the `tar.gz` → walk the
tar and take the single `.mmdb` → **open it with `MmdbReader`** → rename it
over the target → delete every other `*.mmdb`.

- ⚠️ **The integrity check is the reader, not a checksum.** Opening the
  candidate catches a truncated transfer, a gzip that is really an HTML error
  page, and an edition that does not parse — with one test, and it tests the
  property that actually matters: can we read it afterwards. A corrupt
  download therefore never replaces a working database.
- ⚠️ **`CountryLookup::forget()` runs BEFORE the rename**, not after. The
  memoised reader holds an open handle on the very file being replaced, and
  Windows refuses to rename over an open file. Dropping the memo first
  releases the handle AND makes the next lookup re-resolve the directory.
- ⚠️ **The download URL carries the licence key.** No error message and no log
  line may print it; the messages name the edition instead.
- The tar is walked by hand rather than through `PharData`: phar can be
  disabled on shared hosting, and extracting to a directory would spill the
  archive's licence texts into the folder `CountryLookup` globs.

## config — `config/geoip.inc.php` (machine-local, gitignored, not deployed)

`licenseKey` (or MaxMind's ready-made `GeoIP.conf` in the database directory —
the config wins) | `edition` (`GeoLite2-Country`) | `maxAgeDays` (30)

⚠️ The lookup needs NOTHING from this file. The key is only for the job. An
installation without a key runs fine and simply answers no country.

## rules

- When asking for a country → MUST call `CountryLookup::of()`, MUST pass
  `REMOTE_ADDR`, MUST treat `null` as «unknown» and carry on
- When a rule is built on the country → MUST fail OPEN on `null`; MUST NOT
  block on «we do not know», which would turn a missing optional file into a
  total outage
- When restricting by country → MUST be a blocklist; MUST NOT be a whitelist
  (a whitelist locks out the customer in a holiday WLAN, on a VPN, or behind
  a carrier routing through another country)
- When reading which countries are blocked → MUST go through
  `\Z77\Shared\Forms\CountryBlocklist::codes()`;
  MUST NOT read a `blockedCountries` config key — nothing writes one any more
- When writing a blocklist entry → MUST record a `reason`; the backend refuses
  an empty one, and a code path that bypasses it leaves a verdict nobody can
  review in a year
- When shipping a blocklist → MUST NOT: it is per-installation evidence, so
  `BlockedCountry` has no `*.default.json` and is deliberately absent
  from `importEntities`. Carrying one project's list into another is exactly
  the guess this design refuses
- When the unknown country `??` appears in the backend tally → MUST NOT offer
  to block it; it is localhost, a private range or a missing database, and
  blocking it bars everyone the lookup cannot place
- When displaying country results → MUST render `CountryLookup::ATTRIBUTION`
  on the same surface (licence term)
- When shipping the database → MUST NOT: it belongs to the installation. A new
  server gets it from the job, not from an upload of someone else's copy
- When a project overrides `memberConfig` whole → its `jobs` key MUST NOT
  carry a `geoip-update` entry any more (registered by `backendConfig` since
  2026-08-28): a leftover double-declares the job key and the registry throws
  fail-fast at bootstrap

## known issues

- **GEOIP-001**: don't assume an empty country column means the lookup is
  broken. Localhost, private ranges and unassigned blocks are all legitimately
  unknown, and the view says so — it prints «Kein Länder-Datenbestand
  installiert» only when there really is no database.
- **GEOIP-002**: don't assume the file's mtime says how old the data is. A
  copy or a deploy resets it; `CountryLookup::databaseBuiltAt()` reads
  MaxMind's own build stamp, and that is what the job compares against.
- **GEOIP-003**: don't assume a `blockedCountries` entry in a project's
  `memberConfig` override still does anything. It is dead since 2026-08-27 and
  reads as an empty rule — silently, because the config is a whole-file
  override and nothing can tell an intentional key from a leftover. A project
  carrying one has to re-enter its countries on Service → Formular-Protokoll;
  the leftover key should then be deleted so the next reader is not misled.

## pending

- **Adopt the navigation entry per installation** — seed `id:30`
  («Formular-Protokoll» under Service) reaches an existing project through
  Service → Import. A project that already has its own menu point on
  `form-log` should ASSIGN the imported record to it rather than accept it as
  new; accepting it as new leaves the surface listed twice.
- **Say it in the privacy policy** — the form log and the blocklist both hold
  personal data (IP, country, user agent, declared identity) for a purpose
  and a retention of their own. The text is per installation; the framework
  only enforces the 90 days. (The month-filter question from the first review
  is settled without a filter: the prefilled block reason NAMES its counting
  window, so the number cannot be read as a total — review F3.)

## see also

- [`jobs.md`](jobs.md) — the runner, and the delete-vs-schedule rule this job is the exception to
- [`forms.md`](forms.md) — the geo guard that consumes the lookup: gate, form log, blocklist and the `identityField()` opt-in
- [`backend.md`](backend.md) — `FormLogController` (Service → Formular-Protokoll): the surface that shows the log and edits the blocklist, navigation seed `id:30`
- [`persistence-file.md`](persistence-file.md) — where `blocked-countries.json` lives and how the store assigns its ids
