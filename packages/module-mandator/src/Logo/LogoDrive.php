<?php

namespace Z77\Module\Mandator\Logo;

use Z77\Core\DI;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Services\Authz;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Module\Dms\Services\ModuleDrive;
use Z77\Module\Dms\Services\SaveRequest;
use Z77\Module\Dms\Ui\MediaField;

/**
 * The ONE class of module-mandator that names module-dms (2026-11-20, the logo set-up): where the
 * derived logo variants are stored and read. module-dms is only SUGGESTED — without it
 * {@see available()} is false, the window says so, and there is no company logo; no other
 * class of this module may `use` a `Z77\Module\Dms\…` class (the classes here are loaded only after
 * `available()`).
 *
 * Two partitions, both through the module's own handle (`DocumentService::forModule()` →
 * `ModuleDrive`, ADR-021 rule 4):
 *
 *   - {@see KEY} `mandator`, «Firmenlogo» — print, e-mail and login, PROTECTED (the default delivery);
 *     role `admin` reads it (`dmsPartitionGrants` in the module config) so the window can preview it
 *     through the DMS's gated preview action;
 *   - {@see WEB_KEY} `mandator-web`, «Website-Logo» — the website variant only, made PUBLIC by
 *     `ensurePublic()` once, when this call created it (documents.md: never on every request — a
 *     person's later «geschützt» stays). Only ever the one derived image lives there, so `/media`
 *     hands out nothing else of the company.
 *
 * Writes are the trusted system path (`saveGenerated()`, no session principal) — the gate is the
 * mandator screen's right (ADMIN) and its CSRF token; reads are `ModuleDrive::read()` (the module
 * reads back what it stored, checksum compared); a replaced or removed logo goes to the TRASH
 * (`ModuleDrive::trash()`), never deleted.
 */
final class LogoDrive
{
    /** The DMS module key of the protected logos (print, e-mail, login). */
    public const KEY = 'mandator';
    public const NAME = 'Firmenlogo';

    /** The DMS module key of the website logo — a public partition of its own. */
    public const WEB_KEY = 'mandator-web';
    public const WEB_NAME = 'Website-Logo';

    /** The backend host of the DMS document delivery (`DocumentDeliveryTrait`). */
    public const DMS_BASE = '/backend/documents';

    /** Whether module-dms is installed AND registered — without it the window takes no upload. */
    public static function available(): bool
    {
        return class_exists(DocumentService::class) && DI::getModuleManager()->getModuleConfig('dms') !== null;
    }

    /**
     * Store one derived variant, return its document id. The website variant goes to the public
     * partition (made public when this call created it), every other use to the protected one.
     */
    public function store(string $use, string $bytes, string $ext, string $mime, string $companyName): int
    {
        $documents = DocumentService::create();
        $drive     = $use === LogoUses::WEB
            ? $documents->forModule(self::WEB_KEY, self::WEB_NAME)
            : $documents->forModule(self::KEY, self::NAME);
        if ($use === LogoUses::WEB && $drive->created()) {
            $drive->ensurePublic();
        }
        $label    = LogoUses::LABELS[$use] ?? $use;
        $document = $drive->saveGenerated($bytes, new SaveRequest(
            // A generated name, never the upload's: nothing of the request reaches a path or a slug.
            originalName: 'firmenlogo-' . $use . '.' . $ext,
            mimeType:     $mime,
            folderId:     $drive->root()->getId(),
            displayName:  'Firmenlogo ' . $label . ($companyName !== '' ? ' — ' . $companyName : ''),
            // The image is the derived variant already: no further size ladder, only the Drive's thumbnail.
            showOriginal: true,
        ));

        return (int) $document->getId();
    }

    /** The stored bytes of a use's document — null when gone, in the trash, outside the partition or altered. */
    public function read(string $use, int $documentId): ?string
    {
        return $this->find($use)?->read($documentId);
    }

    /** The `/media` address of the website logo — null unless it is publicly delivered. */
    public function publicUrl(int $documentId): ?string
    {
        return $this->find(LogoUses::WEB)?->publicUrl($documentId);
    }

    /** Move a replaced / removed logo to the Drive's trash (restorable). False = not touched. */
    public function trash(string $use, int $documentId): bool
    {
        return $this->find($use)?->trash($documentId) ?? false;
    }

    /**
     * What the window shows of a stored document: the gated preview address (the session's DMS read
     * right decides at the action, RF-4a), or — for the website logo — its public address; null when
     * the session may not read it or it is gone.
     */
    public function previewUrl(string $use, int $documentId): ?string
    {
        if ($use === LogoUses::WEB) {
            return $this->publicUrl($documentId);
        }
        $doc = DocumentService::create()->get($documentId);
        if (!$doc instanceof Document || $doc->isDeleted() || !Authz::create()->allows('document', $documentId, 'read')) {
            return null;
        }

        return MediaField::previewUrl($doc, self::DMS_BASE);
    }

    /** The partition's handle WITHOUT creating it — a read never sets up a partition. */
    private function find(string $use): ?ModuleDrive
    {
        return DocumentService::create()->findModule($use === LogoUses::WEB ? self::WEB_KEY : self::KEY);
    }
}
