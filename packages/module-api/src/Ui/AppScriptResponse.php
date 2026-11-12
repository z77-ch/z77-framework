<?php

namespace Z77\Module\Api\Ui;

use Z77\Core\Http\Response\ResponseInterface;

/**
 * The service worker of the app shell, delivered as JavaScript from `/app/sw.js` — created only by
 * `AppController::script()`.
 *
 * Why not `bytes()` / `file()`: their {@see \Z77\Core\Http\Response\DeliveryPolicy} turns every
 * script into `text/plain` with a sandbox CSP on purpose — they hand out files a person uploaded.
 * A browser registers a service worker only from a JavaScript type, and only for the path it is
 * served from (scope `/app/` needs a script under `/app/`, a versioned `/assets/…` URL cannot be
 * one). This answer carries the framework's OWN asset (module-api `res/assets/js/app-sw.js`),
 * never request input.
 *
 * The CSP header here is the worker's own policy (a worker does not inherit the page's).
 * `no-cache`: the browser revalidates the worker on every navigation, so an update arrives.
 */
final class AppScriptResponse implements ResponseInterface
{
    public const CSP = "default-src 'self'";

    public function __construct(private readonly string $script)
    {
    }

    public function send(): void
    {
        header('Content-Type: text/javascript; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: ' . self::CSP);
        header('Cache-Control: no-cache');
        header('Content-Length: ' . strlen($this->script));
        echo $this->script;
    }
}
