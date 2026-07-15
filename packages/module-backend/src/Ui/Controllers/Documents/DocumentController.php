<?php
namespace Z77\Module\Backend\Ui\Controllers\Documents;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Dms\Ui\DocumentDeliveryTrait;

/**
 * Backend mount of the DMS document byte-delivery (ADR-018 / ADR-019 / ADR-020). Logic
 * lives in {@see DocumentDeliveryTrait}; the per-document gate is in the domain
 * (`DocumentService::serveFor` — effective read, RF-4a), the backend route + auth is
 * only the coarse first line. Registered in `backendConfig` (group `documents`, ADMIN);
 * streams bytes for the Drive's preview/thumbnail + download.
 *
 * URL: /backend/documents/document/{preview,download}?id=&variant=
 */
class DocumentController extends BackendAbstractController
{
    use DocumentDeliveryTrait;
}
