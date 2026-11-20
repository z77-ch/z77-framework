<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Core\DI;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\MandatorService;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The WRITE side of the logo set-up window (owner decisions 2026-11-20): save uploaded logos,
 * remove one, «Später / kein Logo». Called by `MandatorControllerTrait::logoAction()` after the
 * screen's gate (ADMIN, CSRF); everything the request carried is judged again here.
 *
 * **Save** ({@see save()}): per upload the bytes are decoded by content ({@see LogoImage::decode()} —
 * PNG / JPEG / GIF only, size limits) and each use's variant is DERIVED and re-encoded by gd; the
 * variants are stored in the DMS ({@see LogoDrive}); then the mandator names the new documents in ONE
 * write (`MandatorService::updateLogos()`, which also clears «Später / kein Logo»); only AFTER that
 * commit the documents they replace go to the Drive's trash (a file write does not roll back —
 * documents.md, `ModuleDrive::trash()`). A refused upload stores nothing at all: every file is
 * decoded and every variant derived before the first document is written.
 *
 * **The website variant** changes a page every visitor sees: after the mandator's write the page
 * cache is cleared (`PageCache::clearAll()`, the `TranslationCatalog` precedent) — the mandator is
 * Doctrine, its write does not invalidate pages by itself (cache.md: `invalidatesCache` is the
 * File store's).
 */
final class LogoService
{
    public function __construct(
        private readonly UnifiedEntityManager $em,
        private readonly LogoDrive $drive = new LogoDrive(),
    ) {
    }

    /**
     * @param array<string, string> $uploads use => uploaded bytes; the uses the window offers
     *                                       (`LogoUses::served()`), one upload may be given for several
     * @return array<string, string> use => German refusal; [] = saved
     */
    public function save(Mandator $mandator, array $uploads): array
    {
        $errors   = [];
        $variants = [];
        $decoded  = [];
        foreach ($uploads as $use => $bytes) {
            $key   = hash('sha256', $bytes);
            $image = $decoded[$key] ??= LogoImage::decode($bytes);
            if (is_string($image)) {
                $errors[$use] = $image;
                continue;
            }
            try {
                $variants[$use] = ['bytes' => $image->variant($use), 'ext' => $image->ext(), 'mime' => $image->mime()];
            } catch (\RuntimeException $e) {
                error_log('mandator logo: ' . $e->getMessage());
                $errors[$use] = 'Aus diesem Bild lässt sich kein Logo ableiten — bitte eine einfachere PNG- oder JPG-Datei wählen.';
            }
        }
        if ($errors !== [] || $variants === []) {
            return $errors;
        }

        $new = [];
        foreach ($variants as $use => $variant) {
            $new[$use] = $this->drive->store($use, $variant['bytes'], $variant['ext'], $variant['mime'], $mandator->getName());
        }
        $old = [];
        foreach (array_keys($new) as $use) {
            $old[$use] = $mandator->logoDocument($use);
        }
        (new MandatorService($this->em))->updateLogos($mandator, $new, stepDismissed: false);

        $this->trashAll($old);
        if (isset($new[LogoUses::WEB])) {
            self::clearPages();
        }

        return [];
    }

    /** No logo for `$use` from the window any more (the login then falls back to the e-mail logo, LogoFiles). */
    public function remove(Mandator $mandator, string $use): void
    {
        $old = $mandator->logoDocument($use);
        if ($old === null) {
            return;
        }
        (new MandatorService($this->em))->updateLogos($mandator, [$use => null]);
        $this->trashAll([$use => $old]);
        if ($use === LogoUses::WEB) {
            self::clearPages();
        }
    }

    /** «Später / kein Logo»: the setup step stays silent until a logo is saved through the window. */
    public function dismiss(Mandator $mandator): void
    {
        (new MandatorService($this->em))->updateLogos($mandator, [], stepDismissed: true);
    }

    /** @param array<string, int|null> $documents use => the document that is no longer named */
    private function trashAll(array $documents): void
    {
        foreach ($documents as $use => $documentId) {
            if ($documentId === null) {
                continue;
            }
            try {
                if (!$this->drive->trash($use, $documentId)) {
                    error_log("mandator logo: old document {$documentId} ({$use}) not moved to the trash — outside the partition or unknown");
                }
            } catch (\Throwable $e) {
                error_log("mandator logo: old document {$documentId} ({$use}) not moved to the trash: " . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    private static function clearPages(): void
    {
        try {
            DI::getCacheManager()->page()->clearAll();
        } catch (\Throwable $e) {
            error_log('mandator logo: page cache not cleared: ' . $e->getMessage());
        }
    }
}
