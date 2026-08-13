<?php

namespace Z77\Module\Member\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController;
use Z77\Core\DI;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Module\Member\Services\InvitationFlow;
use Z77\Module\Member\Services\MemberAuth;
use Z77\Module\Member\Services\RegistrationFlow;

/**
 * Base of the member view-area (B7). Centralises the three things every member
 * page needs: the namespace constant (template/asset resolution), the
 * production-wired RegistrationFlow with the absolute confirm URL derived
 * from the current request — the link that lands in the confirmation mail —
 * and the signed-in member for the shell chrome.
 */
abstract class AbstractMemberController extends AbstractBaseController
{
    protected const NAMESPACE = 'Z77\\Module\\Member';

    /**
     * Injects `memberUser` and `memberTheme` into every member page.
     *
     * `memberUser` is a plain display view-model, NOT the account entity — the
     * shell header and footer render strings and self-skip when the key is
     * absent (mirrors the frontend's overlayUser, HEADER-AUTH-001). Guest pages
     * (login, register) simply carry null, which is what makes the same
     * partials safe there.
     *
     * `memberTheme` is the account's appearance choice as the string the
     * skeleton puts on <html>, empty when nobody decided — then the stylesheet
     * lets `prefers-color-scheme` answer. Rendering it server-side is what
     * keeps the page from flashing the wrong mode: no script has to correct a
     * ground that is already painted.
     */
    protected function html(array $context = []): HtmlResponse
    {
        if (!array_key_exists('memberUser', $context) || !array_key_exists('memberTheme', $context)) {
            $account = MemberAuth::create()->current();

            // array_key_exists, not ??=: a caller that deliberately passes
            // `memberUser => null` means «render this page without chrome»,
            // and null is exactly the value ??= would overwrite.
            if (!array_key_exists('memberUser', $context)) {
                $context['memberUser'] = $account === null ? null : [
                    'email'    => $account->getEmail(),
                    'name'     => trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? '')),
                    'initials' => self::initials($account->getFirstName(), $account->getLastName(), $account->getEmail()),
                ];
            }

            if (!array_key_exists('memberTheme', $context)) {
                $context['memberTheme'] = $account?->getTheme() ?? '';
            }
        }

        $this->addAreas($context);

        return parent::html($context);
    }

    /**
     * The areas of the switcher, and the name of the one being stood in.
     *
     * Derived from the NAVIGATION slot `member-main`, not from a list in code:
     * the areas are data (nav entries), so a project adds one by adding a nav
     * entry — and the framework module needs no knowledge of what AXO3 calls
     * its areas. This is the same derivation the old tab row did; it moved here
     * because the new skeleton splits the header into two cells and neither of
     * them should have to query a service.
     *
     * Two deliberate opt-outs:
     *   `areas => []`   the dashboard — its RAIL carries the areas, so a second
     *                   way to reach them in the same header would be noise.
     *   `areaName`      a detail page names itself; without it the routed nav
     *                   entry answers, and failing that the page title (the
     *                   profile carries no nav entry of its own).
     */
    private function addAreas(array &$context): void
    {
        $navigation = DI::getInstance()->get('NavigationService');
        $current    = '';
        $areas      = [];

        foreach ($navigation->getBySlot('member-main') as $entry) {
            $active  = $navigation->isActive($entry);
            $areas[] = [
                'name'   => $entry->getName(),
                'url'    => $navigation->urlFor($entry),
                'active' => $active,
            ];
            if ($active) {
                $current = $entry->getName();
            }
        }

        if (!array_key_exists('areas', $context)) {
            $context['areas'] = $areas;
        }

        if (!array_key_exists('areaName', $context)) {
            $context['areaName'] = $current !== '' ? $current : trim((string)($context['pageTitle'] ?? ''));
        }
    }

    /**
     * Two letters for the avatar. Name first, and only the parts that are
     * there — an account carrying just a surname gets one letter, not a letter
     * plus a placeholder. Without any name the e-mail answers, because every
     * account has one; `mb_*` throughout, since «Ürs» must not become a broken
     * byte.
     */
    private static function initials(?string $firstName, ?string $lastName, string $email): string
    {
        $letters = '';
        foreach ([$firstName, $lastName] as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $letters .= mb_substr($part, 0, 1);
            }
        }

        if ($letters === '') {
            $local   = trim(strstr($email, '@', true) ?: $email);
            $letters = mb_substr($local, 0, 2);
        }

        return mb_strtoupper($letters);
    }

    protected function flow(): RegistrationFlow
    {
        return RegistrationFlow::create($this->absoluteUrl('/member/main/confirm'));
    }

    /**
     * The invitation story (B7 v1.1.0). Same shape as flow(): the absolute URL
     * of the page the invitation mail links to — the register route, which
     * recognises an invitation by its `invite` parameter.
     */
    protected function invites(): InvitationFlow
    {
        return InvitationFlow::create($this->absoluteUrl('/member/main/register'));
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
