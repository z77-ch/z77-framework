<?php

namespace Z77\Module\Api\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Request,
    Z77\Core\Http\Response\ApiResponder,
    Z77\Core\Http\Response\ResponseInterface,
    Z77\Core\Services\ApiKeyGuard,
    Z77\Module\Api\Services\IdempotencyStore,
    Z77\Module\Api\Services\ServiceRegistry,
    Z77\Shared\Api\ApiLog,
    Z77\Shared\Api\ApiRequest,
    Z77\Shared\Api\ApiResult,
    Z77\Shared\Api\ApiServiceInterface,
    Z77\Shared\Api\ScopedApiServiceInterface,
    Z77\Shared\Auth\ApiPrincipal,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * The one action behind the stateless `/api` reserved route. Parses version
 * and endpoint from the content slugs (`/api/v1/units` → `[v1, units]`),
 * rate-limits per principal, checks method and scope, dispatches to the
 * declared service, and maps the result onto the wire via ApiResponder — the
 * transport per api-envelope-v1, with the device channel of ADR-057.
 *
 * Deliberately NOT an AbstractBaseController: the base's run() resolves
 * MessageService (session-bound), which is unregistered on the stateless
 * path — by design (bootstrap.md). This controller implements the same
 * `run(): ResponseInterface` contract the Dispatcher expects, and nothing
 * session-shaped can sneak in.
 *
 * No `#[Fetch]`/`#[Page]` attributes anywhere here: API requests are
 * mode-agnostic (routing.md → Stateless reserved routes). Method policy is
 * enforced in-action because the envelope answers 405, not the attribute's 404:
 *
 *   - GET/HEAD: every service;
 *   - POST: only a {@see ScopedApiServiceInterface} that names a scope for it.
 *     Any POST that does not reach such a service answers 405, as every POST
 *     did before the device channel (v1 unchanged for machine clients);
 *   - anything else: 405.
 *
 * Access: a machine (bearer) principal passes as before — the service decides
 * by tenant. A device principal needs a scoped service and the named scope
 * (403 otherwise). A device POST needs an `Idempotency-Key` (400 otherwise);
 * a key is honoured for any principal ({@see IdempotencyStore}).
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
        /** @var ApiKeyGuard $guard */
        $guard     = DI::getInstance()->get('ApiKeyGuard');
        $principal = $guard->getPrincipal();
        $slugs     = $request->getSlugs();
        $method    = $request->getMethod();
        $isPost    = $method === 'post';

        $apiRequest = new ApiRequest(
            $principal?->tenantId ?? '',
            array_slice($slugs, 2),
            $request->getQueryParameters(),
            $request->getIfNoneMatch(),
            $principal?->keyRef,
            method: $method,
            body: $isPost ? self::body($request) : [],
            files: $isPost ? $request->getAllUploadedFiles() : [],
            principal: $principal,
            idempotencyKey: $isPost ? $request->getIdempotencyKey() : null,
        );

        $result   = $this->route($method, $slugs, $apiRequest, $request);
        $response = ApiResponder::respond($apiRequest, $result);

        if ($request->isHead()) {
            $response->omitBody();
        }

        // Log the full principal (`8`, `8:widget-a`, `device:3`) — with two keys on
        // one tenant, WHICH one called is exactly the question a rotation asks.
        ApiLog::line($principal?->label(), $response->getStatus(), (microtime(true) - $started) * 1000);

        return $response;
    }

    /** @param list<string> $slugs */
    private function route(string $method, array $slugs, ApiRequest $apiRequest, Request $request): ApiResult
    {
        if (!in_array($method, ['get', 'head', 'post'], true)) {
            return self::methodNotAllowed();
        }
        $isPost = $method === 'post';

        if (($slugs[0] ?? '') !== self::VERSION) {
            return $isPost
                ? self::methodNotAllowed()
                : ApiResult::error('unknown_version', 404, 'Unknown API version.');
        }

        $throttled = $this->throttle($apiRequest->principal, $request);
        if ($throttled !== null) {
            return $throttled;
        }

        $endpoint = $slugs[1] ?? '';
        $service  = $endpoint === ''
            ? null
            : (new ServiceRegistry(DI::getModuleManager()))->resolve($endpoint);
        if ($service === null) {
            return $isPost
                ? self::methodNotAllowed()
                : ApiResult::error('unknown_endpoint', 404, 'Unknown endpoint.');
        }

        $denied = $this->access($service, $method, $endpoint, $apiRequest);
        if ($denied !== null) {
            return $denied;
        }

        try {
            if (!$isPost || $apiRequest->principal === null) {
                return $service->handle($apiRequest);
            }

            $key = $apiRequest->idempotencyKey;
            if ($key === null) {
                return $apiRequest->device() !== null
                    ? ApiResult::error('bad_request', 400, 'Idempotency-Key header required.')
                    : $service->handle($apiRequest);
            }
            if (preg_match(IdempotencyStore::KEY_PATTERN, $key) !== 1) {
                return ApiResult::error('bad_request', 400, 'Malformed Idempotency-Key header.');
            }

            return (new IdempotencyStore(self::varLib() . '/api/idempotency'))->run(
                $apiRequest->principal->label(),
                $key,
                self::fingerprint($endpoint, $apiRequest),
                static fn(): ApiResult => $service->handle($apiRequest)
            );
        } catch (\Throwable $e) {
            // The envelope never leaks internals; the real error goes to the
            // PHP error log for the operator.
            error_log("api: service for '{$endpoint}' failed: " . $e->getMessage());
            return ApiResult::error('internal', 500, 'Internal error.');
        }
    }

    /**
     * Method and scope (ADR-057). null = may proceed; the error result otherwise.
     * The anonymous principal exists only on the pairing call — the guard lets
     * nothing else through without a credential; the check here is the second lock.
     */
    private function access(ApiServiceInterface $service, string $method, string $endpoint, ApiRequest $apiRequest): ?ApiResult
    {
        $asked = $method === 'head' ? 'get' : $method;
        $scope = $service instanceof ScopedApiServiceInterface
            ? $service->scopeFor($asked, $apiRequest->slugs)
            : null;

        if ($service instanceof ScopedApiServiceInterface ? $scope === null : $asked !== 'get') {
            return self::methodNotAllowed();
        }

        $principal = $apiRequest->principal;
        if ($principal === null) {
            return array_merge([self::VERSION, $endpoint], $apiRequest->slugs) === ApiKeyGuard::PAIRING_SLUGS
                ? null
                : ApiResult::error('unauthorized', 401, 'Missing credential.');
        }

        if (!$principal->isDevice()) {
            return null; // machine channel: today's rule, the service decides by tenant
        }
        if ($scope === null) {
            return ApiResult::error('forbidden', 403, 'This endpoint is not open to devices.');
        }
        if ($scope !== ScopedApiServiceInterface::NO_SCOPE && !$principal->hasScope($scope)) {
            return ApiResult::error('forbidden', 403, 'This device lacks the scope for this endpoint.');
        }
        return null;
    }

    /**
     * null while the caller stays under its hourly limit; the 429 result past
     * it. Counted per CONNECTION when the resolver names one (`keyRef`) — a
     * chatty connection must not eat a sibling's quota — else per tenant; a
     * device per device, the anonymous pairing call per source /64.
     */
    private function throttle(?ApiPrincipal $principal, Request $request): ?ApiResult
    {
        $limit = (int) (DI::getModuleManager()->getModuleConfig('api')?->get('rateLimitPerHour', 600) ?? 600);

        $counterKey = match (true) {
            $principal === null          => 'anon:' . (FileThrottle::normalizeIp((string) ($request->getClientIp() ?? '')) ?? '-'),
            $principal->isDevice()       => 'device:' . $principal->deviceId,
            $principal->keyRef !== null  => 'key:' . $principal->tenantId . ':' . $principal->keyRef,
            default                      => 'tenant:' . $principal->tenantId,
        };

        $throttle = new FileThrottle(self::varLib() . '/throttle/api');
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

    /**
     * POST body: the form fields (multipart / urlencoded — PHP fills them), else
     * the decoded JSON body. A multipart upload carries its fields as form fields.
     *
     * @return array<string,mixed>
     */
    private static function body(Request $request): array
    {
        $fields = $request->getPostParameters();
        return $fields !== [] ? $fields : $request->getJsonBody();
    }

    /** What makes two sends "the same request": method, path, body, and each file's name, size and hash. */
    private static function fingerprint(string $endpoint, ApiRequest $apiRequest): string
    {
        $files = [];
        foreach ($apiRequest->files as $field => $list) {
            foreach ($list as $file) {
                $files[$field][] = [$file->originalName, $file->size, $file->sha256()];
            }
        }

        return hash('sha256', json_encode(
            [$apiRequest->method, $endpoint, $apiRequest->slugs, $apiRequest->body, $files],
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        ) ?: '');
    }

    private static function methodNotAllowed(): ApiResult
    {
        return ApiResult::error('method_not_allowed', 405, 'Method not supported on this endpoint.');
    }

    private static function varLib(): string
    {
        return rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/var/lib';
    }
}
