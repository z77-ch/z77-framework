# Member findings (MEM-001…008) — solution plan

**Status:** `[DONE]` — MEM-003, 005, 006 built and MEM-007 decided on 2026-08-07;
MEM-001/002/004 stay documented constraints, MEM-008 deferred with a named trigger
**Date:** 2026-08-07
**Source:** [`member-login-security-review-2026-08-07.md`](member-login-security-review-2026-08-07.md),
[`../topics/member.md`](../topics/member.md) `## known issues`

One section per finding: what it is, what closing it costs, and a recommendation. Three of
the eight are constraints with no fix worth building — saying so is part of the plan, so
they stop being re-opened at every review.

## Summary

| # | Finding | Outcome |
|---|---|---|
| MEM-006 | Mail links from the Host header | **Built, then redesigned** — `canonicalBaseUrl` → `Request::getBaseUrl()`. Scope grew twice: the same header also fed the SEO canonical/hreflang (page cache stores it by path only), and the storage location this plan proposed turned out wrong. Final shape: [ADR-030](../02-decisions/adr-030-system-configuration.md) |
| MEM-003 | `extract()` swallows `context` / `path` | **Built** — prefixed locals in all three render scopes; also closes review-create-css B1 |
| MEM-005 | Throttled login answer differs | **Built** — `LoginFlow::request()` returns true unconditionally (login path only, deliberately) |
| MEM-007 | Confirm link changes state on a GET | **Decided** — rule narrowed to state changes that grant access; the flow is unchanged |
| MEM-008 | Resume rewrites `accounts.json` | **Deferred** — trigger: a shortened idle window or a dropped session cookie |
| MEM-001 | `MemberAccount.roles` is not an ACL input | **Deferred** — ADR-029 owns it; trigger is the first role-based member app |
| MEM-002 | Member session vs. page cache | **Documented constraint** — nothing to build |
| MEM-004 | Orphaned device keys | **Documented constraint** — cap + profile list are the answer |

### What the implementation turned up that this plan did not

Removing the dead-looking `url_origin()` preBoot helper showed it was not dead:
`AbstractBaseController::buildSeoLinks()` used it to build the SEO canonical and hreflang
URLs from `HTTP_HOST`, and those are rendered INTO the page. `PageCache` keys on
`{language}/{module}/{group}/{controller}/{action}` — **no host** — so a single request with
a forged Host poisoned the canonical served to every later visitor until the entry expired.
That is a wider blast radius than the mail case this plan was written around, and it landed
in the same commit through the same seam. The review missed it because it scoped to
`module-member`; the lesson is that a header-derived value is worth grepping framework-wide,
not per module.

---

## MEM-006 — mail links are built from the Host header (also SEC-005)

**Now:** `AbstractMemberController::absoluteUrl()` and
`AccountsControllerTrait::memberAbsoluteUrl()` read `$_SERVER['HTTP_HOST']`, a
client-controlled header, and there is no trusted-host concept anywhere in the framework.
`Request::parseUrl()` reads it too. (`getBaseUrl()` in the preBoot `Functions.php` also
does, but nothing calls it — see the cleanup note below.)

**Attack:** a forged Host under a catch-all vhost turns a genuine login mail into token
exfiltration, as described in the review (F1).

> **Superseded — read [ADR-030](../02-decisions/adr-030-system-configuration.md) instead.**
> The proposal below was implemented and then redesigned the same day. Three of its five
> points turned out wrong, and it is kept only because the reasoning that broke them is worth
> having:
>
> | Proposed here | What it became | Why |
> |---|---|---|
> | Key in `bootstrap.default.inc.php`, fed from `composer.json` | Own seed-once file `config/systemConfig.inc.php` | `composer.json` is committed, so staging and production could not differ; `bootstrap.inc.php` is regenerated on install, so a value corrected on the server was lost |
> | Empty falls back to the `Host` header, logged | Empty **throws** at the point of use | A logged fallback is a fallback nobody reads; and once the value has a real home, there is no installation left that needs the old behaviour |
> | Accessor on `Request` | Constant `CANONICAL_BASE_URL` from `Bootstrap` | `Request` does not exist in CLI — a cron that mails a link had nothing to call |
> | Installer seeds the value from the install host | Installer seeds the KEY, empty | `composer install` runs on a CLI with no HTTP host; there is nothing to derive |
>
> Points 2 (leave `parseUrl()` alone) and 4 (collapse the two call sites) survived unchanged.

### Proposal (superseded)

**1. One config value, semantically named.** `canonicalBaseUrl` in
`packages/kernel/core/src/Config/bootstrap.default.inc.php` — it belongs next to
`htmlRoot` and `timeZone`, because it states what this installation IS, not what an auth
policy demands. Full origin, not just the host, so a TLS-terminating proxy stops mattering
for link generation as well:

```php
// bootstrap.default.inc.php
'canonicalBaseUrl' => '',   // e.g. 'https://kunde.ch' — empty falls back to the request (dev)
```

**2. One accessor.** `Request::getBaseUrl(): string` returns the configured value when set,
otherwise scheme + `HTTP_HOST` as today. Deliberately does NOT touch `parseUrl()`: routing
must keep working on whatever host actually answered, and only URL *generation* is the
attack surface. Smallest blast radius for the fix.

**3. Fail loud exactly where it matters.** An installation that forgets the config value
keeps today's behaviour everywhere — except that generating a link which will be MAILED
without a configured canonical base URL throws. That is the difference between fail-open
(useless) and fail-closed-everywhere (breaks every dev setup). Concretely: `LoginFlow` /
`RegistrationFlow` get their URLs from the controller as today, but the controller builds
them through a helper that demands the config value when the target is a mail.

**4. Call sites collapse.** `AbstractMemberController::absoluteUrl()` and
`AccountsControllerTrait::memberAbsoluteUrl()` become `DI::getRequest()->getBaseUrl() . $path`
— which also closes their conventions-rule-4 violation (`$_SERVER` outside `Request`).

**5. The installer seeds it.** `config/bootstrap.inc.php` is already generated at install
like `auth.inc.php`; the install host is known at that moment, so the value can be written
without asking. A re-install must not silently overwrite a corrected value.

**Optional tier 2 (not recommended yet):** reject requests whose Host differs from the
canonical one (400/421). Defense-in-depth against cache poisoning, but it breaks apex-vs-www
and staging setups, so it needs its own opt-in switch and a migration story. Park it.

**Touches:** `Request`, `bootstrap.default.inc.php`, `Install`, the two module helpers,
plus `security.md` (SEC-005 → resolved), `member.md` (MEM-006 → resolved), `bootstrap.md`
(new key), `conventions.md` (rule 4 gains its missing accessor).

**Verification:** request the login page with `Host: evil.tld` on an installation with
`canonicalBaseUrl` set and assert the mail body still points at the configured host.

**Cleanup rider:** `getBaseUrl()` in `core/src/autoload/preBoot/php/Functions.php` has no
callers and honours `HTTP_X_FORWARDED_HOST` unconditionally. Delete it in the same pass so
it cannot become the next unnoticed source of a poisoned URL.

---

## MEM-003 — `extract($context, EXTR_SKIP)` swallows `context` and `path`

**Now:** `TemplateRenderer::render(string $path, array $context)` extracts with
`EXTR_SKIP`, so a view variable named `path` or `context` silently keeps the method's own
value and the template renders the wrong thing — no error, no warning. It already bit once:
this module named its variable `pending` to dodge it.

### Verified before proposing (2026-08-07)

Reproduced against the real class, not reasoned about — probe renders a template with a
context carrying `path`, `context` and a control key:

```text
CURRENT   path    = '…/mem003-probe.tpl.php'          ← the renderer's own local
          context = array('path'=>…, 'context'=>…)     ← the whole context array
          normal  = 'PROJECT-VALUE-NORMAL'
PROPOSED  path    = 'PROJECT-VALUE-PATH'
          context = 'PROJECT-VALUE-CONTEXT'
          normal  = 'PROJECT-VALUE-NORMAL'             ← unchanged
```

`$this->partial()` still resolves from the private method (checked separately) — the
isolated scope must stay a method, not a static closure, for exactly that reason.

Blast radius, measured:

- **120 framework templates and 48 project templates contain no `$path`, `$context`,
  `$data` or `$tplPath`.** The single hit is `slider.tpl.php`, where `$path` is a `foreach`
  value the template assigns itself before reading — unaffected either way.
- **No caller passes a context key of those names.** Every `'path' =>` in the codebase is an
  internal structure (asset registry, partial descriptors, cookie params, backup config),
  never a template context.
- **No template uses `get_defined_vars()` or `compact()`,** so nothing observes the scope's
  shape.

Conclusion: the change is additive. Names that are silently dead today start working; no
template can lose a value it currently reads.

### Three extract sites, two opposite failure modes

| Site | Flag | Who wins | Failure |
|---|---|---|---|
| `TemplateRenderer::render` | `EXTR_SKIP` | the locals | context key silently dropped (MEM-003) |
| `EmailService::render` | none | the context | a `tplPath` key would `include` the wrong file |
| `StylesheetManager::createCss` | none | the context | same — and this is **B1**, still open |

The second row is worth stating plainly: a context key called `tplPath` does not just render
wrong, it selects the included file. Unreachable today (contexts are built by app code with
fixed keys), but it is the sharper of the two edges.

### This supersedes B1 in `review-create-css.md`

[`review-create-css.md`](review-create-css.md) B1 found the `StylesheetManager` overwrite
and proposed `extract($data, EXTR_SKIP)`. That fix was never applied, and it is the wrong
direction: it protects the locals by making the context lose — which is precisely the
behaviour MEM-003 complains about in `TemplateRenderer`. Adopting it would close B1 by
spreading MEM-003 to a third site.

Renaming the locals dissolves both findings at once: no collision is possible, so there is
no winner to choose. Keep `EXTR_SKIP` on top as belt-and-braces, and a context key would
have to be named `z77TplPath` to reach anything.

**ADR check:** ADR-002 sketches `extract($context, EXTR_SKIP)` in a code comment, but it is
descriptive and the ADR is marked superseded by ADR-003/004. No decision blocks this.

### Proposal

Move the extraction into a scope whose only locals cannot collide. It has to stay a
*method*, not a static closure, because templates call `$this->partial()`:

```php
public function render(string $path, array $context = []): string
{
    if (!is_file($path)) {
        throw new ViewException("Template not found: {$path}");
    }

    return $this->renderIsolated($path, $context);
}

/** Locals are prefixed so no view variable can collide with them (EXTR_SKIP would win). */
private function renderIsolated(string $z77TplPath, array $z77TplContext): string
{
    extract($z77TplContext, EXTR_SKIP);
    ob_start();
    require $z77TplPath;

    return ob_get_clean();
}
```

Same treatment for `EmailService::render()` and `StylesheetManager::createCss()` — both keep
their static closures (templates must not reach `$this` there), only the parameter names
change and `EXTR_SKIP` gets added. The rule then reads «view variables may be named
anything» everywhere, instead of «anything except the words that happen to be locals here».

**Risk:** none measured — see the blast radius above. The residual is a template someone
writes between now and the change that starts depending on the leak, which the same grep
catches.

**Docs to update in the same commit:**

| File | Change |
|---|---|
| `docs/topics/member.md` | MEM-003 → resolved; drop the note that this module uses `pending` as a work-around |
| `docs/03-development/review-create-css.md` | B1 → resolved, by renaming rather than by the `EXTR_SKIP` it proposed |
| `docs/03-development/concepts/view-layer.md` | line 14 states `extract($context, EXTR_SKIP)` — reword |
| `docs/01-handbook/templates.md` | line 11 describes the pipeline — add that any name is safe |
| `docs/topics/view-layer.md` | new rule: view variables may carry any name; the renderer's locals are prefixed |

**Acceptance:** re-run `scratchpad/mem003-check.php` (both blocks print the project values),
then render one page, one mail and one generated CSS in the running app.

---

## MEM-005 — a throttled login request does not get the neutral answer

**Now:** `LoginFlow::request()` returns `false` on throttle, so `PublicFormHandler`
re-renders the form with the send-error banner instead of redirecting to the waiting page.
The doc has been corrected to match; the code is unchanged.

### Proposal

Return `true` unconditionally. The throttle keeps doing its job — it still suppresses the
mail — and only the visible answer changes:

```php
public function request(string $email, bool $remember = false, ?int $now = null): bool
{
    // ... unchanged: $allowed decides whether a token is issued and a mail goes out
    return true;   // the page answer never depends on throttle state
}
```

This also repairs an inconsistency that exists today: the throttled branch already opens a
waiting record and writes its id into the session, but the visitor never reaches the page
that would use it. After the change the record is exactly what it was written for, and the
comment in `request()` becomes true again.

**The price:** a real user who genuinely hit 5 requests in an hour gets no feedback at all —
the waiting page simply never turns green. That is the same silence unknown addresses get,
which is the point of the design, but it is a support case waiting to happen. If that
worries you, the alternative is to leave the code and keep the documented exception; the
finding leaks nothing either way.

**Verification:** six requests for one address within an hour must produce six identical
`/warten` pages.

---

## MEM-007 — the registration confirm link changes state on a GET

**Now:** `ConfirmController::indexAction` flips `registered → confirmed` on a plain GET,
against this module's own rule that a mail-triggered state change must go through a POST.
B8's login link follows the rule; B7 predates it.

### Proposal — and my argument against the obvious fix

The obvious fix is the B8 pattern: the link lands on a page with a «Bestätigen» button and
the POST does the transition. I would not do it, and the reason is that the rule's own
justification does not carry here.

The rule exists because an automated fetch must not grant access. Confirmation grants none:
it moves the account into the operator's queue, and everything past that point — activation,
and every later login — needs a fresh mail in the same mailbox. What a scanner's auto-fetch
costs is the claim «a human clicked», downgrading it to «the mailbox accepted», which is
what e-mail confirmation actually proves anyway. Against that stands one more click on the
highest-friction step of the whole registration.

So: **narrow the rule** to what it is really about, in `member.md`:

> When a state change from a mail GRANTS ACCESS (a session, a role, a device key) → MUST
> route it through a POST on a page. A change that only records mailbox control (the
> registration confirmation) MAY happen on the GET — an automated fetch proves the same
> thing a click does.

If you would rather keep one blunt rule and pay the click, the change is small and
self-contained: a landing template plus a POST branch in `ConfirmController`, mirroring
`redeemAction`. Your call — I have no stake beyond the reasoning above.

---

## MEM-008 — a resumed session rewrites `accounts.json`

**Now:** corrected in the review — `restore()` runs only where `MemberAuth::current()`
finds no session, so it is one write per browser start or 2-hour idle timeout, not per
request. Same order of magnitude as a login.

### Proposal

Roll lazily: in `DeviceKeys::restore()`, skip the account save and the cookie refresh while
`last_used_at` is younger than a day. The 90-day rolling semantics survive — a device used
daily still rolls daily — and the cookie must be refreshed on the same schedule as the key,
never less often, or the browser copy would expire before the server's.

**Recommendation: defer.** The write frequency does not justify touching a working
mechanism. Take it up if the idle window is ever shortened or the session cookie is dropped,
because both turn every page view into a resume.

---

## MEM-001 — `MemberAccount.roles` is not an ACL input

Not a defect: it is the standing decision in
[ADR-029](../02-decisions/adr-029-member-session-and-framework-acl.md). Member routes are
`AuthRole::GUEST` and every member page guards itself through `MemberAuth`; declaring a
route `AuthRole::MEMBER` locks signed-in customers out, which is why the trap is documented.

**Nothing to do now.** The trigger for re-opening it is named in the ADR: the first
role-based member application (AXO3 B10), where per-function rights would otherwise be
re-implemented in every controller. The likely shape is a NEW role level for customer
accounts rather than reusing `MEMBER` (level 20), because `MEMBER` already means something
to DMS ACEs and to the page-cache rule. Re-opening it means amending the ADR, not working
around it.

---

## MEM-002 — a member session does not survive the page cache

A constraint, not a bug. Member pages set `cache.enabled = false` in the module config. A
project that moves member-specific markup onto a cacheable page must widen the bypass first
— the procedure is `cache.md` CACHE-ADMIN-001.

**Nothing to build.** Keep the note so the next person does not discover it through a
customer seeing someone else's cached page.

---

## MEM-004 — orphaned device keys

A property of the mechanism: the cookie IS the identity, so a deleted cookie leaves an entry
the server cannot distinguish from a second real device with the same browser. Anything
"clever" here — matching on the User-Agent label, on IP, on timing — would either merge two
genuine devices or fail exactly when it matters.

**Nothing to build.** The cap (`MAX_KEYS = 5`, drops the longest-unused, which is precisely
the orphan) and the profile list with its explanatory note are the answer, and both exist.

---

## Suggested order

1. **MEM-006** — the only item with an attacker in the story.
2. **MEM-003** — cheap, and it removes a whole class of silent template bugs.
3. **MEM-005** + **MEM-007** — one small commit each, both mostly decisions.
4. **MEM-008** — when its trigger appears.
5. **MEM-001** — when ADR-029's trigger appears.

MEM-006 and MEM-003 are both kernel changes and should be separate commits: one is a
security fix worth finding again in the log, the other is a rendering change that touches
every page.
