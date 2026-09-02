<?php

namespace Z77\Module\Api\Services;

use Z77\Shared\Api\ApiRequest,
    Z77\Shared\Api\ApiResult,
    Z77\Shared\Api\ApiServiceInterface
;

/**
 * Built-in `/api/v1/health`: keyed liveness + transport self-test — a 200
 * here proves routing, the Authorization pass-through, and key auth in one
 * request (api-envelope-v1). Deliberately no ETag: monitoring wants a fresh
 * answer every time.
 */
final class HealthService implements ApiServiceInterface
{
    public function handle(ApiRequest $request): ApiResult
    {
        return ApiResult::payload(['status' => 'ok']);
    }
}
