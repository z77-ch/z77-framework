<?php

namespace Z77\Module\Receipt\Api;

use Z77\Core\DI;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Services\PaymentMethodService;
use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;
use Z77\Shared\Api\ScopedApiServiceInterface;

/**
 * `GET /api/v1/payment-methods` — the choice «Bezahlt mit» on the capture screen (E10): the
 * ACTIVE payment methods in choice order.
 *
 *   {"payment_methods": [{"code": "kasse", "label": "Kasse"}, …]}
 *
 * Without the credit account: the phone picks a method, never an account (E2 / E10). ETag = hash
 * of the payload.
 */
final class PaymentMethodsService implements ScopedApiServiceInterface
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

        $methods = (new PaymentMethodService(DI::getUnifiedEntityManager()))->active();

        return self::withEtag(['payment_methods' => array_map(static fn(PaymentMethod $m): array => [
            'code'  => $m->getCode(),
            'label' => $m->getLabel(),
        ], $methods)]);
    }
}
