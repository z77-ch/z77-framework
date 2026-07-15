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
        $response = $this->html([
            'uiLanguages'   => $this->catalog()->uiLanguages(),
            'uiRows'        => $this->catalog()->uiMatrix(),
            'slugLanguages' => $this->catalog()->slugLanguages(),
            'slugRows'      => $this->catalog()->slugMatrix(),
            'defaultLang'   => DI::getI18n()->getDefaultLanguage(),
        ]);
        return $response;
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
                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    ($kind === 'slug' ? 'Slug «' : 'Schlüssel «') . $formKey
                        . ($isNew ? '» angelegt' : '» gespeichert')
                );
                return $this->fetch()
                    ->setStatus('success')
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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

        return $this->fetch()
            ->setStatus('success')
            ->addCommand('reload');
    }
}
