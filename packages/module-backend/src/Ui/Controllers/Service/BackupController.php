<?php
namespace Z77\Module\Backend\Ui\Controllers\Service;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\FileResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Backup\BackupService,
    Z77\Shared\Backup\BackupType
;

/**
 * Backend surface for the installation backups (docs/topics/backup.md): one
 * list screen with the three type sections (data / db / full), a per-type
 * "run now" trigger, and download / delete per archive. All logic lives in
 * {@see BackupService} (kernel, HTTP-free — shared with the CLI entry);
 * this is thin glue. SUPER_USER only — archives contain the user store.
 *
 * URL: /backend/service/backup/{action}. Mutations are Fetch POSTs (globally
 * CSRF-gated); delete additionally carries a per-archive entity token.
 */
class BackupController extends BackendAbstractController
{
    private const CSRF_SCOPE = 'backup';

    private function service(): BackupService
    {
        return BackupService::fromProjectRoot(ABS_BASE_PATH);
    }

    /** Normalizes the `?type=` / body type token; null for anything unknown. */
    private function typeParam(?string $raw): ?BackupType
    {
        return BackupType::fromName($raw);
    }

    protected function listAction(): HtmlResponse
    {
        $service = $this->service();
        $history = $service->history();

        $sections = [];
        foreach (BackupType::cases() as $type) {
            $sections[] = [
                'type'    => $type->value,
                'entries' => $history->scan($type),
            ];
        }

        return $this->html([
            'sections'     => $sections,
            'dbConfigured' => $service->isDatabaseConfigured(),
        ]);
    }

    /** Runs one backup synchronously (a full backup can take a while). */
    #[Fetch, HttpMethod('POST')]
    protected function runAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $type = $this->typeParam($body['type'] ?? null);
        if ($type === null) {
            return $this->fetchError('Unbekannter Backup-Typ');
        }

        $service = $this->service();
        if ($type === BackupType::Db && !$service->isDatabaseConfigured()) {
            return $this->fetchError('Keine Datenbank konfiguriert (config/backup.inc.php)');
        }

        set_time_limit(0);

        try {
            $entry = $service->run($type, 'manual');
        } catch (\RuntimeException $e) {
            return $this->fetchError('Backup fehlgeschlagen: ' . $e->getMessage());
        }

        $this->messageService->pushFlashAfterRedirect(
            'success',
            'Backup «' . $entry->getFileName() . '» erstellt'
        );

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    protected function downloadAction(): FileResponse|FetchResponse
    {
        $request = DI::getRequest();
        $type    = $this->typeParam($request->getGetParameter('type'));
        $file    = (string)$request->getGetParameter('file');

        $path = $type === null ? null : $this->service()->history()->resolvePath($type, $file);
        if ($path === null) {
            return $this->fetchError('Backup nicht gefunden');
        }

        return $this->file($path, basename($path), 'application/zip');
    }

    /** Per-row action hub (the list row's ⋮): download + delete for one archive. */
    protected function actionsAction(): HtmlResponse|FetchResponse
    {
        $request = DI::getRequest();
        $type    = $this->typeParam($request->getGetParameter('type'));
        $file    = (string)$request->getGetParameter('file');

        if ($type === null || $this->service()->history()->resolvePath($type, $file) === null) {
            return $this->fetchError('Backup nicht gefunden');
        }

        $response = $this->html([
            'type'     => $type->value,
            'fileName' => $file,
        ]);
        $this->layoutManager->addPartials('actions', 'Service/BackupController', self::NAMESPACE);
        return $response;
    }

    protected function confirmDeleteAction(): HtmlResponse|FetchResponse
    {
        $request = DI::getRequest();
        $type    = $this->typeParam($request->getGetParameter('type'));
        $file    = (string)$request->getGetParameter('file');

        if ($type === null || $this->service()->history()->resolvePath($type, $file) === null) {
            return $this->fetchError('Backup nicht gefunden');
        }

        $response = $this->html([
            'type'       => $type->value,
            'fileName'   => $file,
            'entityCsrf' => DI::getCsrfService()->generateEntityToken(self::CSRF_SCOPE, $file),
        ]);
        $this->layoutManager->addPartials('confirmDelete', 'Service/BackupController', self::NAMESPACE);
        return $response;
    }

    #[Fetch, HttpMethod('POST')]
    protected function removeAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();
        $type = $this->typeParam($body['type'] ?? null);
        $file = trim((string)($body['file'] ?? ''));
        if ($type === null || $file === '') {
            return $this->fetchError('Backup nicht gefunden');
        }

        $csrf = trim($body['entity_csrf'] ?? '');
        if (!DI::getCsrfService()->validateEntityToken($csrf, self::CSRF_SCOPE, $file)) {
            return $this->fetchError('Invalid token');
        }

        try {
            $this->service()->delete($type, $file);
        } catch (\RuntimeException $e) {
            return $this->fetchError('Löschen fehlgeschlagen: ' . $e->getMessage());
        }

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }
}
