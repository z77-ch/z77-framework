<?php
namespace Z77\Module\Frontend\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController,
    Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Core\Http\RequestMode,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Shared\Controller\RouteInfoTrait
;

/**
 * Base controller for frontend pages. Injects the admin overlay (environment
 * switcher + routing info + logout) into the bottom of every full page — but
 * ONLY for logged-in users with role >= admin, and only on full-page (Page mode)
 * loads. The overlay ships its own isolated, compiled CSS (`admin-overlay`) that
 * overrides the frontend's fonts/colours, so it looks the same on any site.
 */
abstract class AbstractFrontendController extends AbstractBaseController
{
    use RouteInfoTrait;

    protected const NAMESPACE = 'Z77\\Module\\Frontend';

    protected function html(array $context = []): HtmlResponse
    {
        $user    = DI::getAuthService()->getCurrentUser();
        $isAdmin = $user !== null && $user->hasAtLeast(AuthRole::ADMIN);
        $isPage  = DI::getRequest()->getMode() === RequestMode::Page;

        if ($isAdmin && $isPage) {
            // Plain display view-model — NOT the AuthUser security object. The auth
            // decision stays here in the controller (mirrors the backend headerUser
            // pattern, HEADER-AUTH-001); the partial renders strings only and self-skips
            // on data absence. Initials derived like the backend avatar (first two
            // letters, uppercased).
            $context['overlayUser'] = [
                'initials' => mb_strtoupper(mb_substr($user->getUserName(), 0, 2)),
                'name'     => $user->getUserName(),
                'role'     => $user->getHighestRole(),
            ];
            $context['viewAreas']   = DI::getInstance()->get('NavigationService')->getViewAreas();
            $context['routeInfo'] ??= $this->routeInfo();

            // Dev section (partial-label toggle) only under DEBUG — without
            // DEBUG the PartialLabels gate is closed anyway, so the switch
            // would be a dead control (PARTIAL-LABELS-002).
            if (DEBUG) {
                $request = DI::getRequest();
                $context['overlayDev'] = [
                    'partialLabels' => DI::getCurrentUserService()
                        ->getPreferences()
                        ->isPartialLabelsEnabled($request->getModule()),
                    'returnPath'    => $request->getRawRequestUri(),
                    'csrfToken'     => DI::getCsrfService()->getToken(),
                ];
            }
        }

        $response = parent::html($context);

        // Added after parent::html() (which runs initialize()): buildView at send
        // time picks these up — same pattern controllers use for per-action assets.
        if ($isAdmin && $isPage) {
            $this->layoutManager->addCss('admin-overlay', self::NAMESPACE);
            $this->layoutManager->addPartials('adminOverlay', 'partials', self::NAMESPACE, 'adminOverlay');
        }

        return $response;
    }
}
