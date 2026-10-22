<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\BackendMenu,
    Z77\Module\Backend\Ui\DashboardBoard,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Dashboard\DashboardContributors
;

class DashboardController extends BackendAbstractController
{
    /** Template namespace of the dashboard's own templates (help). */
    private const NS = 'Z77\Module\Backend';

    /**
     * The entry page (ADR-052): one tile per menu section the user may see,
     * the modules' pending work and «+ Neu» buttons from the dashboard
     * registry (`dashboard` config key), each entry kept only when the user
     * may open its target — the same check the menu makes (ADR-045), so an
     * editor sees «Webseiten» and no debtor numbers.
     */
    protected function overviewAction(): HtmlResponse
    {
        $auth = DI::getAuthService();
        $user = $auth->getCurrentUser();
        $menu = BackendMenu::forCurrentUser('backend-main');

        $board = DashboardBoard::build(
            DashboardBoard::tilesFrom($menu),
            DashboardContributors::fromModules(DI::getModuleManager())->entriesFor($user),
            fn(string $module, string $group, string $controller, string $action): bool
                => $auth->canReach($user, $module, $group, $controller, $action)
        );

        $this->help->attach('System/DashboardController/overview.help', self::NS, [], 'Hilfe: Übersicht');

        return $this->html([
            'authUser'      => $user,
            'backendMenu'   => $menu,   // the shell reuses it (BackendAbstractController::html() `??=`)
            'tiles'         => $board['tiles'],
            'pending'       => $board['pending'],
            'today'         => new \DateTimeImmutable('today'),
            'activeSection' => 'overview',
            'crumbRoot'     => '',   // no «Dashboard» button on the dashboard itself
            'shellBare'     => true, // owner 2026-10-22: the dashboard alone starts right under the top bar — no band, no crumb row
            // «Angemeldet als: admin – Peter Ruepp»: the login name, and the person's name when entered (BackendUser::name)
            'signedInAs'    => trim($user->getUserName() . ' – ' . (DI::getCurrentUserService()->getBackendUser()?->getName() ?? ''), ' –'),
        ]);
    }
}
