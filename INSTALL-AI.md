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

**You never** choose, see or store the admin password, put a password into a tracked file
or into the chat log you write, edit anything under `vendor/`, or deploy.

---

## 2. Preflight checks

Run each command, compare with the expected result, tell the person the outcome in one line.

| Check | Command | Expected |
|---|---|---|
| PHP version | `php -r "echo PHP_VERSION, PHP_EOL;"` | **≥ 8.2** for the web stack · **≥ 8.4** for the business suite |
| PHP extensions | `php -m` | contains `mbstring`, `fileinfo`, `zip`, `gd` · for the business suite also `pdo_mysql` · `apcu` is optional (recommended; then `apc.enable_cli=1`) |
| Composer | `composer --version` | **2.x** |
| Git | `git --version` | any 2.x (needed for the pre-2.0.0 business-suite path, §4 box) |
| MariaDB (business suite only) | `mariadb --version` (or `mysql --version`) | MariaDB **≥ 10.6**, running and reachable on the machine |
| Write permission | `php -r "var_dump(is_writable('.'));"` in the folder that will hold the project | `bool(true)` |

Node.js / npm are **not** needed: the framework ships compiled CSS and minified JS. They
matter only later, when someone edits SCSS.

The requirements come from the packages' `composer.json` and
https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/dev-environment.md —
read it if a check is unclear.

On any failure: report and stop (§1). Installing or upgrading PHP, Composer or MariaDB is
the person's decision — offer to explain how for their system, do not do it unasked.

---

## 3. Questions to ask before you act

Ask these together, in plain language, and wait for the answers. Do not assume defaults.

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
   | `module-creditor` | supplier invoices — **in progress**, check its status first: https://github.com/z77-ch/z77-framework/blob/main/docs/topics/creditor.md | contact, vat, mandator, financial, receipt, dms |

   Every business module except `module-vat` also requires `z77/persistence-doctrine` (the
   database driver). Source: each package's `composer.json` → `require`, and
   https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md §1.
3. **Database** (suite only): does a database and a database user exist already, or should
   they be created — and who does it (the person, or you with their MariaDB admin login)?
   Explain where the credentials will go (§4 step 5) and that they never enter a tracked file.
4. **The first admin:** explain that the person chooses the admin password themselves in the
   browser (§4 step 7). You will not see it.
5. **Project folder:** where on their machine, and its name. For the pre-2.0.0 suite path
   also: where the framework repository may be cloned (next to the project).
6. **Local port** for the development server (default `8080`; one port per project if they
   run several).

---

## 4. Installation — in order, each step with its check

`<project>` = the project folder, `<port>` = the local port from §3.

### Step 1 — Create the project

```bash
composer create-project z77/skeleton <project>
```

This pulls the skeleton and the web-stack packages (`z77/kernel`, `z77/module-frontend`,
`z77/module-backend`, `z77/module-dms`) from Packagist and runs the installer (step 4
happens here already). Alternative through GitHub: the template repository
https://github.com/z77-ch/z77-skeleton → «Use this template» → clone → `composer install`.

**Check:** `<project>/composer.json`, `<project>/vendor/z77/kernel/`, `<project>/public/index.php`
exist.

> **Until release 2.0.0: the business suite is not on Packagist.**
> Only the web stack and `z77/docs` are. For a project with business modules, follow
> https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/new-project-manual.md
> stage 1 instead of `create-project` (ask before cloning):
>
> 1. `git clone https://github.com/z77-ch/z77-framework.git` next to the project folder.
> 2. Start the project `composer.json` from the repository's `skeleton/composer.json`
>    (https://github.com/z77-ch/z77-framework/blob/main/skeleton/composer.json) and adapt it:
>    `name`, `description`; `"php": ">=8.4"`; keep `"minimum-stability": "dev"` and
>    `"prefer-stable": true`.
> 3. `repositories`: one `path` repository **for every z77 package in the dependency
>    graph** — the web stack too (`kernel`, `module-frontend`, `module-backend`,
>    `module-dms`), `persistence-doctrine`, every chosen module and everything it requires
>    (table in §3), and `docs`. Relative to the project, with symlinks:
>    `{"type": "path", "url": "../z77-framework/packages/kernel", "options": {"symlink": true}}`
>    (`docs` is `../z77-framework/docs`).
> 4. `require`: the web stack and the chosen modules; `require-dev`: `z77/docs`.
> 5. Write the `.gitignore` of step 2 before the first commit.
>
> Then continue with step 2. On release 2.0.0 this box goes away and the modules are added
> with `composer require` like any package.

### Step 2 — `.gitignore`

The project's `.gitignore` must ignore at least: `/vendor/`, `/config/`, `/data/`,
`/backup/`, `/var/`, `/logs/`, `/public/assets/`, `/public/media/`, `/public/storage/`,
`/.vscode/`, `/.claude/`, `/docs/temp/`, `/composer.lock`
(new-project-manual.md stage 1). `config/` and `data/` hold credentials and the admin
account. If the file is missing or lacks an entry, show the person the change, then add it.

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

`Z77\Module\Dms\` is already in the skeleton. Put the packages into `require` in the same
edit (pre-2.0.0: with their path repositories, box above). Register **all** modules now,
before the one migration run in step 6.
Source: https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/business-suite-setup.md §2.

**Check:** `composer validate` reports no error.

### Step 4 — `composer install` (or `composer update` after editing `composer.json`)

Ask first. Then run it in `<project>` — `composer update` when you changed `composer.json`
in step 3 or the box, `composer install` otherwise; a website-only project created with
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
`hiddeninput.exe: Resource temporarily unavailable`, run `composer install --no-interaction`.
Details: https://github.com/z77-ch/z77-framework/blob/main/docs/01-handbook/installer.md

**Check:**
- the run ends with `✓ Z77 Core installation complete`,
- `config/vendor/moduleManager.inc.php` lists every module of step 3,
- `config/client/systemConfig.inc.php`, `config/client/database.inc.php` and `CLAUDE.md` exist.

### Step 5 — Settings of this installation

1. `config/client/systemConfig.inc.php`: set `canonicalBaseUrl` to
   `http://localhost:<port>` and, if the person wants, `installationName`. While
   `canonicalBaseUrl` is empty, website pages answer with an error 500 (the installer prints a
   reminder at the end of every run).
2. **Suite only — the database.** If the database does not exist yet, it is created in the
   MariaDB client by an account with admin rights (ask who does it; the password is chosen
   by the person):
   ```sql
   CREATE DATABASE <db> CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER '<db>'@'127.0.0.1' IDENTIFIED BY '<password>';
   GRANT ALL PRIVILEGES ON <db>.* TO '<db>'@'127.0.0.1';
   ```
   The connection goes into **`config/client/database.inc.php`** and nowhere else: `host`,
   `port` (`null` = 3306), `name`, `user`, `password`. This file is seed-once and
   gitignored. Best: the person types the password into the file themselves. On Windows set
   `host` to `127.0.0.1`, not `localhost` (`localhost` measured ~2 s per request there). The
   person keeps the password in a password manager, not in the project.

**Check:** `git status` does not list anything under `config/` or `data/`.

### Step 6 — Create the database tables (suite only) — one run, after all modules are registered

Ask first. Then:

```bash
php vendor/bin/z77-db status     # names the project root and the database — read them out to the person
php vendor/bin/z77-db migrate
php vendor/bin/z77-db status
```

Do not order migrations by hand: `z77-db` collects them from `persistence-doctrine`, every
**registered** module and the project, and runs them in timestamp order across all modules.
If `status` names a different project root or database than expected: stop.

**Check:** the last `status` shows `New 0`.

### Step 7 — The first admin

Interactive install: the installer has already asked for the password (username `admin`) —
skip to step 8. Non-interactive install (the usual case for you): the file
`data/framework/auth/SETUP_TOKEN` exists. Then:

1. Start the server (step 8).
2. Tell the person to open `http://localhost:<port>/backend/system/setup/setup`, enter the
   token from that file (show it to them only when they ask for it) and create the admin
   account with a password **they** choose. The token is deleted afterwards.

**Check:** `data/framework/auth/backendUsers.json` exists and `SETUP_TOKEN` is gone.

### Step 8 — Start and look

```bash
composer dev
```

This runs `composer install` and then PHP's built-in server for `public/` (the skeleton
uses port `8080`; another port: change `scripts.dev` in `composer.json` to
`php -S localhost:<port> -t public`). It keeps running — start it in the background or let
the person start it in their own terminal.

**Check (together with the person, in the browser):**
- `http://localhost:<port>/` and the starter pages (`/about`, `/services`, `/contact`,
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
(Pre-2.0.0 suite projects get it through the `docs` path repository of the box in step 1.)

**Check:** `vendor/z77/docs/README.md` exists.

### Step 10 — First steps in the backend (suite only)

These are entries the person makes in the backend, in this order — guide them, one at a
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
  start it (`composer dev`) and where to sign in (`/login`).

---

Online: https://github.com/z77-ch/z77-framework/blob/main/INSTALL-AI.md ·
raw: https://raw.githubusercontent.com/z77-ch/z77-framework/main/INSTALL-AI.md

[MIT](https://github.com/z77-ch/z77-framework/blob/main/LICENSE) © Peter Ruepp (z77)
