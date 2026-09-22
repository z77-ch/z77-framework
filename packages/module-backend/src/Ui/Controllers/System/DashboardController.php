<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController
;

class DashboardController extends BackendAbstractController
{
    /**
     * The overview cards: [module, group, controller, action] is the page a
     * card opens. A card is shown only when the user may open that page — the
     * same check the menu makes (ADR-045), so an editor sees «Frontend» and
     * not «Stammdaten».
     */
    private const CARDS = [
        ['id' => 'frontend', 'code' => '01', 'label' => 'Frontend',   'sub' => 'Webinhalte',            'target' => ['backend', 'content', 'content', 'list']],
        ['id' => 'master',   'code' => '02', 'label' => 'Stammdaten', 'sub' => 'Navigation · Benutzer', 'target' => ['backend', 'content', 'navigation', 'list']],
    ];

    protected function overviewAction(): HtmlResponse
    {
        $auth = DI::getAuthService();
        $user = $auth->getCurrentUser();

        $cards = [];
        foreach (self::CARDS as $card) {
            [$module, $group, $controller, $action] = $card['target'];
            if ($auth->canReach($user, $module, $group, $controller, $action)) {
                $card['url'] = '/' . implode('/', $card['target']);
                $cards[]     = $card;
            }
        }

        return $this->html([
            'authUser'      => $user,
            'cards'         => $cards,
            'activeSection' => 'overview',
        ]);
    }
}
