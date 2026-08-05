# entity-data-handling

2026-08-03

## entry

1. `packages/kernel/persistence/src/Cleaning/BodyCleaner.php` — orchestrator for input cleaning (clean → hydrate → validate pipeline)
2. `packages/kernel/persistence/src/Validation/EntityValidator.php` — abstract base class for all entity validators
3. `packages/kernel/shared/src/Attributes/Clean.php` — property attribute declaring filter chain per entity field

Add/Edit flow runs end-to-end through `NavigationController` and `Tag`-actions in the same controller — both follow the symmetric pattern: GET renders form into the popup (`[data-z77-popup-body]`), POST runs `BodyCleaner` → `mapFromArray` → validator → persist. UI never builds URLs or constructs save payloads; that is shared `core.js` + server convention (see [`fetch.md`](fetch.md)).

## file map

SOURCE=/packages/kernel/persistence/src/Cleaning/BodyCleaner.php
SOURCE=/packages/kernel/persistence/src/Cleaning/FilterRegistry.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/FilterInterface.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/TextFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/SlugFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/IdentFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/EmailFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/IntFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/BoolFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/ListFilter.php
SOURCE=/packages/kernel/persistence/src/Cleaning/Filters/NullableFilter.php
SOURCE=/packages/kernel/persistence/src/Validation/EntityValidator.php
SOURCE=/packages/kernel/persistence/src/Concurrency/EntityStateHash.php
SOURCE=/packages/kernel/persistence/src/File/Repository/FileRepository.php
SOURCE=/packages/kernel/shared/src/Attributes/Clean.php
SOURCE=/packages/kernel/shared/src/Validators/NavigationValidator.php
SOURCE=/packages/kernel/shared/src/Traits/ArrayMappable.php
SOURCE=/packages/kernel/shared/src/Libraries/Convention/Naming.php
SOURCE=/packages/kernel/shared/src/Entities/Navigation.php
SOURCE=/packages/module-backend/src/Ui/Controllers/Content/NavigationController.php

## mental model

Entity data handling follows a three-step pipeline: **clean → hydrate → validate**. Incoming POST body is run through `BodyCleaner::cleanFor($entityClass, $body)` which reads `#[Clean(...)]` attributes on entity properties and applies a filter chain per field. The cleaned array is passed to `mapFromArray()` which calls dumb setters; the validator then reads back the entity via `mapToArray()` and runs `validate{FieldName}()` methods.

- **Mass-assignment protection follows the Doctrine convention** — properties without a setter cannot be hydrated from a body. `id` and other server-controlled fields have no setter; the file repository sets them via reflection during storage load.
- Cleaning is silent typo correction (trim, lowercase emails, strip non-allowed chars in slugs); validation is the loud feedback channel for semantic errors. Cleaning must never change meaning.
- Filter chains are compiled once per entity class and cached statically. Filter classes load only on first use (autoloader + lazy registry).
- Properties without `#[Clean]` are pass-through — body value reaches the setter unchanged. Use this for already-typed values (e.g. internal dropdown ints) where cleaning would be no-op.
- Validators receive already-cleaned values. Rule methods (`notEmpty`, `minLength`, etc.) do not re-trim — that is `TextFilter`'s job.
- Concurrent edits are caught by optimistic locking: the form carries an `entity_hash` of the stored state (next to `entity_csrf`), `$validator->guardStoredState()` compares it against the freshly loaded entity on POST — a mismatch rejects the save through the normal validation channel (see [optimistic locking](#optimistic-locking-entity_hash)).

## flow

```text
HTTP POST (JSON body)
    │
    ▼  controller editAction()
$body = DI::getRequest()->getJsonBody();
    │
    ▼
BodyCleaner::cleanFor(EntityClass::class, $body)
    │  per property (cached plan via Reflection):
    │   • #[Clean('a','b',...)] present → registry.compile(['a','b',...]).apply($body[key])
    │   • no #[Clean]            → pass-through $body[key] as-is
    │   • body key missing       → skip (existing entity value kept)
    ▼
$entity->mapFromArray($cleaned)
    │  setter found → called with cleaned value
    │  no setter    → silently ignored (mass-assignment safe)
    ▼
$validator->isValid()
    │  iterates mapToArray(), invokes validate{FieldName}() on cleaned values
    ▼
persist + flush + clearAllApcu (on success)
re-render edit.tpl.php (on validation errors)
```

## cleaning filters

`#[Clean('filter')]` runs a single filter. `#[Clean('wrapper', 'inner')]` composes a wrapper around an inner filter. All filter args are positional strings.

### Leaf filters

| Filter | Cleaning logic | Use case |
|---|---|---|
| `text` | strip control chars, collapse whitespace, trim | display names, titles, descriptions |
| `slug` | lowercase, trim, `\` → `/`, keep only `[a-z0-9_\-/]` | URL paths, slugs |
| `ident` | trim, keep only `[a-zA-Z0-9_\-]` | module / controller / action names |
| `email` | lowercase, strip all whitespace | email addresses |
| `int` | empty/null → `null`, else `(int)` | numeric ids and counts |
| `bool` | `null \| false \| 0 \| '0' \| '' \| 'false'` → `false`, else `true` | checkbox values |

### Wrapper filters

| Wrapper | Logic | Example |
|---|---|---|
| `list` | array_filter + apply inner to each element, drop empties, reindex | `#[Clean('list', 'ident')]` on `array $items` |
| `nullable` | apply inner, then `'' \| null \| []` → `null` | `#[Clean('nullable', 'slug')]` on `?string $aliasUrl` |

Wrappers can compose: `#[Clean('list', 'nullable', 'slug')]` produces a list whose elements are null when empty.

## validation feedback channels

Two paths share the same DOM convention (`[data-z77-field-wrapper]` + `aria-invalid="true|false"` + `<small data-z77-field-error>` — see [`fetch.md`](fetch.md) field validation section):

| Path | Trigger | Renderer | Use |
|---|---|---|---|
| Server-rerender | POST save fails `$validator->isValid()` | `edit.tpl.php` reads `$validator->getFieldError()` + renders `aria-invalid="true"` and the error `<small>` | full save with multi-field errors |
| Client blur check | Input blur on form carrying `data-check-url` | `core.js` `fields`-handler calls `_Z77.core.fields.mark()` | live single-field UX |

Both paths re-validate server-side — the client never decides validity. POST save runs the full validator regardless of any prior successful blur checks.

## controller pattern

```php
protected function addAction(): HtmlResponse|FetchResponse
{
    return $this->edit(new EntityClass());
}

protected function editAction(): HtmlResponse|FetchResponse
{
    $entity = $this->repo()->find((int)DI::getRequest()->getGetParameter('id'));
    if ($entity === null) {
        $this->messageService->pushFlash('error', 'Entry not found');
        return $this->fetch()->setStatus('error');
    }
    return $this->edit($entity);
}

private function edit(EntityClass $entity): HtmlResponse|FetchResponse
{
    $isNew     = $entity->getId() === null;
    $validator = new EntityClassValidator($entity);

    // Optimistic locking: on GET the form gets the hash of the freshly loaded
    // state; after a failed POST the SUBMITTED hash is re-issued unchanged —
    // the form content is still based on that state, so a conflict stays a
    // conflict until the user reloads.
    $entityHash = '';

    if (DI::getRequest()->isPost()) {
        $body = DI::getRequest()->getJsonBody();

        if (!$isNew) {
            $csrf = trim($body['entity_csrf'] ?? '');
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'entity-type', $entity->getId())) {
                $this->messageService->pushFlash('error', 'Invalid token');
                return $this->fetch()->setStatus('error');
            }

            $entityHash = trim($body['entity_hash'] ?? '');
            $validator->guardStoredState($entityHash);   // BEFORE mapFromArray — entity still carries the stored state
        }

        $entity->mapFromArray(BodyCleaner::cleanFor(EntityClass::class, $body));

        if ($validator->isValid()) {
            // persist, flush, clearAllApcu
            // in-place success (same page):
            //   $this->messageService->pushFlash('success', 'Eintrag «...» gespeichert');
            //   return $this->fetch()->setStatus('success')->addCommand('update-fields', [...]);
            // success with reload (after page reload):
            //   $this->messageService->pushFlashAfterRedirect('success', 'Eintrag «...» angelegt');
            //   return $this->fetch()->setStatus('success')->addCommand('reload');
        }
        // validation failed — fall through to form re-render
    }

    $entityCsrf = !$isNew ? DI::getCsrfService()->generateEntityToken('entity-type', $entity->getId()) : '';
    if (!$isNew && !DI::getRequest()->isPost()) {
        $entityHash = EntityStateHash::of($entity);
    }
    $response = $this->html(['entry' => $entity, 'entityCsrf' => $entityCsrf, 'entityHash' => $entityHash, 'validator' => $validator]);
    $this->layoutManager->addPartials('edit', 'EntityClassController', self::NAMESPACE);
    return $response;
}

// Optional: checkField endpoint for blur-based live validation
protected function checkFieldAction(): FetchResponse
{
    if (!DI::getRequest()->isPost()) {
        $this->messageService->pushFlash('error', 'Method not allowed');
        return $this->fetch()->setStatus('error');
    }

    $body  = DI::getRequest()->getJsonBody();
    $field = (string)($body['field'] ?? '');
    if ($field === '') return $this->fetch();

    $cleaned = BodyCleaner::cleanFor(EntityClass::class, [$field => $body['value'] ?? '']);

    $entity = new EntityClass();
    $entity->mapFromArray($cleaned);

    $validator = new EntityClassValidator($entity);
    $validator->isValid([$field]);

    $response = $this->fetch();
    if ($validator->hasFieldError($field)) {
        $response
            ->setStatus('error')
            ->setField($field, false, $validator->getFieldError($field));
    }
    return $response;
}
```

## entity pattern

```php
class EntityClass
{
    use ArrayMappable;

    public function __construct(array $data = []) { if ($data) $this->mapFromArray($data); }

    // server-controlled: NO setter → hydrated via reflection in FileRepository,
    //                    not settable from body (mass-assignment safe)
    private ?int $id = null;

    #[Clean('text')]
    private string $name = '';

    #[Clean('nullable', 'slug')]
    private ?string $aliasUrl = null;

    #[Clean('list', 'ident')]
    private array $tags = [];

    // public dumb setters for every #[Clean]-annotated property
    public function setName(string $name): void { $this->name = $name; }
    public function setAliasUrl(?string $aliasUrl): void { $this->aliasUrl = $aliasUrl; }
    public function setTags(array $tags): void { $this->tags = $tags; }
}
```

## validator pattern

```php
class EntityClassValidator extends EntityValidator
{
    public function validateName(string $name): void
    {
        $this->validate('name', 'Name', $name)->notEmpty();
    }

    public function validateSlug(string $slug): void
    {
        $this->validate('slug', 'Slug', $slug)->notEmpty()->maxLength(64);
    }
}
```

`validate()` returns `static` for fluent chaining.

`isValid(?array $only = null)` — full validation by default, or restricted to a list of field keys (snake_case, matching `mapToArray()` output). Each call resets state and re-runs; there is no cache. Used by:

| Caller | Aufruf | Effekt |
|---|---|---|
| POST save | `$validator->isValid()` | runs every `validate{Field}()` method |
| Blur check | `$validator->isValid([$field])` | runs only the requested field's validator |
| Partial save | `$validator->isValid(['name', 'email'])` | runs a subset |

Fields listed in `$only` that have no `validate{Field}()` method are silently skipped (per the [`fetch.md`](fetch.md) checkField rule).

Available rules:

| Method | Validates |
|---|---|
| `notEmpty()` | value has at least one character (no trim — `TextFilter` already did) |
| `minLength(int)` | minimum character count |
| `maxLength(int)` | maximum character count |
| `isEmail()` | valid email format |
| `isUrl()` | lowercase a-z, 0-9, `-_/` only (URL slugs and paths) |
| `isAlphaAscii()` | a-z, `_`, `-` only |
| `isAlphaAsciiNum()` | a-z, A-Z, 0-9, `_`, `-` |
| `isAlphaNum()` | letters incl. umlauts, digits, spaces, `.,-` |

Add new rules to `EntityValidator` as needed — one method per rule, guard with `!isset($this->fieldErrors[$field])`.

## CSRF rules

| Case | Behaviour |
|---|---|
| New entity (no ID) | No CSRF check on POST; no token generated for GET |
| Existing entity | CSRF validated on POST; fresh token generated for GET and for re-rendered form after failed validation |

Entity CSRF is separate from the form-level session CSRF. Token type: `DI::getCsrfService()->generateEntityToken($type, $id)`.

## optimistic locking (entity_hash)

Two admins open the same edit form; whoever saves later silently overwrites the other's change. No lock can solve this human race — the conflict is DETECTED at save time and reported to the user (pattern carried over from the wdv framework, decision Peter).

Mechanics (reference implementation: `NavigationController` + its `edit.tpl.php`):

| Step | What happens |
|---|---|
| Render (existing entity) | `EntityStateHash::of($entity)` over the freshly loaded stored state → hidden `entity_hash` field, next to `entity_csrf` |
| POST (existing entity) | `$validator->guardStoredState($body['entity_hash'])` BEFORE `mapFromArray()` — the entity still carries the freshly loaded stored state, so the guard compares form basis vs. current store |
| Mismatch / missing hash | Conflict — `isValid()` returns `false` with the general error «Der Eintrag wurde inzwischen geändert — neu laden und Änderung erneut anbringen.»; the save flows through the normal re-render path, no special channel |
| Re-render after failed POST | The SUBMITTED hash is re-issued unchanged — the form content is still based on that state, so a conflict stays a conflict until the user reloads |
| New entity (no id) | Exempt — no stored state, guard not called, `entity_hash` stays `''` |

- The conflict is validator STATE, not a rule result — it survives the per-call reset in `isValid()` (each call re-adds it to the general error channel).
- `EntityStateHash` hashes `mapToArray()` normalised by recursive key sort — the `ArrayMappable` contract, not file-driver behaviour. Carrier-neutral: a driver with native versioning (e.g. a Doctrine version column) replaces only the hash source; the form/validator flow stays identical.
- Hash comparison is consistent because both sides hash a HYDRATED entity (render and guard) — hydration is deterministic, so no false conflicts.
- The general-error alert region in the edit template renders `$validator->getErrors()` — that is where the conflict message surfaces. On a conflict (`$validator->hasStateConflict()`) the alert additionally renders a «Neu laden» button with `data-fetch-get` back to the edit GET URL — one click re-fetches the fresh form into the popup (core.js re-wires `data-fetch-get` on every `popup.show()`, no extra JS). The reload discards the entered values by design; the user re-applies the change on the fresh state.
- Verified: `tests/optimistic-locking.php` (11 checks over the real Navigation stack — conflict rejected + store keeps the other change, undisturbed save passes, missing hash fails loud, conflict coexists with field errors, new-entity exemption).

## rules

- When defining a new entity property that should be writable from POST body → MUST add a `#[Clean(...)]` attribute and a corresponding dumb public setter
- When a property must never be written from POST body (id, timestamps, computed fields, tree references) → MUST omit its setter entirely; `FileRepository` will hydrate it via reflection on load
- When adding `#[Clean]` → MUST use only registered filter names (`text`, `slug`, `ident`, `email`, `int`, `bool`, `list`, `nullable`); unknown names throw `InvalidArgumentException` at compile time
- When a property is nullable (`?string`, `?int`) and the empty value should map to `null` → MUST wrap with `#[Clean('nullable', '...')]`; plain `#[Clean('text')]` on `?string` will set `""`, not `null`
- When writing a `validate{FieldName}()` method → MUST call `$this->validate($field, $label, $value)` first to initialise the fluent chain before calling rule methods
- When writing a validator rule method → MUST NOT re-trim or re-clean the value; cleaning ran in `BodyCleaner` before hydration
- When a POST request fails validation → MUST fall through to the same form render path (no early `FetchResponse` for validation errors); MUST pass `$validator` to the template
- When rendering a form after a failed POST → MUST regenerate the entity CSRF token (the previous one was consumed on the POST)
- When rendering an edit form for an EXISTING entity → MUST include a hidden `entity_hash` field (`EntityStateHash::of()` over the freshly loaded entity, before any body hydration) next to `entity_csrf`; new entities carry `''`
- When handling the POST of an EXISTING entity → MUST call `$validator->guardStoredState($body['entity_hash'])` BEFORE `mapFromArray()` (the guard needs the stored state, not the hydrated body); MUST NOT call it for new entities
- When re-rendering an edit form after a failed POST → MUST re-issue the SUBMITTED `entity_hash` unchanged; MUST NOT compute a fresh hash — that would let a second save silently overwrite the very change the conflict just reported
- When the re-rendered form reports a state conflict (`$validator->hasStateConflict()`) → MUST render a reload button inside the error alert (`data-fetch-get` targeting the edit GET URL) so the user can re-fetch the fresh form without leaving the modal; MUST NOT add per-form JS for this (core.js wires `data-fetch-get` on every popup render)
- When adding a new entity field that requires validation → MUST add a `validate{FieldName}()` method in the entity's validator
- When constructing a new entity in `addAction()` → MUST use `new EntityClass()` (no data); MUST NOT pre-fill via constructor — hydration happens in `edit()` after POST
- When implementing a `checkFieldAction()` → MUST run `BodyCleaner::cleanFor()` on the submitted value (same pipeline as the save path); MUST call `$validator->isValid([$field])`; MUST return an empty `$this->fetch()` on success
- When emitting any user feedback from a controller action → MUST push via `$this->messageService->pushFlash()` / `pushMessage()` and build the response via `$this->fetch()`; MUST NOT call `new FetchResponse()` directly (the helper drains the buffers — see [`messages.md`](messages.md))
- When rendering a form template after a failed POST → MUST set `aria-invalid="true"` on the input, MUST emit `<small data-z77-field-error>` with `$validator->getFieldError()` inside a `[data-z77-field-wrapper]` element (backend templates apply `.be-form__field` / `.be-form__field-error` classes for styling)

## known issues

- **CONC-EDIT-001** — resolved 2026-08-03. Concurrent edits of the same entity silently lost the earlier save (two admins, same form — last write wins with no warning). Fix: optimistic locking via `entity_hash` + `EntityValidator::guardStoredState()` (see [optimistic locking](#optimistic-locking-entity_hash)); reference implementation in `NavigationController` edit flow. Raised by the AXO3 B1 review; AXO3-side use (version in `EstateStore::updateOwn()`) comes with B10 — the framework mechanism + Navigation reference is complete. Verified: `tests/optimistic-locking.php` (11 checks).
- **VAL-BLOCK-001** — resolved 2026-05-16. Both feedback channels live: server-rerender path (`edit.tpl.php` + `tagEdit.tpl.php` consume `$validator`) and client blur-check path (`NavigationController::checkFieldAction` + `data-check-url` + `_Z77.core.fields`). Reference flow: full POST validation in [`navigation.md`](navigation.md); blur-check in [`fetch.md`](fetch.md). Blueprint unblocked.

## pending

- Convention-based validator resolution: `$em->getValidator($entity)` resolves `{Namespace}\Validators\{EntityName}Validator` — not yet implemented; validators are instantiated explicitly for now
- Validator rule set is minimal (notEmpty, minLength, maxLength, isEmail, isUrl, isAlpha*) — extend as needed per entity
- `BodyCleaner` filter registry is hardcoded in the constructor — if external packages need custom filters, expose `BodyCleaner::registry()->register('name', $factory)` as a documented extension point

## see also

- [`navigation.md`](navigation.md) — prototype controller implementing this pattern (`NavigationController`)
- [`fetch.md`](fetch.md) — `FetchResponse` commands used in POST-success paths
- [`messages.md`](messages.md) — `MessageService` push/consume API; `$this->fetch()` helper rules
- [`persistence-file.md`](persistence-file.md) — `FileRepository` hydrates server-controlled properties via reflection (`id`, tree refs)
