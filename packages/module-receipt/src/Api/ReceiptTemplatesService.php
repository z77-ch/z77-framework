<?php

namespace Z77\Module\Receipt\Api;

use Z77\Core\DI;
use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Module\Receipt\Services\ReceiptTemplateService;
use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;
use Z77\Shared\Api\ScopedApiServiceInterface;

/**
 * `GET /api/v1/receipt-templates` — the tiles of the phone (E2 / E3): the ACTIVE receipt templates
 * in tile order.
 *
 *   {"templates": [{"code": "tanken", "label": "Tanken", "icon": "fuel", "position": 10}, …]}
 *
 * Deliberately WITHOUT the accounts and the VAT code: the phone never chooses or shows them (E2),
 * the capture applies them on the server from the code. ETag = hash of the payload.
 */
final class ReceiptTemplatesService implements ScopedApiServiceInterface
{
    use DeviceOnlyTarget;

    public function scopeFor(string $method, array $slugs): ?string
    {
        return $method === 'get' ? self::SCOPE : null;
    }

    public function handle(ApiRequest $request): ApiResult
    {
        $refused = self::refused($request);
        if ($refused !== null) {
            return $refused;
        }

        $templates = (new ReceiptTemplateService(DI::getUnifiedEntityManager()))->active();

        return self::withEtag(['templates' => array_map(static fn(ReceiptTemplate $t): array => [
            'code'     => $t->getCode(),
            'label'    => $t->getLabel(),
            'icon'     => $t->getIcon(),
            'position' => $t->getPosition(),
        ], $templates)]);
    }
}
