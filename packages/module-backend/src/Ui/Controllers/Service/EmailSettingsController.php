<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\DI,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\EmailDocumentSetting,
    Z77\Shared\Entities\EmailFormSetting,
    Z77\Shared\Mail\DocumentMailKinds,
    Z77\Shared\Repositories\EmailDocumentSettingRepository,
    Z77\Shared\Repositories\EmailFormSettingRepository,
    Z77\Shared\Validators\EmailDocumentSettingValidator,
    Z77\Shared\Validators\EmailFormSettingValidator
;

/**
 * Backend editor for form-mail settings (EmailService v2 —
 * docs/03-development/email-settings-v2-bauplan.md): recipients, CC, subject
 * and the routeKey→recipient routing per form key, operator-editable without a
 * deploy. The form keys + body templates stay code (emailConfig, seed/fallback);
 * a saved {@see EmailFormSetting} record overrides to/cc/subject/routes
 * completely, «Zurücksetzen» deletes the record → config applies again.
 *
 * Second section «Beleg-Mails» (step 3 of the mail plan, owner 2026-10-22):
 * subject and text per language of each document mail kind the modules
 * declare (`documentMails` — invoice, credit note, …); the module's text is
 * the seed, a saved {@see EmailDocumentSetting} overrides it while active.
 * Actions `document-*`, the same two-layer model as the form mails.
 *
 * Role: ADMIN (module default — operator care, deliberately NOT the
 * SUPER_USER of the backup surface; owner decision E1, 2026-07-18).
 *
 * URL: /backend/service/email-settings/{action}.
 */
class EmailSettingsController extends BackendAbstractController
{
    /** Empty route rows offered in the edit form (add more by saving + reopening). */
    private const EMPTY_ROUTE_ROWS = 2;

    private function repo(): EmailFormSettingRepository
    {
        return $this->em()->getRepository(EmailFormSetting::class);
    }

    /**
     * Union of config form keys and entity records, with the EFFECTIVE values +
     * origin per key. The effective values follow resolution: an override
     * applies only when the record is active — a dormant record (active=false)
     * shows the config seed values (origin «Backend (inaktiv)»). Entity-only
     * keys (config entry removed after an override was saved) are listed too —
     * visible leftovers instead of silent orphans.
     *
     * @return list<array{key: string, to: list<string>, subject: string,
     *                    routes: int, origin: string, hasEntity: bool,
     *                    hasConfig: bool, active: bool}>
     */
    private function rows(): array
    {
        $forms = (array) DI::getConfigManager()
            ->getArrayConfig('Config/emailConfig', 'Z77\\Shared')
            ->get('forms', []);

        $entities = [];
        foreach ($this->repo()->findAll() as $entity) {
            $entities[$entity->getFormKey()] = $entity;
        }

        $rows = [];
        foreach (array_unique([...array_keys($forms), ...array_keys($entities)]) as $key) {
            $entity = $entities[$key] ?? null;
            $config = $forms[$key] ?? null;
            $active = $entity !== null && $entity->isActive();

            if ($active) {
                $to      = $entity->getTo();
                $subject = $entity->getSubject();
                $routes  = count($entity->getRoutes());
                $origin  = 'Backend';
            } else {
                // No entity OR dormant override → config seed is effective.
                $to      = $this->splitList((string) ($config['to'] ?? ''));
                $subject = (string) ($config['subject'] ?? '');
                $routes  = count((array) ($config['routes'] ?? []));
                $origin  = $entity !== null ? 'Backend (inaktiv)' : 'Config';
            }

            $rows[] = [
                'key'       => (string) $key,
                'to'        => $to,
                'subject'   => $subject,
                'routes'    => $routes,
                'origin'    => $origin,
                'hasEntity' => $entity !== null,
                'hasConfig' => is_array($config),
                'active'    => $active,
            ];
        }
        usort($rows, static fn (array $a, array $b) => $a['key'] <=> $b['key']);
        return $rows;
    }

    /**
     * The in-place answer for one form key (ADR-047 addendum 2026-10-10): its row re-rendered by
     * the list's own partial (`replaceRow`), or removed when the key is no longer listed. core.js
     * wires the new row's switch, ⋮ and «Übersteuern» (FETCH-ROW-001).
     */
    private function rowAnswer(FetchResponse $response, string $key): FetchResponse
    {
        foreach ($this->rows() as $row) {
            if ($row['key'] === $key) {
                return $response->replaceRow('email-setting', $key, (new TemplateRenderer(self::NAMESPACE))
                    ->partial('Service/EmailSettingsController/_row', ['row' => $row]));
            }
        }
        return $response->removeRow('email-setting', $key);
    }

    protected function listAction(): HtmlResponse
    {
        return $this->html(['rows' => $this->rows(), 'documentRows' => $this->documentRows()]);
    }

    // ── document mails («Beleg-Mails», step 3 of the mail plan, owner 2026-10-22) ──
    //
    // The sibling section of the same screen: one row per document mail kind a module declares
    // (`documentMails`, DocumentMailKinds); subject and text per language. The module's
    // declaration is the seed, a saved EmailDocumentSetting overrides it completely while
    // active — the form mails' two-layer model, so the screen reads the same way. The sender is
    // the installation's identity (config/client/mail.inc.php), never edited here.

    private function documentRepo(): EmailDocumentSettingRepository
    {
        return $this->em()->getRepository(EmailDocumentSetting::class);
    }

    private function documentKinds(): DocumentMailKinds
    {
        return DocumentMailKinds::fromModules(DI::getModuleManager());
    }

    /**
     * One row per declared kind (+ a leftover record whose kind no module declares any more),
     * with the EFFECTIVE German subject and the origin.
     *
     * @return list<array{kind: string, label: string, subject: string, languages: list<string>,
     *                    origin: string, hasEntity: bool, declared: bool, active: bool}>
     */
    private function documentRows(): array
    {
        $kinds    = $this->documentKinds();
        $entities = [];
        foreach ($this->documentRepo()->findAll() as $entity) {
            $entities[$entity->getKind()] = $entity;
        }

        $rows = [];
        foreach (array_unique([...array_keys($kinds->all()), ...array_keys($entities)]) as $kind) {
            $entity   = $entities[$kind] ?? null;
            $declared = $kinds->has($kind);
            $active   = $entity !== null && $entity->isActive();
            $subjects = $active ? $entity->getSubjects() : ($declared ? $kinds->get($kind)['subject'] : []);
            $rows[] = [
                'kind'      => (string) $kind,
                'label'     => $declared ? $kinds->get($kind)['label'] : (string) $kind,
                'subject'   => (string) ($subjects[DocumentMailKinds::FALLBACK_LANGUAGE] ?? ''),
                'languages' => $declared ? $kinds->languages($kind) : [],
                'origin'    => $active ? 'Backend' : ($entity !== null ? 'Backend (inaktiv)' : 'Modul'),
                'hasEntity' => $entity !== null,
                'declared'  => $declared,
                'active'    => $active,
            ];
        }

        return $rows;
    }

    /** The in-place answer for one kind: its row re-rendered (`replaceRow`), or removed when it is listed no more. */
    private function documentRowAnswer(FetchResponse $response, string $kind): FetchResponse
    {
        foreach ($this->documentRows() as $row) {
            if ($row['kind'] === $kind) {
                return $response->replaceRow('email-document', $kind, (new TemplateRenderer(self::NAMESPACE))
                    ->partial('Service/EmailSettingsController/_documentRow', ['row' => $row]));
            }
        }
        return $response->removeRow('email-document', $kind);
    }

    /** The row's ⋮ context menu: «Bearbeiten» (a declared kind), «Zurücksetzen» (a record exists). */
    protected function documentActionsAction(): HtmlResponse|FetchResponse
    {
        $kind     = trim((string) DI::getRequest()->getGetParameter('kind'));
        $declared = $kind !== '' && $this->documentKinds()->has($kind);
        $entity   = $kind !== '' ? $this->documentRepo()->findByKind($kind) : null;
        if (!$declared && $entity === null) {
            return $this->fetchError('Beleg-Art nicht gefunden');
        }

        $response = $this->html(['kind' => $kind, 'declared' => $declared, 'hasEntity' => $entity !== null]);
        $this->layoutManager->addPartials('documentActions', 'Service/EmailSettingsController', self::NAMESPACE);
        return $response;
    }

    /** The list switch of a kind's record — on: the record applies, off: the module's text (kept, dormant). */
    #[Fetch, HttpMethod('POST')]
    protected function documentToggleActiveAction(): FetchResponse
    {
        $kind   = trim((string) DI::getRequest()->getGetParameter('kind'));
        $entity = $kind !== '' ? $this->documentRepo()->findByKind($kind) : null;
        if ($entity === null) {
            return $this->fetchError('Keine Backend-Einstellung vorhanden');
        }

        $body = DI::getRequest()->getJsonBody();
        $entity->setActive((bool) ($body['value'] ?? !$entity->isActive()));
        $this->em()->persist($entity);
        $this->em()->flush();

        return $this->documentRowAnswer($this->fetch()->setStatus('success'), $kind);
    }

    /**
     * The editor of a kind: subject and text per declared language, the placeholders listed.
     * A first save creates the record (prefilled from the module's declaration), every save
     * answers in place (the row) and closes the dialog.
     */
    protected function documentEditAction(): HtmlResponse|FetchResponse
    {
        $kind = trim((string) DI::getRequest()->getGetParameter('kind'));
        if ($kind === '') {
            $kind = trim((string) (DI::getRequest()->getJsonBody()['kind'] ?? ''));
        }
        $kinds = $this->documentKinds();
        if ($kind === '' || !$kinds->has($kind)) {
            return $this->fetchError('Beleg-Art nicht gefunden');
        }
        $declaration = $kinds->get($kind);
        $languages   = $kinds->languages($kind);

        $entity = $this->documentRepo()->findByKind($kind);
        $isNew  = $entity === null;
        if ($isNew) {
            $entity = new EmailDocumentSetting();
            $entity->setKind($kind);
            $entity->setSubjects($declaration['subject']);
            $entity->setBodies($declaration['body']);
        }

        $validator = null;
        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'emailDocumentSetting', $kind)) {
                return $this->fetchError('Invalid token');
            }
            $subjects = [];
            $bodies   = [];
            foreach ($languages as $language) {
                $subjects[$language] = trim((string) ($body['subjects'][$language] ?? ''));
                $bodies[$language]   = trim(str_replace("\r\n", "\n", (string) ($body['bodies'][$language] ?? '')));
            }
            $entity->setSubjects($subjects);
            $entity->setBodies($bodies);

            $validator = new EmailDocumentSettingValidator($entity);
            if ($validator->isValid()) {
                $this->em()->persist($entity);
                $this->em()->flush();
                $this->messageService->pushFlash('success', "Beleg-Mail «{$declaration['label']}» gespeichert");
                return $this->documentRowAnswer($this->fetch()->setStatus('success'), $kind)->addCommand('close-modal');
            }
        }

        $response = $this->html([
            'entry'        => $entity,
            'label'        => $declaration['label'],
            'languages'    => $languages,
            'placeholders' => $declaration['placeholders'],
            'entityCsrf'   => DI::getCsrfService()->generateEntityToken('emailDocumentSetting', $kind),
            'isOverride'   => !$isNew,
            'isDormant'    => !$isNew && !$entity->isActive(),
            'validator'    => $validator ?? new EmailDocumentSettingValidator($entity),
        ]);
        $this->layoutManager->addPartials('documentEdit', 'Service/EmailSettingsController', self::NAMESPACE);
        return $response;
    }

    protected function confirmDocumentResetAction(): HtmlResponse|FetchResponse
    {
        $kind   = trim((string) DI::getRequest()->getGetParameter('kind'));
        $entity = $kind !== '' ? $this->documentRepo()->findByKind($kind) : null;
        if ($entity === null) {
            return $this->fetchError('Keine Backend-Einstellung vorhanden');
        }

        $kinds    = $this->documentKinds();
        $response = $this->html([
            'kind'       => $kind,
            'label'      => $kinds->has($kind) ? $kinds->get($kind)['label'] : $kind,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('emailDocumentSetting', $kind),
        ]);
        $this->layoutManager->addPartials('confirmDocumentReset', 'Service/EmailSettingsController', self::NAMESPACE);
        return $response;
    }

    /** Deletes the kind's record — the module's declaration applies again. */
    #[Fetch, HttpMethod('POST')]
    protected function documentResetAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $kind = trim((string) ($body['kind'] ?? ''));
        if ($kind === '') {
            return $this->fetchError('Beleg-Art fehlt');
        }
        if (!DI::getCsrfService()->validateEntityToken(trim((string) ($body['entity_csrf'] ?? '')), 'emailDocumentSetting', $kind)) {
            return $this->fetchError('Invalid token');
        }
        $entity = $this->documentRepo()->findByKind($kind);
        if ($entity === null) {
            return $this->fetchError('Keine Backend-Einstellung vorhanden');
        }

        $this->em()->remove($entity);
        $this->em()->flush();

        $this->messageService->pushFlash('success', "Beleg-Mail «{$kind}» auf die Vorgabe des Moduls zurückgesetzt");
        return $this->documentRowAnswer($this->fetch()->setStatus('success'), $kind)->addCommand('close-modal');
    }

    /**
     * Per-row action hub (⋮): edit + reset. Mirrors the backend-user / navigation
     * hubs. Reset only offered when a backend record exists.
     */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $key = trim((string) DI::getRequest()->getGetParameter('key'));
        if ($key === '') {
            return $this->fetchError('Formular-Key fehlt');
        }

        $entity    = $this->repo()->findByFormKey($key);
        $hasConfig = is_array(DI::getConfigManager()
            ->getArrayConfig('Config/emailConfig', 'Z77\\Shared')
            ->get(['forms', $key], null));

        if (!$hasConfig && $entity === null) {
            return $this->fetchError('Formular-Key nicht gefunden');
        }

        $response = $this->html([
            'formKey'   => $key,
            'hasConfig' => $hasConfig,
            'hasEntity' => $entity !== null,
        ]);
        $this->layoutManager->addPartials('actions', 'Service/EmailSettingsController', self::NAMESPACE);
        return $response;
    }

    /**
     * Inline active toggle from the list — enables/disables the override without
     * deleting it. Non-destructive → global CSRF only (no entity token, like the
     * navigation toggle). The effective recipients and the origin badge change: the row is
     * replaced in place (it was a `reload` until 2026-10-10).
     */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $key    = trim((string) DI::getRequest()->getGetParameter('key'));
        $entity = $key !== '' ? $this->repo()->findByFormKey($key) : null;
        if ($entity === null) {
            return $this->fetchError('Keine Backend-Einstellung vorhanden');
        }

        $body = DI::getRequest()->getJsonBody();
        $entity->setActive((bool) ($body['value'] ?? !$entity->isActive()));
        $this->em()->persist($entity);
        $this->em()->flush();

        return $this->rowAnswer($this->fetch()->setStatus('success'), $key);
    }

    protected function confirmResetAction(): HtmlResponse|FetchResponse
    {
        $key    = trim((string) DI::getRequest()->getGetParameter('key'));
        $entity = $key !== '' ? $this->repo()->findByFormKey($key) : null;
        if ($entity === null) {
            return $this->fetchError('Keine Backend-Einstellung vorhanden');
        }

        $response = $this->html([
            'formKey'    => $key,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('emailFormSetting', $key),
        ]);
        $this->layoutManager->addPartials('confirmReset', 'Service/EmailSettingsController', self::NAMESPACE);
        return $response;
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $key = trim((string) DI::getRequest()->getGetParameter('key'));
        if ($key === '') {
            $key = trim((string) (DI::getRequest()->getJsonBody()['form_key'] ?? ''));
        }
        if ($key === '') {
            return $this->fetchError('Formular-Key fehlt');
        }

        $entity = $this->repo()->findByFormKey($key);
        $isNew  = $entity === null;
        if ($isNew) {
            $entity = $this->fromConfig($key);
            if ($entity === null) {
                return $this->fetchError('Formular-Key nicht gefunden');
            }
        }

        $validator = null;

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            $csrf = trim($body['entity_csrf'] ?? '');
            if (!DI::getCsrfService()->validateEntityToken($csrf, 'emailFormSetting', $key)) {
                return $this->fetchError('Invalid token');
            }

            $entity->setTo($this->splitList((string) ($body['to'] ?? '')));
            $entity->setCc($this->splitList((string) ($body['cc'] ?? '')));
            $entity->setSubject(trim((string) ($body['subject'] ?? '')));
            $entity->setRoutes($this->routesFromBody($body));

            $validator = new EmailFormSettingValidator($entity);
            if ($validator->isValid()) {
                $this->em()->persist($entity);
                $this->em()->flush();

                // In place (ADR-047 addendum 2026-10-10) — also the first save, which gives the
                // row its on/off switch in place of «Übersteuern».
                $this->messageService->pushFlash('success', "E-Mail-Einstellungen «{$key}» gespeichert");
                return $this->rowAnswer($this->fetch()->setStatus('success'), $key)->addCommand('close-modal');
            }
            // validation failed — fall through to re-render the form with errors
        }

        $response = $this->html([
            'entry'          => $entity,
            'entityCsrf'     => DI::getCsrfService()->generateEntityToken('emailFormSetting', $key),
            'isOverride'     => !$isNew,
            'isDormant'      => !$isNew && !$entity->isActive(),
            'validator'      => $validator ?? new EmailFormSettingValidator($entity),
            'emptyRouteRows' => self::EMPTY_ROUTE_ROWS,
        ]);
        $this->layoutManager->addPartials('edit', 'Service/EmailSettingsController', self::NAMESPACE);
        return $response;
    }

    /** Deletes the override record — the config seed applies again. */
    #[Fetch, HttpMethod('POST')]
    protected function resetAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $key  = trim((string) ($body['form_key'] ?? ''));
        if ($key === '') {
            return $this->fetchError('Formular-Key fehlt');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, 'emailFormSetting', $key)) {
            return $this->fetchError('Invalid token');
        }

        $entity = $this->repo()->findByFormKey($key);
        if ($entity === null) {
            return $this->fetchError('Keine Backend-Einstellung vorhanden');
        }

        $this->em()->remove($entity);
        $this->em()->flush();

        // In place: the row shows the config values and «Übersteuern» again — or goes, when the
        // key exists no longer in the config (an entity-only leftover).
        $this->messageService->pushFlash('success', "E-Mail-Einstellungen «{$key}» auf die Config zurückgesetzt");
        return $this->rowAnswer($this->fetch()->setStatus('success'), $key)->addCommand('close-modal');
    }

    /**
     * Pre-fills a fresh (unsaved) entity from the config seed so the edit form
     * opens with the effective values. Null when the key is not declared.
     */
    private function fromConfig(string $key): ?EmailFormSetting
    {
        $form = DI::getConfigManager()
            ->getArrayConfig('Config/emailConfig', 'Z77\\Shared')
            ->get(['forms', $key], null);

        if (!is_array($form)) {
            return null;
        }

        $entity = new EmailFormSetting();
        $entity->setFormKey($key);
        $entity->setTo($this->splitList((string) ($form['to'] ?? '')));
        $entity->setCc($this->splitList((string) ($form['cc'] ?? '')));
        $entity->setSubject((string) ($form['subject'] ?? ''));

        $routes = [];
        foreach ((array) ($form['routes'] ?? []) as $routeKey => $route) {
            $to = is_array($route['to'] ?? null)
                ? array_values(array_filter(array_map('trim', $route['to'])))
                : $this->splitList((string) ($route['to'] ?? ''));
            if ($to === []) {
                continue;
            }
            $entry = ['to' => $to];
            if (!empty($route['subject'])) {
                $entry['subject'] = (string) $route['subject'];
            }
            $routes[(string) $routeKey] = $entry;
        }
        $entity->setRoutes($routes);

        return $entity;
    }

    /**
     * Parses the parallel route_key[i]/route_to[i]/route_subject[i] maps from
     * the edit form (core.js supports one bracket level) — rows with an empty
     * key are dropped (the blank "add" rows).
     *
     * @return array<string, array{to: list<string>, subject?: string}>
     */
    private function routesFromBody(array $body): array
    {
        $keys     = (array) ($body['route_key'] ?? []);
        $tos      = (array) ($body['route_to'] ?? []);
        $subjects = (array) ($body['route_subject'] ?? []);

        $routes = [];
        foreach ($keys as $i => $routeKey) {
            $routeKey = trim((string) $routeKey);
            if ($routeKey === '') {
                continue;
            }
            $entry = ['to' => $this->splitList((string) ($tos[$i] ?? ''))];
            $subject = trim((string) ($subjects[$i] ?? ''));
            if ($subject !== '') {
                $entry['subject'] = $subject;
            }
            $routes[$routeKey] = $entry;
        }
        return $routes;
    }

    /** Splits a newline/','/';'-separated address block into a trimmed list. */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,;]+/', $value) ?: []
        ), static fn (string $v) => $v !== ''));
    }
}
