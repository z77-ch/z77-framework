<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Entities\EmailFormSetting,
    Z77\Shared\Repositories\EmailFormSettingRepository,
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

    protected function listAction(): HtmlResponse
    {
        return $this->html(['rows' => $this->rows()]);
    }

    /**
     * Per-row action hub (⋮): edit + reset. Mirrors the login-user / navigation
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
     * navigation toggle). The effective recipients change, so the list reloads.
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

        return $this->fetch()->setStatus('success')->addCommand('reload');
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

                $this->messageService->pushFlashAfterRedirect('success', "E-Mail-Einstellungen «{$key}» gespeichert");
                return $this->fetch()
                    ->setStatus('success')
                    ->addCommand('close-modal')
                    ->addCommand('reload');
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

        $this->messageService->pushFlashAfterRedirect('success', "E-Mail-Einstellungen «{$key}» auf die Config zurückgesetzt");
        return $this->fetch()
            ->setStatus('success')
            ->addCommand('close-modal')
            ->addCommand('reload');
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
