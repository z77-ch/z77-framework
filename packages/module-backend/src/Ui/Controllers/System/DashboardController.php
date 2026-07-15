<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController
;

class DashboardController extends BackendAbstractController
{
    protected function overviewAction(): HtmlResponse
    {
        return $this->html([
            'authUser'      => DI::getAuthService()->getCurrentUser(),
            'activeSection' => 'overview',
        ]);
    }
}
