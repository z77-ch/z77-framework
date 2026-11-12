<?php
namespace Z77\Module\Receipt\Ui;

use Z77\Module\Dms\Entities\Document,
    Z77\Module\Dms\Services\Authz,
    Z77\Module\Dms\Services\DocumentService,
    Z77\Module\Dms\Ui\MediaField
;

/**
 * What the receipt screens show of a receipt's PHOTO — a DMS document the capture stored
 * (`ReceiptCaptureService`, partition «Belege»). The addresses come from module-dms
 * ({@see MediaField::previewUrl()} / {@see MediaField::thumbUrl()} — the backend's domain-gated
 * preview action), never built here; the read gate is the DMS's (RF-4a): a document the session may
 * not read, or one that is gone, shows as `missing` and its name is never shown.
 *
 * A view model, read-only; nothing here touches the request.
 */
final class ReceiptPhoto
{
    /** The backend host of the DMS document delivery (`DocumentDeliveryTrait`). */
    public const DMS_BASE = '/backend/documents';

    /**
     * @param list<int> $documentIds
     * @return array<int, array{state: 'doc'|'missing', id: int, name: string, mime: string, size: string, isImage: bool, previewUrl: string|null, thumbUrl: string|null, canDelete: bool}>
     *         by document id
     */
    public static function of(array $documentIds, string $base = self::DMS_BASE): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds), static fn(int $id) => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $docs  = DocumentService::create();
        $authz = Authz::create();
        $out   = [];
        foreach ($ids as $id) {
            $doc = $docs->get($id);
            if (!$doc instanceof Document || !$authz->allows('document', $id, 'read')) {
                $out[$id] = self::missing($id);
                continue;
            }
            $out[$id] = [
                'state'      => 'doc',
                'id'         => $id,
                'name'       => $doc->getDisplayName(),
                'mime'       => $doc->getMimeType(),
                'size'       => self::humanSize($doc->getSizeBytes()),
                'isImage'    => $doc->getKind() === 'image',
                'previewUrl' => MediaField::previewUrl($doc, $base),
                'thumbUrl'   => MediaField::thumbUrl($doc, $base),
                'canDelete'  => $authz->allows('document', $id, 'manage'),
            ];
        }

        return $out;
    }

    /** The view of a photo that is not shown (gone, or not readable for the session). */
    public static function missing(int $id): array
    {
        return ['state' => 'missing', 'id' => $id, 'name' => '', 'mime' => '', 'size' => '', 'isImage' => false, 'previewUrl' => null, 'thumbUrl' => null, 'canDelete' => false];
    }

    /** 1258291 → «1.2 MB». */
    public static function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 * 1024 => number_format($bytes / (1024 * 1024), 1, '.', '') . ' MB',
            $bytes >= 1024        => (int) round($bytes / 1024) . ' KB',
            default               => $bytes . ' B',
        };
    }
}
