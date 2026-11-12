<?php

namespace Z77\Module\Api\Ui\Controllers\App;

use Z77\Core\Controller\AbstractBaseController,
    Z77\Core\DI,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Module\Api\Services\AppScreens,
    Z77\Module\Api\Ui\AppScriptResponse
;

/**
 * The app shell `/app` (plan A6 / T6, ADR-057 addendum): what a paired phone opens — a PWA without
 * a store. Generic: it knows pairing and the device's scopes, the screens come from the modules
 * (`appScreens`, {@see AppScreens}).
 *
 *   /app/                      start — every contributed screen, each hidden until the device
 *                              shows the screen's scope (`GET /api/v1/devices/me`, client side)
 *   /app/pair?code=…           pairing — the QR of the backend screen «Geräte» opens it with the
 *                              code filled in; typing the code is the other way
 *   /app/manifest.webmanifest  the web app manifest (`scope` and `start_url` /app/)
 *   /app/sw.js                 the service worker — served under /app/ so its scope is /app/
 *
 * The pages carry no user data: the device cookie is on `/api` only (Path, SameSite=Strict), so the
 * server cannot know here who asks — the page's script asks the gateway and builds the lists with
 * DOM nodes and `textContent`. Pairing and sending need the header `X-Z77-App` (ADR-057 CSRF rule),
 * which a plain form cannot send: without JavaScript the pages say so instead of offering a form
 * that would end in a JSON 403.
 *
 * Every HTML answer carries an ENFORCED CSP ({@see CSP}). It is set here, before the answer, and
 * `SecurityHeaders::send()` keeps a header an action already set — the per-answer mechanism the
 * password reset uses for its `Referrer-Policy`; the global default stays observing for every
 * other page (security.md SEC-HEAD-001).
 */
final class AppController extends AbstractBaseController
{
    private const NS = 'Z77\\Module\\Api';

    /**
     * The app pages' policy, enforced: scripts, styles, images, fetches, the worker and the
     * manifest from this origin only; no inline script or style, no plugin, no framing. The photo
     * preview is a canvas (no `blob:` image), so `img-src 'self'` holds.
     */
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; "
        . "connect-src 'self'; worker-src 'self'; manifest-src 'self'; object-src 'none'; "
        . "base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    /** The pairing code as the backend shows it (ApiDevices: `ABCDE-FGHJK`), loosely — the server checks. */
    private const CODE_PATTERN = '/^[A-Za-z0-9 -]{1,24}$/';

    protected function startAction(): HtmlResponse
    {
        $this->pageOnly();
        $this->appHeaders();

        $screens = (new AppScreens(DI::getModuleManager()))->all();
        $response = $this->html(['screens' => $screens, 'page' => 'start']);
        foreach ($screens as $screen) {
            foreach ($screen['js'] as $script) {
                $this->layoutManager->addJs($script, $screen['nameSpace']);
            }
        }

        return $response;
    }

    protected function pairAction(): HtmlResponse
    {
        $this->pageOnly();
        $this->appHeaders();
        // The code is in this page's address: it must not leave in a Referer header.
        header('Referrer-Policy: no-referrer');

        $code = DI::getRequest()->getGetParameter('code');
        $code = is_string($code) && preg_match(self::CODE_PATTERN, trim($code)) === 1 ? strtoupper(trim($code)) : '';

        return $this->html(['code' => $code, 'page' => 'pair']);
    }

    protected function manifestAction(): JsonResponse
    {
        $this->pageOnly();

        return $this->json([
            'id'               => '/app/',
            'name'             => 'z77 App',
            'short_name'       => 'z77 App',
            'lang'             => 'de',
            'start_url'        => '/app/',
            'scope'            => '/app/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'background_color' => '#f3f4fb',
            'theme_color'      => '#211d45',
            // The installation's own icons (the files every z77 project serves from public/).
            'icons'            => [
                ['src' => '/web-app-manifest-192x192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
                ['src' => '/web-app-manifest-512x512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ],
        ]);
    }

    protected function serviceWorkerAction(): AppScriptResponse
    {
        $this->pageOnly();

        // The served copy in public/{assetDir}/api (the installer publishes res/assets there),
        // minified in production — the rule JavascriptManager applies to every script.
        $finder = DI::getFileFinder();
        $path   = (DEBUG ? null : $finder->getFirstAssetMatch('js/app-sw.min.js', self::NS, false))
            ?? $finder->getFirstAssetMatch('js/app-sw.js', self::NS);

        return $this->script((string) file_get_contents($path));
    }

    /**
     * The worker's answer — see {@see AppScriptResponse} for why it is not `bytes()`. The one
     * response of this controller that is not a kernel helper's.
     */
    private function script(string $js): AppScriptResponse
    {
        return new AppScriptResponse($js);
    }

    /** Each route answers its own path only: `/app/anything` is not the start page. */
    private function pageOnly(): void
    {
        if (DI::getRequest()->getSlugs() !== []) {
            throw new NotFoundException('Unknown app page.');
        }
    }

    /** Before html(): SecurityHeaders keeps what an action set (security.md). */
    private function appHeaders(): void
    {
        header('Content-Security-Policy: ' . self::CSP);
        header('X-Robots-Tag: noindex, nofollow');
    }
}
