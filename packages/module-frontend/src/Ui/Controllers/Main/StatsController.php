<?php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController,
    Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Core\Http\Response\NoContentResponse,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Stats\StatsEvents,
    Z77\Shared\Stats\StatsRecorder
;

/**
 * The statistics beacon — `/frontend/main/stats/event?event={name}&path={path}`
 * (docs/topics/stats.md). For what the server cannot count itself because it
 * never passes PHP: a media file the web server delivers as a static file, an
 * outbound link, a popup opened on the page.
 *
 * It accepts exactly two things: an event NAME that the project declared
 * ({@see StatsEvents} — the declaration is the whitelist; anything else is
 * dropped, and the answer does not say which) and the page's path. No free
 * text, no id, no cookie is read, no session state is written. The answer is
 * always 204 — a beacon has nothing to learn from it.
 *
 * GET, not POST: the browser's `sendBeacon()` cannot carry headers, and every
 * Fetch-mode POST dies at the AccessGuard's global CSRF check — rightly so for
 * a form, pointless for a counter that changes no state anyone can read back.
 * A GET carries the two values in the query and is sent as
 *
 *   fetch('/frontend/main/stats/event?event=floorplan-pdf&path='
 *         + encodeURIComponent(decodeURIComponent(location.pathname)),
 *         {keepalive: true, cache: 'no-store'});
 *
 * (`keepalive` survives the page unloading on an outbound click; `no-store`
 * because a 204 to a GET is heuristically cacheable; `location.pathname` is
 * already percent-encoded, so it is decoded before it is encoded once — the
 * server decodes once more and lands on the page view's spelling either
 * way). The project decides which clicks are events and adds the call; the
 * framework ships no script for it (Rule 7 — and the events are the
 * project's, not the framework's). One address may post
 * {@see StatsRecorder::BEACON_LIMIT_PER_HOUR} events an hour; beyond that
 * the answer is still 204 and nothing is written.
 */
final class StatsController extends AbstractFrontendController
{
    #[HttpMethod('GET')]
    protected function eventAction(): NoContentResponse
    {
        $request = DI::getRequest();
        $name    = $request->getGetParameter('event');
        $path    = $request->getGetParameter('path');

        // Type-checked, never cast: `event[]=x` arrives as an ARRAY, and a
        // (string) cast of it is "Array" plus an E_WARNING that DEBUG would
        // print into the body of the 204. Anything that is not a string is
        // nothing — and a missing path is `/` (StatsRecorder::cleanPath()).
        //
        // Declared? Asked HERE, outside the recorder's catch-all: a malformed
        // declaration is a configuration error and must answer 500 into the
        // error log, not drop every event silently.
        if (is_string($name) && StatsEvents::isDeclared($name)) {
            StatsRecorder::event(
                $name,
                is_string($path) ? $path : '',
                $request,
                DI::getAuthService()->getCurrentUser()->hasAtLeast(AuthRole::EDITOR)
            );
        }

        return $this->noContent();
    }
}
