<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Member\Services\RegistrationFlow,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController
;

/**
 * B7 confirmation landing (?token=…): one action, three outcomes from the
 * flow — confirmed (the gate opens, operator notification went out),
 * already («bereits bestätigt», the double click), dead (unknown, expired
 * or used link → the template offers the resend link). The template renders
 * per outcome; no outcome reveals more than the spec's wording.
 */
class ConfirmController extends AbstractMemberController
{
    protected function indexAction(): HtmlResponse
    {
        $outcome = $this->flow()->confirm(
            (string)DI::getRequest()->getGetParameter('token')
        );

        return $this->html([
            'pageTitle' => match ($outcome) {
                RegistrationFlow::CONFIRMED => 'E-Mail bestätigt',
                RegistrationFlow::ALREADY   => 'Bereits bestätigt',
                default                     => 'Link nicht mehr gültig',
            },
            'outcome' => $outcome,
        ]);
    }
}
