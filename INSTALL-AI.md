# Installing z77 — a guide for your AI coding assistant

> **For the person:** hand this file to your AI coding assistant (for example Claude Code)
> before anything is installed — or paste this prompt:
>
> ```text
> Read https://raw.githubusercontent.com/z77-ch/z77-framework/main/INSTALL-AI.md and install z77 for me. Ask me before you decide anything.
> ```
>
> ```text
> Lies https://raw.githubusercontent.com/z77-ch/z77-framework/main/INSTALL-AI.md und installiere z77 für mich. Frag mich, bevor du etwas entscheidest.
> ```

**For the assistant:** everything below is addressed to you. This file is self-contained;
every link is absolute, so it works when you read only this file. Where it links to a
handbook page, that page is the source of truth — read it when a step points there.

z77 is an open-source PHP MVC framework (MIT): a web stack (kernel, website, admin backend,
document management) that runs without a database, plus an optional Swiss business suite
(contacts, bookkeeping, VAT, articles, orders, invoices with QR-bill, receipts) on MariaDB.
Repository: https://github.com/z77-ch/z77-framework

---

## 1. Your contract

**Talk to the person in plain language.** They may not be a developer. Before every step,
say in one sentence what you are about to do and why. Use the technical term and explain
it in a few words («Composer — the tool that downloads PHP packages»). One step at a time.

**You do on your own** (after you have announced it):

- read files, run the read-only checks in §2, run commands inside the project folder that
  the steps below name, edit the project's own files (`composer.json`, `.gitignore`,
  `config/client/*.inc.php`) as the steps describe.

**You ask first and wait for a clear yes** before anything that:

- installs or downloads software (PHP, Composer, MariaDB, `composer install` / `update` /
  `require`, `git clone`),
- writes outside the project folder (a new folder elsewhere, a system setting, `php.ini`),
- needs or creates credentials (database user and password, the admin account),
- touches a database (creating a database or user, running migrations),
- touches a server, a domain or a deployment.

**You stop and report** (what failed, the exact output, what the person can do) when:

- a check in §2 fails — do not work around it (no `--ignore-platform-reqs`, no other PHP
  version guessed, no extension switched off),
- a command ends with an error you cannot explain from this guide or the linked docs,
- a step would contradict a rule in §5.

**You never** choose the admin password, output any password (not in the chat, not in a
log, not in a command line you show), store one outside `config/client/` (gitignored) or the
person's password manager, put one into a tracked file, edit anything under `vendor/`, or
deploy. You may see the content of a file you wrote (your tool reports file changes back to
you) — that is acceptable; printing it is not. Never read `config/client/database.inc.php`
aloud or back into the chat.

---

## 2. Preflight checks

Run each command, compare with the expected result, tell the person the outcome in one line.

| Check | Command | Expected |
|---|---|---|
| PHP version | `php -r "echo PHP_VERSION, PHP_EOL;"` | **≥ 8.2** for the web stack · **≥ 8.4** for the business suite |
| PHP extensions | `php -m` | contains `mbstring`, `fileinfo`, `zip`, `openssl`, `iconv`, `simplexml`, `gd` · for the business suite also `pdo_mysql` · `curl` with `z77/kibot` (KI-Assistent, AI recognition) and for the GeoIP update job · `apcu` is optional (recommended; then `apc.enable_cli=1`) |
| PHP upload and memory limits | `php -r "echo ini_get('upload_max_filesize'), ' / ', ini_get('post_max_size'), ' / ', ini_get('memory_limit'), PHP_EOL;"` | upload ≥ `20M`, post ≥ upload, memory ≥ `128M` (`256M` with the suite). A fresh PHP has `2M / 8M` — tell the person; changing `php.ini` needs their yes (§1), it does not stop the installation. All values: https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/dev-environment.md#2-php-settings-phpini |
| Composer | `composer --version` | **2.x** |
| Git | `git --version` | any 2.x (the project becomes a git repository, step 1; step 2's check needs it) |
| MariaDB (business suite only) | `mariadb --version` (or `mysql --version`) — on Windows the client is usually **not on PATH**, see §2a; then skip this row: the PDO check of the next row prints the server version too | MariaDB **≥ 10.6** installed; **tested with 10.6, 11.4 and 13.0** (10.6.28 dev, 11.4.13 production, 13.0.2 install test 2026-11-19). A newer major than tested → proceed, but stop at the first SQL error (§4 step 6) |
| MariaDB running and reachable (suite only) | `php -r "echo (new PDO('mysql:host=127.0.0.1', '<user>', '<password>'))->query('SELECT VERSION()')->fetchColumn(), PHP_EOL;"` (works without the `mysql` client; ask the person for `<user>` / `<password>` — do not store them). Windows service: `Get-Service MariaDB` | prints a version ≥ 10.6; an exception (refused / access denied) → report (§1) |
| Write permission | `php -r "var_dump(is_writable('.'));"` in the folder that will hold the project | `bool(true)` |

Node.js / npm are **not** needed: the framework ships compiled CSS and minified JS. They
matter only later, when someone edits SCSS.

The requirements come from the packages' `composer.json` and
https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/dev-environment.md —
read it if a check is unclear.

On any failure: report and stop (§1). Installing or upgrading PHP, Composer or MariaDB is
the person's decision — offer to explain how for their system, do not do it unasked. On
Windows see §2a.

---

## 2a. Windows prerequisites

Only when a check in §2 failed on Windows and the person said yes to installing. Announce
each command first (§1). These commands are `winget` (Windows' package manager) facts, not
z77 facts.

- **PHP:** `winget install --id PHP.PHP.8.4 -e`. Then `php.ini`: copy `php.ini-development`
  to `php.ini` in PHP's folder, enable the extensions of §2 (remove the `;` before
  `extension=…`), raise the upload and memory limits — all values:
  https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/dev-environment.md#2-php-settings-phpini.
  If `php` does not start (missing DLL), the Microsoft Visual C++ runtime is missing — ask
  the person, then install the current «Visual C++ Redistributable» (x64).
- **Restart** the terminal and the editor after every install: PATH is read at start.
- **MariaDB:** `winget install --id MariaDB.Server -e --interactive`. In the installer window
  the person ticks **«Install as service»** and **types the root password themselves** (you
  never see it). Check: `Get-Service MariaDB` → `Running`.
- **The MariaDB client is not on PATH.** Default folder:
  `C:\Program Files\MariaDB <version>\bin\` (for example `MariaDB 13.0`). Call it by full path
  or use the PHP/PDO script of §4 step 5.
- **Administrator rights** only for these system installs: Windows asks itself (UAC), or the
  person runs that one command in an administrator terminal. Never run the editor or the
  assistant as administrator for the z77 installation itself.
- **Uninstall is not deleting data.** Removing a program leaves its data directory; deleting
  that is a separate decision of the person — never delete data silently. If `winget` still
  sees an old MSI registration after an uninstall, **stop and ask the person** — no registry
  edits by you.

---

## 3. Questions to ask before you act

Ask these together, in plain language, and wait for the answers. Do not assume defaults.
If an answer is partial, targeted follow-up questions are expected — ask only for what is
missing.

1. **What should it be?**
   - *Website only* (pages, navigation, admin backend, documents — no database), or
   - *Website plus business suite* (invoices, bookkeeping, … — needs PHP 8.4 and MariaDB).
2. **Which business modules?** (only for the suite) Show this list with one line each and
   let the person pick. A module pulls in what it requires — name those too.

   | Module | What it does | Also needs |
   |---|---|---|
   | `module-contact` | contacts with addresses | — |
   | `module-mandator` | your own company: letterhead, UID, VAT liability, ledger accounts | — |
   | `module-vat` | VAT codes and rates | — |
   | `module-financial` | double-entry bookkeeping, fiscal years, bank statements | vat, mandator |
   | `module-article` | articles, prices | vat, mandator |
   | `module-debtor` | invoices with QR-bill, payments, dunning | vat, contact, mandator |
   | `module-order` | quotes and orders | vat, contact, mandator, article, debtor |
   | `module-receipt` | receipt journal, phone capture | vat, mandator, financial, dms |
   | `module-mailing` | mail outbox | — |
   | `module-creditor` | supplier invoices | contact, vat, mandator, financial, receipt, dms |

   Optional packages that are **out of scope of «all business modules»** unless the person
   asks for them by name: `module-member` (member area), `module-api` (JSON gateway) and
   `kibot` (AI chatbot, KI-Assistent; needs PHP `curl`). Their requirements:
   https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/optional-modules.md

   Every business module except `module-vat` also requires `z77/persistence-doctrine` (the
   database driver). Source: each package's `composer.json` → `require`, and
   https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md §1.
3. **Database** (suite only) — which variant applies?
   - **(a)** The person has a MariaDB/MySQL **admin account** (for example `root`) → the
     database and a dedicated user are created (§4 step 5, SQL included). The person types
     the admin password; you never store it. **Admin password unknown?** See §4 step 5
     «Admin password unknown» — fallback: website only for now.
   - **(b)** The person or their hoster **already provides a database and a restricted
     user** → use exactly those; no `CREATE USER` / `GRANT`. You need: host, port, database
     name, user (the password the person enters themselves). If the provided user may
     create its own databases (a prefix grant), the person — or you, with the person's
     yes — runs `CREATE DATABASE <name>` with that user; otherwise the database must
     already exist.
   Explain where the credentials will go (§4 step 5) and that they never enter a tracked file.
4. **Mail:** should the installation send real e-mail at all? Default for setup: no (§4 step 5).
5. **The first admin:** explain that the person chooses the admin password themselves in the
   browser (§4 step 7). You will not see it.
6. **Project folder:** where on their machine, and its name. The folder must be **empty**
   (`composer create-project` fails otherwise) — `INSTALL-AI.md` itself must not lie in it;
   keep it elsewhere. Also their own name for
   `composer.json` → `authors`.
7. **Local port** for the development server (default `8080`; one port per project if they
   run several).

---

## 4. Installation — in order, each step with its check

`<project>` = the project folder, `<port>` = the local port from §3.

**Non-interactive shell:** your shell has no keyboard. Prefix every Composer command with
`COMPOSER_NO_INTERACTION=1` (PowerShell: `$env:COMPOSER_NO_INTERACTION = '1'` first) or add
`--no-interaction`, so nothing waits for a prompt. Never run a command that may wait for input.

### Step 1 — Create the project

```bash
COMPOSER_NO_INTERACTION=1 composer create-project z77/skeleton <project>
cd <project> && git init
```

Then set the person's own name (and e-mail, if they want) in `composer.json` → `authors`.

This pulls the skeleton and the web-stack packages (`z77/kernel`, `z77/module-frontend`,
`z77/module-backend`, `z77/module-dms`) from Packagist and runs the installer (step 4
happens here already). Alternative through GitHub: the template repository
https://github.com/z77-ch/z77-skeleton → «Use this template» → clone → `composer install`.

**Check:** `<project>/composer.json`, `<project>/vendor/z77/kernel/`, `<project>/public/index.php`
exist.

Every z77 package — the web stack, the business suite, `module-member`, `module-api`, `kibot`
and `z77/docs` — comes from Packagist; no `repositories` block and no clone of the framework
repository are needed. Deployment (the `.releases/` folder, `.htaccess`, `sftp.json`, SSH, a
server) is **out of scope** here — it comes later, only with the person, via
https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/new-project-manual.md
(stages 4–6) and https://github.com/z77-ch/z77-framework/blob/main/.releases/RULES.md.

### Step 2 — `.gitignore`

The project's `.gitignore` must ignore at least: `/vendor/`, `/config/`, `/data/`,
`/backup/`, `/var/`, `/logs/`, `/public/assets/`, `/public/media/`, `/public/storage/`,
`/.vscode/`, `/.claude/`, `/docs/temp/`, `/composer.lock`
(new-project-manual.md stage 1). `config/` and `data/` hold credentials and the admin
account. If the file is missing or lacks an entry, show the person the change, then add it.

`public/` and `cron/` are **committed**: `public/` is seeded once and then belongs to the
project (ADR-024:
https://github.com/z77-ch/z77-framework/blob/main/docs/02-decisions/adr-024-asset-ownership-and-first-install-seed.md),
`cron/run.php` is seeded once into the project. Only the generated subfolders listed above
are ignored.

**Check:** `git check-ignore config/client/database.inc.php data/framework/auth` (inside a
git repository) prints both paths.

### Step 3 — Register the business modules (suite only)

A required package is installed, but it is a **module** only when its namespace is in the
**project's** `autoload.psr-4`. Without it: no screens, no routes, and its database tables are
never created. Add one line per chosen module **and per module it requires**:

```json
"Z77\\Module\\Vat\\":       ["override/z77/module/vat/src/"],
"Z77\\Module\\Contact\\":   ["override/z77/module/contact/src/"],
"Z77\\Module\\Mandator\\":  ["override/z77/module/mandator/src/"],
"Z77\\Module\\Financial\\": ["override/z77/module/financial/src/"],
"Z77\\Module\\Article\\":   ["override/z77/module/article/src/"],
"Z77\\Module\\Debtor\\":    ["override/z77/module/debtor/src/"],
"Z77\\Module\\Order\\":     ["override/z77/module/order/src/"],
"Z77\\Module\\Receipt\\":   ["override/z77/module/receipt/src/"],
"Z77\\Module\\Creditor\\":  ["override/z77/module/creditor/src/"],
"Z77\\Module\\Mailing\\":   ["override/z77/module/mailing/src/"]
```

`Z77\Module\Dms\` is already in the skeleton. In the same edit of `composer.json`:

- `require`: one line per chosen module with the constraint `^2.0`, for example
  `"z77/module-order": "^2.0"`. Modules that are only pulled in as requirements (and
  `z77/persistence-doctrine`) need no line — Composer installs them anyway; their psr-4 line
  above is still needed.
- `require` → `"php": ">=8.4"` (the business modules need PHP 8.4).
- The web stack (`z77/kernel`, `z77/module-frontend`, `z77/module-backend`,
  `z77/module-dms`) must be required with `^2.0`, like the modules. A skeleton older than
  2.0.0 has `^1.0` there — change those four lines to `^2.0`.

Register **all** modules now, before the one migration run in step 6.
Source: https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md §2.

**Check:** `composer validate` reports no error.

### Step 4 — `composer install` (or `composer update` after editing `composer.json`)

Ask first. Then run it in `<project>` — `COMPOSER_NO_INTERACTION=1 composer update` when you changed `composer.json`
in step 3, `COMPOSER_NO_INTERACTION=1 composer install` otherwise; a website-only project created with
`create-project` already went through this step. The installer (a Composer script,
`Z77\Core\Installer\Install::run`) runs after every install and update and:

- publishes `public/` on the first install (afterwards `public/` belongs to the project),
- creates the `override/z77/…` folders for every namespace in `autoload.psr-4`,
- writes `config/vendor/` (generated on every run — never edit),
- seeds `config/client/` once (`auth`, `i18n`, `backup`, `mail`, `systemConfig`, `database`
  — never overwritten afterwards),
- seeds `data/` once from the packages' defaults and adds the modules' menu entries,
- provisions the first admin, or writes a one-time setup token (see step 7),
- seeds a project `CLAUDE.md` once,
- offers `z77/docs` (interactive runs only).

Your shell is most likely **not interactive**: then there is no password prompt (a token is
written instead — that is intended) and no docs offer. If Windows reports
`hiddeninput.exe: Resource temporarily unavailable`, the prefix above was missing — run it
again with `--no-interaction`.
Details: https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/installer.md

**Check:**
- the run ends with `✓ Z77 Core installation complete`,
- `config/vendor/moduleManager.inc.php` lists every module of step 3,
- `config/client/systemConfig.inc.php`, `config/client/database.inc.php` and `CLAUDE.md` exist.

### Step 5 — Settings of this installation

1. `config/client/systemConfig.inc.php`: set `canonicalBaseUrl` to
   `http://127.0.0.1:<port>` (the address step 8 serves — the same host as in the browser) and,
   if the person wants, `installationName`. While
   `canonicalBaseUrl` is empty, website pages answer with an error 500 (the installer prints a
   reminder at the end of every run).
2. **Suite only — the database** (the variant from §3 Q3).
   - **(a) Admin account:** with the person's go-ahead, run in a MariaDB client logged in
     as the admin account (the person types the password; the user's password is chosen by
     the person — or generated randomly by you and written only into
     `config/client/database.inc.php`):
     ```sql
     CREATE DATABASE <db> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER '<db>'@'127.0.0.1' IDENTIFIED BY '<password>';
     GRANT ALL PRIVILEGES ON <db>.* TO '<db>'@'127.0.0.1';
     ```
     **No client on PATH (Windows)? Use PHP/PDO instead** — no nested quotes in PowerShell.
     Write this to a temp file **outside the project** (for example in the system temp
     folder), run it with `php <file> <admin-user> <db> <db-password>`, then delete it. The
     admin password is read from the environment variable `Z77_ADMIN_PW`, which the person
     sets in their own terminal (`$env:Z77_ADMIN_PW = '…'`); an empty value means an empty
     password:
     ```php
     <?php
     [, $admin, $db, $dbPw] = $argv;
     $pdo = new PDO('mysql:host=127.0.0.1', $admin, (string) getenv('Z77_ADMIN_PW'),
         [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
     $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
     $pdo->exec("CREATE USER " . $pdo->quote($db) . "@'127.0.0.1' IDENTIFIED BY " . $pdo->quote($dbPw));
     $pdo->exec("GRANT ALL PRIVILEGES ON `$db`.* TO " . $pdo->quote($db) . "@'127.0.0.1'");
     echo "done\n";
     ```
     (`<db>` is a plain identifier: letters, digits, underscore.) Delete the file afterwards,
     also when it failed; do not print the password in the command you show the person.

     **Admin password unknown / root has an empty password** — check in this order:
     1. Run the script's connection line alone, user `root`, `Z77_ADMIN_PW` unset.
     2. A printed version → root has an **empty** password: use it, and tell the person that
        is unsafe beyond a local machine (they should set one).
     3. `Access denied` → a password exists. Ask the person for it.
     4. The person does not know it: MariaDB has a documented root password reset (stop the
        service, start with `--skip-grant-tables`, set a new password — needs administrator
        rights; the procedure is in MariaDB's own documentation — external fact, follow the
        page for the installed version). Only the person does that, not you. Otherwise the
        fallback is **website only for now** (no database); the suite can be added later.
   - **(b) Provided database and restricted user:** run no SQL; enter the given values below.
     The user needs the rights to create and alter tables in that database (migrations).

   The connection goes into **`config/client/database.inc.php`** and nowhere else: `host`,
   `port` (`null` = 3306), `name`, `user`, `password`. This file is seed-once and
   gitignored. Best: the person types the password into the file themselves; if you write it,
   never print it. On Windows set
   `host` to `127.0.0.1`, not `localhost` (`localhost` measured ~2 s per request there). The
   person keeps the password in a password manager, not in the project.
3. **Mail.** The installer seeds `config/client/mail.inc.php` with `enabled => true`,
   `transport => 'mail'` and an empty `fromAddress`. Ask the person before any real mail
   is enabled. To make sure nothing is sent during setup, set `transport` to `'file'`
   (mails are written as `.eml` files to `data/framework/mail/outbox` instead of being
   delivered) — or `enabled` to `false` (sending then fails with a clear error). Real
   delivery (`'smtp'` with host and credentials, a sender address) is the person's decision
   later: https://github.com/z77-ch/z77-framework/blob/main/docs/topics/mail.md

**Check:** `git status` does not list anything under `config/` or `data/`.

### Step 6 — Create the database tables (suite only) — one run, after all modules are registered

Ask first. Then:

```bash
php vendor/bin/z77-db status     # shows the database name — read it out to the person
php vendor/bin/z77-db migrate --no-interaction
php vendor/bin/z77-db status
```

`--no-interaction` is required for you: without it `migrate` asks «WARNING! You are about to
execute a migration … (yes/no)» and hangs without a keyboard. `migrate` first prints
`Project: <root> — database: <name>` — read that line out to the person.

Do not order migrations by hand: `z77-db` collects them from `persistence-doctrine`, every
**registered** module and the project, and runs them in timestamp order across all modules.
If `status` or `migrate` names a different database or project root than expected: stop.

`--no-interaction` means a failing migration is not paused for a human — so run `status`
before and after, and watch the output.

**On ANY SQL error: stop at once.** Do not run `migrate` again. Record: the exact exception
text, the migration class, Executed / Current / Next from `status`, the database name and
the project root. Delete nothing, repair nothing by hand — no edits in `vendor/`, no manual
SQL against the database, no workaround. Tell the person it is a **framework issue** to
report: https://github.com/z77-ch/z77-framework/issues (with the record above, the MariaDB
version, no passwords). DDL on MariaDB is not transactional, so a failed migration may leave
tables half created; after a framework fix, a **fresh test database** is the clean way to
continue. This holds also for a MariaDB newer than the tested versions (§2).

**Check:** the last `status` shows `New 0`.

### Step 7 — The first admin

Interactive install: the installer has already asked for the password (username `admin`) —
skip to step 8. Non-interactive install (the usual case for you): the file
`data/framework/auth/SETUP_TOKEN` exists. Then:

1. Start the server (step 8).
2. Tell the person to open `http://127.0.0.1:<port>/backend/system/setup/setup`, enter the
   token from that file (show it to them only when they ask for it) and create the admin
   account with a password **they** choose. The token is deleted afterwards. Until then the
   login page `/login` shows a link to that screen, and the installer's last lines name the
   token file and the URL (https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/installer.md#first-login).

**Check:** `data/framework/auth/backendUsers.json` exists and `SETUP_TOKEN` is gone.

### Step 8 — Start and look

```bash
php -S 127.0.0.1:<port> -t public
```

Run it in `<project>`. Use `127.0.0.1`, not `localhost`: on Windows `php -S localhost:…`
listens on IPv6 only (`[::1]`), and requests to `127.0.0.1` then hang. It is the safe
equivalent of `composer dev`, which runs `composer install` first and then the dev server
(skeleton `scripts.dev`, port `8080`) — **do not use `composer dev` from your shell**: its
`composer install` step can wait for input. The server keeps running — start it in the
background or let the person start it in their own terminal (`composer dev` is fine there).
To make `composer dev` use another port, change `scripts.dev` in `composer.json`.

**Check (together with the person, in the browser):**
- `http://127.0.0.1:<port>/` and the starter pages (`/about`, `/services`, `/contact`,
  `/legal`, `/privacy`) load; `/en/…` as well,
- `/login` → the admin signs in,
- suite: one backend screen per database module (for example Kontakte) opens without error,
- `logs/` holds no new error.

### Step 9 — `z77/docs` for the assistant

If `vendor/z77/docs/README.md` does not exist (non-interactive install, or declined), ask,
then run:

```bash
composer require --dev z77/docs
```

It is a dev dependency: version-matched to the framework, never part of a `--no-dev` deploy.

**Check:** `vendor/z77/docs/README.md` exists.

### Step 10 — First steps in the backend (suite only)

These are steps for the person in the browser; you explain them, you do not script them.
They are entries the person makes in the backend, in this order — guide them, one at a
time: chart of accounts, the Mandant (company record), fiscal year, VAT codes, payment target
with IBAN, articles and order statuses, contacts and their roles, receipts and the phone app,
bank accounts, supplier invoices, outbox — as far as their modules reach. The exact menu paths
and what each step needs:
https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md#4-first-steps-in-the-backend

---

## 5. Hard rules

1. **CE-first — override, never fork.** Every project change lives in the project: its own
   files under `override/z77/…`, `composer.json`, `config/client/`, `data/`. Never edit
   anything under `vendor/` — the next `composer update` erases it.
   https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/architecture.md
2. **Generated files stay generated.** `config/vendor/*` is rewritten on every install —
   change `composer.json` instead.
3. **No real customer data in demo or test setups.** Use invented names, addresses, IBANs and
   amounts until the person decides the installation goes into real use.
4. **No secrets in the repository.** Passwords, tokens and keys live only in
   `config/client/` (gitignored) or the person's password manager — never in `composer.json`,
   a commit, a code comment or a document you write.
5. **Never touch a deploy server above the project root.** Deployment is not part of this
   guide; it follows the release rules, and only with the person:
   https://github.com/z77-ch/z77-framework/blob/main/.releases/RULES.md and
   https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/new-project-manual.md
   stages 4–6. On a server: uploads go into `releases/<name>` only, never over `current`,
   `next` or `shared/`.
6. **Tests and docs as the project's `CLAUDE.md` says.** After installation that file is
   your working rule set for this project.

---

## 6. After the installation

- Read the project's **`CLAUDE.md`** (seeded by the installer — it belongs to the project and
  may be edited) and follow it.
- The framework documentation is in **`vendor/z77/docs/`**: start at
  `vendor/z77/docs/README.md` (it has a topic trigger map); before working in an area, read
  `vendor/z77/docs/topics/<topic>.md`. The first page:
  `vendor/z77/docs/01-handbook/create-page.md`.
- Tell the person in two or three sentences what is installed, where the project is, how to
  start it (`composer dev` in a terminal, or `php -S 127.0.0.1:<port> -t public`) and where to sign in (`/login`).

---

Online: https://github.com/z77-ch/z77-framework/blob/main/INSTALL-AI.md ·
raw: https://raw.githubusercontent.com/z77-ch/z77-framework/main/INSTALL-AI.md

[MIT](https://github.com/z77-ch/z77-framework/blob/main/LICENSE) © Peter Ruepp (z77)
