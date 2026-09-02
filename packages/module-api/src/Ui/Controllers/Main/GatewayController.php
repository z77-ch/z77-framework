<?php

namespace Z77\Module\Api\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\ApiResponder,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Module\Api\Services\ServiceRegistry,
    Z77\Shared\Api\ApiLog,
    Z77\Shared\Api\ApiRequest,
    Z77\Shared\Api\ApiResult,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * The one action behind the stateless `/api` reserved route. Parses version
 * and endpoint from the content slugs (`/api/v1/units` → `[v1, units]`),
 * rate-limits per tenant, dispatches to the declared service, and maps the
 * result onto the wire via ApiResponder — the transport per api-envelope-v1.
 *
 * Deliberately NOT an AbstractBaseController: the base's run() resolves
 * MessageService (session-bound), which is unregistered on the stateless
 * path — by design (bootstrap.md). This controller implements the same
 * `run(): ResponseInterface` contract the Dispatcher expects, and nothing
 * session-shaped can sneak in.
 *
 * No `#[Fetch]`/`#[Page]` attributes anywhere here: API requests are
 * mode-agnostic (routing.md → Stateless reserved routes). Method policy
 * (GET/HEAD only → 405) is enforced in-action because the envelope answers
 * 405, not the attribute's 404.
 */
final class GatewayController
{
    private const VERSION = 'v1';

    public function __construct(private readonly string $actionMethod)
    {
    }

    public function run(): ResponseInterface
    {
        $actionMethod = $this->actionMethod;
        return $this->$actionMethod();
    }

    protected function handleAction(): ResponseInterface
    {
        $started = microtime(true);
        $request = DI::getRequest();
        $guard   = DI::getInstance()->get('ApiKeyGuard');
        $slugs   = $request->getSlugs();

        $apiRequest = new ApiRequest(
            $guard->getTenantId(),
            array_slice($slugs, 2),
            $request->getQueryParameters(),
            $request->getIfNoneMatch(),
            $guard->getKeyRef()
        );

        $result   = $this->route($request->getMethod(), $slugs, $apiRequest);
        $response = ApiResponder::respond($apiRequest, $result);

        if ($request->isHead()) {
            $response->omitBody();
        }

        // Log the full principal (`8` or `8:widget-a`) — with two keys on one
        // tenant, WHICH one called is exactly the question a rotation asks.
        $principal = $apiRequest->tenantId
            . ($apiRequest->keyRef !== null ? ':' . $apiRequest->keyRef : '');
        ApiLog::line($principal, $response->getStatus(), (microtime(true) - $started) * 1000);

        return $response;
    }

    /** @param list<string> $slugs */
    private function route(string $method, array $slugs, ApiRequest $apiRequest): ApiResult
    {
        if (!in_array($method, ['get', 'head'], true)) {
            return ApiResult::error('method_not_allowed', 405, 'Only GET and HEAD are supported.');
        }

        if (($slugs[0] ?? '') !== self::VERSION) {
            return ApiResult::error('unknown_version', 404, 'Unknown API version.');
        }

        $throttled = $this->throttle($apiRequest);
        if ($throttled !== null) {
            return $throttled;
        }

        $endpoint = $slugs[1] ?? '';
        $service  = $endpoint === ''
            ? null
            : (new ServiceRegistry(DI::getModuleManager()))->resolve($endpoint);
        if ($service === null) {
            return ApiResult::error('unknown_endpoint', 404, 'Unknown endpoint.');
        }

        try {
            return $service->handle($apiRequest);
        } catch (\Throwable $e) {
            // The envelope never leaks internals; the real error goes to the
            // PHP error log for the operator.
            error_log("api: service for '{$endpoint}' failed: " . $e->getMessage());
            return ApiResult::error('internal', 500, 'Internal error.');
        }
    }

    /**
     * null while the caller stays under its hourly limit; the 429 result past
     * it. Counted per CONNECTION when the resolver names one (`keyRef`) — a
     * chatty connection must not eat a sibling's quota — else per tenant.
     */
    private function throttle(ApiRequest $apiRequest): ?ApiResult
    {
        $limit = (int) (DI::getModuleManager()->getModuleConfig('api')?->get('rateLimitPerHour', 600) ?? 600);
        $dir   = rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib/throttle/api';

        $counterKey = $apiRequest->keyRef !== null
            ? 'key:' . $apiRequest->tenantId . ':' . $apiRequest->keyRef
            : 'tenant:' . $apiRequest->tenantId;

        $throttle = new FileThrottle($dir);
        if ($throttle->allow($counterKey, $limit, 3600)) {
            return null;
        }

        return ApiResult::error(
            'throttled',
            429,
            'Rate limit exceeded.',
            $throttle->retryAfter(3600)
        );
    }
}
