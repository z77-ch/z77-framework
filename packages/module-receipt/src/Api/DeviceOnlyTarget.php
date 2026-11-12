<?php

namespace Z77\Module\Receipt\Api;

use Z77\Shared\Api\ApiRequest;
use Z77\Shared\Api\ApiResult;

/**
 * What the receipt targets share (wave 2, plan B4 as changed by E1 / E2 / E10): one scope for all
 * three methods, and the refusal of a machine caller.
 *
 * Why a bearer key is refused although it passes the gateway (ADR-057 decision 6): a machine
 * principal skips the scope check by design, and these targets act for a PERSON — the receipt
 * journal records who captured it (E8), and the templates and payment methods are installation
 * data no tenant of the machine channel has business reading. So every target answers a device
 * only (`$request->device()`), whatever the gateway let through.
 */
trait DeviceOnlyTarget
{
    /** The scope a device needs for every receipt target — an app user is given it by this key (ADR-058). */
    public const SCOPE = 'receipt.capture';

    /** null when a paired device asks for the endpoint itself; the refusal otherwise. */
    private static function refused(ApiRequest $request): ?ApiResult
    {
        if ($request->slugs !== []) {
            return ApiResult::error('unknown_endpoint', 404, 'Unknown endpoint.');
        }
        if ($request->device() === null) {
            return ApiResult::error('forbidden', 403, 'This endpoint answers a paired device only.');
        }
        return null;
    }

    /**
     * Success with the payload's hash as ETag — the responder answers 304 when the phone holds
     * this state already (its offline copy stays valid without a transfer).
     *
     * @param array<string, mixed> $data
     */
    private static function withEtag(array $data): ApiResult
    {
        return ApiResult::payload($data, hash('sha256', (string) json_encode($data, JSON_UNESCAPED_UNICODE)));
    }
}
