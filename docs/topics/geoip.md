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
SOURCE=/packages/module-member/src/Services/RegistrationLog.php
SOURCE=/packages/module-member/src/Services/RegistrationFlow.php
SOURCE=/packages/module-member/src/App/Config/memberConfig.inc.php

RUNTIME=/data/framework/geoip/GeoLite2-Country.mmdb
RUNTIME=/config/geoip.inc.php

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
- **The lookup is a fact, the country RULE is a policy.** `CountryLookup`
  answers; `RegistrationFlow::blockedCountries()` decides. They are separate
  on purpose: the log records countries whether or not anything is blocked,
  and a rule is switched on from what the log showed.

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

The class lives in the kernel beside `CountryLookup`; it is REGISTERED by
whichever module consumes country data (today `module-member`). Same shape as
`BackupJob`, which lives in the kernel and is registered in `backendConfig`.

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
- When displaying country results → MUST render `CountryLookup::ATTRIBUTION`
  on the same surface (licence term)
- When shipping the database → MUST NOT: it belongs to the installation. A new
  server gets it from the job, not from an upload of someone else's copy
- When the update job is absent from a project's `jobs` config → the database
  ages and the licence obligation is unmet; a whole-file `memberConfig`
  override MUST carry the entry (the standing trap of whole-file overrides)

## known issues

- **GEOIP-001**: don't assume an empty country column means the lookup is
  broken. Localhost, private ranges and unassigned blocks are all legitimately
  unknown, and the view says so — it prints «Kein Länder-Datenbestand
  installiert» only when there really is no database.
- **GEOIP-002**: don't assume the file's mtime says how old the data is. A
  copy or a deploy resets it; `CountryLookup::databaseBuiltAt()` reads
  MaxMind's own build stamp, and that is what the job compares against.

## see also

- [`jobs.md`](jobs.md) — the runner, and the delete-vs-schedule rule this job is the exception to
- [`member.md`](member.md) — the registration log this feeds, and the gates around it
