<?php
namespace Z77\Module\Frontend\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Frontend\Ui\Controllers\AbstractFrontendController,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Attributes\Page
;

/**
 * Endpoint of the frontend admin overlay (adminOverlay partial). Role gate is
 * config-only: frontendConfig maps this controller to AuthRole::ADMIN.
 *
 * No listAction/homeAction on purpose — the convention URL stays 404; the
 * controller exists solely for the overlay's form posts.
 */
class AdminPanelController extends AbstractFrontendController
{
    /**
     * Toggles the partial-label overlay preference (PARTIAL-LABELS-002) for the
     * current user in the CURRENT view area (= request module key, ADR-022) and
     * redirects back to the page the form was on. Plain page-mode form POST —
     * the overlay stays JS-free. CSRF via the declarative #[Csrf] attribute
     * (AccessGuard reads the `csrf_token` body field; failure → 303 to root).
     */
    #[Page, HttpMethod('POST'), Csrf]
    protected function togglePartialLabelsAction(): RedirectResponse
    {
        $request = DI::getRequest();

        $viewArea = $request->getModule();
        $service  = DI::getCurrentUserService();
        $prefs    = $service->getPreferences();

        $prefs->setPartialLabelsEnabled($viewArea, !$prefs->isPartialLabelsEnabled($viewArea));
        $service->savePreferences($prefs);

        return $this->redirect($this->safeReturnPath($request->getPostParameter('return')), 303);
    }

    /** Same-site path only (must start with '/', no '//' scheme-relative) — no open redirect. */
    private function safeReturnPath(mixed $path): string
    {
        if (is_string($path)
            && str_starts_with($path, '/')
            && !str_starts_with($path, '//')
        ) {
            return $path;
        }
        return '/';
    }
}
