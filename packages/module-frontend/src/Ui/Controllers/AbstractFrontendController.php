<?php
namespace Z77\Module\Frontend\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController,
    Z77\Core\Config\AuthRole,
    Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Services\NavigationService,
    Z77\Shared\Content\PageEditing,
    Z77\Shared\Controller\RouteInfoTrait,
    Z77\Shared\Entities\Navigation
;

/**
 * Base controller for frontend pages. Injects the admin overlay into the bottom
 * of every full page (Page mode) for a logged-in user with role >= editor — the
 * way from the site to the backend and out (logout). The overlay ships its own
 * isolated, compiled CSS (`admin-overlay`) that overrides the frontend's
 * fonts/colours, so it looks the same on any site.
 *
 * What the overlay offers follows the access rules, decided HERE (the partial
 * renders strings only, HEADER-AUTH-001):
 *   - «Umgebung»: the view areas, each linked to the first page the user may
 *     open (NavigationService::entryAllowedIn() over AuthService::canReach() —
 *     the rule the backend menu uses, MENU-ACCESS-001); an area without such a
 *     page is left out;
 *   - «Seite bearbeiten»: the page editor switch (ADR-045 §4) — a per-user,
 *     per-view-area preference, off by default;
 *   - «Info» (routing) and, under DEBUG, «Entwicklung» (partial labels): ADMIN
 *     only, as before;
 *   - «Abmelden».
 *
 * The page editor: when PageEditing::active() is true (editor + full page +
 * switch on) it adds `content-edit.js`, which puts a pencil on every slot the
 * templates marked (ContentView::editAttribute() — PageContent asks the same
 * PageEditing::active()). Switch off, or a visitor: neither markers nor script.
 */
abstract class AbstractFrontendController extends AbstractBaseController
{
    use RouteInfoTrait;

    protected const NAMESPACE = 'Z77\\Module\\Frontend';

    protected function html(array $context = []): HtmlResponse
    {
        $auth       = DI::getAuthService();
        $user       = $auth->getCurrentUser();
        $isAdmin    = $user !== null && $user->hasAtLeast(AuthRole::ADMIN);
        $hasOverlay = PageEditing::available();   // full page + role >= editor
        $editing    = $hasOverlay && PageEditing::active();

        if ($hasOverlay) {
            $request = DI::getRequest();
            $nav     = DI::getNavigationService();

            // Plain display view-model — NOT the AuthUser security object. The auth
            // decision stays here in the controller (mirrors the backend headerUser
            // pattern, HEADER-AUTH-001); the partial renders strings only and self-skips
            // on data absence. Initials derived like the backend avatar (first two
            // letters, uppercased).
            $context['overlayUser'] = [
                'initials' => mb_strtoupper(mb_substr($user->getUserName(), 0, 2)),
                'name'     => $user->getUserName(),
                'role'     => $user->getHighestRole(),
                'rail'     => $isAdmin ? 'admin' : 'redaktion',
            ];
            $context['viewAreas'] = $nav->getViewAreas(
                fn(Navigation $entry): bool => NavigationService::entryAllowedIn(
                    $entry,
                    fn(int $id): ?Navigation => $nav->findById($id),
                    fn(string $module, string $group, string $controller, string $action): bool
                        => $auth->canReach($user, $module, $group, $controller, $action)
                )
            );
            // The overlay's switches are plain form POSTs to AdminPanelController
            // (hidden CSRF + return path, 303 back here) — the overlay stays JS-free.
            $context['overlayForm'] = [
                'returnPath' => $request->getRawRequestUri(),
                'csrfToken'  => DI::getCsrfService()->getToken(),
            ];
            $context['overlayEdit'] = ['on' => $editing];

            if ($isAdmin) {
                $context['overlayInfo'] = $context['routeInfo'] ?? $this->routeInfo();

                // Dev section (partial-label toggle) only under DEBUG — without
                // DEBUG the PartialLabels gate is closed anyway, so the switch
                // would be a dead control (PARTIAL-LABELS-002).
                if (DEBUG) {
                    $context['overlayDev'] = [
                        'partialLabels' => DI::getCurrentUserService()
                            ->getPreferences()
                            ->isPartialLabelsEnabled($request->getModule()),
                    ];
                }
            }
        }

        $response = parent::html($context);

        // Added after parent::html() (which runs initialize()): buildView at send
        // time picks these up — same pattern controllers use for per-action assets.
        if ($hasOverlay) {
            $this->layoutManager->addCss('admin-overlay', self::NAMESPACE);
            $this->layoutManager->addPartials('adminOverlay', 'partials', self::NAMESPACE, 'adminOverlay');
        }
        if ($editing) {
            $this->layoutManager->addJs('content-edit', self::NAMESPACE);
        }

        return $response;
    }
}
