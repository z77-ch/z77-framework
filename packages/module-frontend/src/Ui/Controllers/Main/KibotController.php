<?php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController,
    Z77\Module\Frontend\Ui\Kibot\KibotEndpoint,
    Z77\Module\Frontend\Ui\Kibot\KibotSession,
    Z77\Core\DI,
    Z77\Core\Exception\NotFoundException,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\VoidResponse,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The KI-Bot widget's two routes (z77/kibot, docs/topics/kibot.md):
 *
 *   GET  /frontend/main/kibot/config  → widget texts + CSRF token (JSON)
 *   POST /frontend/main/kibot/ask     → {messages, token, website} → answer as SSE
 *
 * Both are Fetch-only: a navigation to them is a 404, so no page cache ever
 * stores a response carrying a token. The POST passes AccessGuard's global
 * CSRF check (X-CSRF-Token header, sent by bot.js) before it gets here; Guard
 * re-checks the same z77 token from the body, then honeypot, rate limits and
 * lengths. Off (both 404) while z77/kibot is not installed or the project has
 * no `config/client/kibot.inc.php`.
 *
 * The answer streams while the action runs and the action returns void():
 * the response helpers buffer a complete body, a stream has none. Headers are
 * set here, output buffers are dropped so every frame reaches the browser.
 */
final class KibotController extends AbstractFrontendController
{
    #[Fetch, HttpMethod('GET')]
    protected function configAction(): JsonResponse
    {
        return $this->json($this->endpoint()->widget(DI::getCsrfService()->getToken()));
    }

    #[Fetch, HttpMethod('POST')]
    protected function askAction(): VoidResponse
    {
        $endpoint = $this->endpoint();
        $request  = DI::getRequest();
        $session  = new KibotSession(DI::getSessionManager(), DI::getCsrfService());

        ignore_user_abort(true); // finish and log even when the visitor closes the tab
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');

        $endpoint->ask(
            $request->getJsonBody(),
            (string) $request->getClientIp(),
            $session,
            static function (string $frame): void {
                echo $frame;
                flush();
            }
        );

        return $this->void();
    }

    private function endpoint(): KibotEndpoint
    {
        return KibotEndpoint::fromConfig() ?? throw new NotFoundException('KiBot is not configured.');
    }
}
