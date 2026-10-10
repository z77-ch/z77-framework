<?php
namespace Z77\Module\Backend\Ui\Controllers\Content;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Services\TranslationCatalog,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * Backend editor for the i18n catalog (translation.md): the two runtime families
 * under `data/framework/i18n/` — UI strings (`{lang}.json`) and route slugs
 * (`route-slugs.{lang}.json`). One list screen with two tables; a `?kind=ui|slug`
 * discriminator drives the shared add/edit/delete modal flow. All persistence +
 * validation lives in {@see TranslationCatalog}; this is thin glue.
 *
 * URL: /backend/content/translation/{action}. Mutations are Fetch POSTs, so they
 * are already CSRF-gated globally (AccessGuard verifies the X-CSRF-Token header);
 * edit/delete additionally carry a per-entry token (scope = kind, id = the key).
 */
class TranslationController extends BackendAbstractController
{
    private function catalog(): TranslationCatalog
    {
        return DI::getInstance()->get('TranslationCatalog');
    }

    /** Normalizes the `?kind=` query param to the two supported families. */
    private function kindParam(): string
    {
        return DI::getRequest()->getGetParameter('kind') === 'slug' ? 'slug' : 'ui';
    }

    private function csrfScope(string $kind): string
    {
        return $kind === 'slug' ? 'translationSlug' : 'translationUi';
    }

    /**
     * Per-language values from the submitted body (`value[<lang>]`).
     *
     * @return array<string, string> language → value
     */
    private function readValues(array $body): array
    {
        $raw = is_array($body['value'] ?? null) ? $body['value'] : [];
        $values = [];
        foreach ($raw as $lang => $value) {
            if (is_string($lang) && is_string($value)) {
                $values[$lang] = $value;
            }
        }
        return $values;
    }

    protected function listAction(): HtmlResponse
    {
        $uiLanguages   = $this->catalog()->uiLanguages();
        $slugLanguages = $this->catalog()->slugLanguages();

        $response = $this->html([
            'uiRows'        => array_map(
                fn(array $row) => $row + ['summary' => $this->valueSummary('ui', $row['values'], $uiLanguages)],
                $this->catalog()->uiMatrix()
            ),
            'slugLanguages' => $slugLanguages,
            'slugRows'      => array_map(
                fn(array $row) => $row + ['summary' => $this->valueSummary('slug', $row['values'], $slugLanguages)],
                $this->catalog()->slugMatrix()
            ),
            'defaultLang'   => DI::getI18n()->getDefaultLanguage(),
        ]);
        return $response;
    }

    /**
     * Compact per-language value summary of one catalog row (pre-escaped HTML); an empty
     * value shows a muted «fehlt» / «nicht lokalisiert». SINGLE source for the list cell
     * and the in-place answer of a save (`update-fields`) — the two must show the same.
     *
     * @param array<string, string> $values     language → value
     * @param list<string>          $languages  the columns of this kind
     */
    private function valueSummary(string $kind, array $values, array $languages): string
    {
        $missing = $kind === 'slug' ? 'nicht lokalisiert' : 'fehlt';
        $parts   = [];
        foreach ($languages as $lang) {
            $value = (string)($values[$lang] ?? '');
            $shown = $value === ''
                ? '<span style="color:var(--be-muted,#94a3b8)">' . e($missing) . '</span>'
                : e($value);
            $parts[] = '<strong style="font-weight:600">' . e($lang) . ':</strong> ' . $shown;
        }
        return implode(' &nbsp;·&nbsp; ', $parts);
    }

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->edit($this->kindParam(), null);
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $key = (string)DI::getRequest()->getGetParameter('key');
        if ($key === '') {
            return $this->fetchError('Kein Eintrag angegeben');
        }
        return $this->edit($this->kindParam(), $key);
    }

    /**
     * Shared add/edit modal. On POST it persists through the catalog; catalog
     * validation errors re-render the modal with the entered values, a hard error
     * (bad token) returns a fetch error. GET (and a failed POST) render the form.
     */
    private function edit(string $kind, ?string $originalKey): HtmlResponse|FetchResponse
    {
        $isNew  = $originalKey === null;
        $errors = [];

        if ($isNew) {
            $formKey    = '';
            $formValues = [];
        } else {
            $formKey    = $originalKey;
            $formValues = $kind === 'slug'
                ? $this->catalog()->slugEntry($originalKey)
                : $this->catalog()->uiEntry($originalKey);
        }

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim($body['entity_csrf'] ?? '');
                if (!DI::getCsrfService()->validateEntityToken($csrf, $this->csrfScope($kind), $originalKey)) {
                    return $this->fetchError('Invalid token');
                }
            }

            $formValues = $this->readValues($body);
            $formKey    = trim((string)($body[$kind === 'slug' ? 'canonical' : 'key'] ?? ''));

            $errors = $kind === 'slug'
                ? $this->catalog()->saveSlugEntry($formKey, $formValues, $isNew ? null : $originalKey)
                : $this->catalog()->saveUiEntry($formKey, $formValues, $isNew ? null : $originalKey);

            if ($errors === []) {
                $flash = ($kind === 'slug' ? 'Slug «' : 'Schlüssel «') . $formKey
                       . ($isNew ? '» angelegt' : '» gespeichert');

                if ($isNew || $formKey !== $originalKey) {
                    // Still `reload`: a new or renamed key takes a new place in the
                    // key-sorted list (and a rename changes the key its ⋮ link carries) —
                    // the position depends on the whole list. See docs/topics/content.md
                    // CONTENT-ACTIONS-002.
                    $this->messageService->pushFlashAfterRedirect('success', $flash);
                    return $this->fetch()
                        ->setStatus('success')
                        ->addCommand('close-modal')
                        ->addCommand('reload');
                }

                // Same key, new values: one row changes (ADR-047 addendum 2026-10-10).
                $this->messageService->pushFlash('success', $flash);
                $entity    = $this->csrfScope($kind);
                $languages = $kind === 'slug' ? $this->catalog()->slugLanguages() : $this->catalog()->uiLanguages();
                $stored    = $kind === 'slug' ? $this->catalog()->slugEntry($formKey) : $this->catalog()->uiEntry($formKey);
                $response  = $this->fetch()->setStatus('success');

                // A slug row exists only while some language localizes it (slugMatrix());
                // emptied everywhere, it leaves the list.
                if ($kind === 'slug' && array_filter($stored, fn(string $v) => $v !== '') === []) {
                    return $response->removeRow($entity, $formKey)->addCommand('close-modal');
                }

                return $response
                    ->setData(['summary' => $this->valueSummary($kind, $stored, $languages)])
                    ->addCommand('update-fields', [
                        'target' => FetchResponse::rowTarget($entity, $formKey),
                        'fields' => ['summary' => 'html'],
                    ])
                    ->addCommand('close-modal');
            }
        }

        $entityCsrf = !$isNew
            ? DI::getCsrfService()->generateEntityToken($this->csrfScope($kind), $originalKey)
            : '';

        $response = $this->html([
            'kind'        => $kind,
            'isNew'       => $isNew,
            'formKey'     => $formKey,
            'formValues'  => $formValues,
            'languages'   => $kind === 'slug' ? $this->catalog()->slugLanguages() : $this->catalog()->uiLanguages(),
            'defaultLang' => DI::getI18n()->getDefaultLanguage(),
            'errors'      => $errors,
            'entityCsrf'  => $entityCsrf,
        ]);
        $this->layoutManager->addPartials('edit', 'Content/TranslationController', self::NAMESPACE);
        return $response;
    }

    /** Per-row action hub (the list row's ⋮): edit + delete for one catalog entry (kind + key). */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $kind = $this->kindParam();
        $key  = (string)DI::getRequest()->getGetParameter('key');
        if ($key === '') {
            return $this->fetchError('Kein Eintrag angegeben');
        }

        $response = $this->html([
            'kind'     => $kind,
            'entryKey' => $key,
        ]);
        $this->layoutManager->addPartials('actions', 'Content/TranslationController', self::NAMESPACE);
        return $response;
    }

    protected function confirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $kind = $this->kindParam();
        $key  = (string)DI::getRequest()->getGetParameter('key');
        if ($key === '') {
            return $this->fetchError('Kein Eintrag angegeben');
        }

        $response = $this->html([
            'kind'       => $kind,
            'entryKey'   => $key,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken($this->csrfScope($kind), $key),
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'Content/TranslationController', self::NAMESPACE);
        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $kind = ($body['kind'] ?? '') === 'slug' ? 'slug' : 'ui';
        $key  = trim((string)($body['key'] ?? ''));
        if ($key === '') {
            return $this->fetchError('Missing key');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, $this->csrfScope($kind), $key)) {
            return $this->fetchError('Invalid token');
        }

        if ($kind === 'slug') {
            $this->catalog()->deleteSlugEntry($key);
        } else {
            $this->catalog()->deleteUiEntry($key);
        }

        $this->messageService->pushFlash('success', ($kind === 'slug' ? 'Slug «' : 'Schlüssel «') . $key . '» gelöscht');
        return $this->fetch()
            ->setStatus('success')
            ->removeRow($this->csrfScope($kind), $key)
            ->addCommand('close-modal');
    }
}
