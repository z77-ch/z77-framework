<?php

namespace Z77\Module\Member\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController;
use Z77\Core\DI;
use Z77\Module\Member\Services\RegistrationFlow;

/**
 * Base of the member view-area (B7). Centralises the two things every member
 * page needs: the namespace constant (template/asset resolution) and the
 * production-wired RegistrationFlow with the absolute confirm URL derived
 * from the current request — the link that lands in the confirmation mail.
 */
abstract class AbstractMemberController extends AbstractBaseController
{
    protected const NAMESPACE = 'Z77\\Module\\Member';

    protected function flow(): RegistrationFlow
    {
        return RegistrationFlow::create($this->absoluteUrl('/member/main/confirm'));
    }

    /**
     * Absolute URL for a link that leaves the request — every one built here ends
     * up in a mail. The origin comes from the installation's configured canonical
     * base URL, never from the request's Host header (SEC-005 / MEM-006).
     */
    protected function absoluteUrl(string $path): string
    {
        return DI::getRequest()->getBaseUrl() . $path;
    }
}
