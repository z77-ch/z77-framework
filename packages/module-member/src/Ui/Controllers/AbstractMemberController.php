<?php

namespace Z77\Module\Member\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController;
use Z77\Core\DI;
use Z77\Core\Http\Response\HtmlResponse;
use Z77\Module\Member\Entities\MemberAccount;
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

            if (!array_key_exists('memberTenant', $context)) {
                $context['memberTenant'] = $this->tenantLabel($account);
            }
        }

        $this->addAreas($context);

        return parent::html($context);
    }

    /**
     * WHOSE data is on screen — the readable name of the account's project
     * reference, for the header (Peter, 2026-08-14: one has to be able to see
     * which tenant is loaded).
     *
     * The module knows no tenants, so it asks the project: the same
     * `tenantLabelHook` the invitation mail and the backend account list use.
     * No hook, no reference, no label — the header then simply carries nothing,
     * which is right for a project whose accounts hang on nothing.
     *
     * ⚠️ Deliberately NOT the account's company field. That is what the person
     * typed at registration; the tenant name is what the installation actually
     * loaded, and when the two differ, the second one is the one worth seeing.
     */
    private function tenantLabel(?MemberAccount $account): string
    {
        $ref = trim((string)$account?->getTenantRef());
        if ($ref === '') {
            return '';
        }

        $fqcn = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', self::NAMESPACE)
            ->get('tenantLabelHook', '');

        if ($fqcn === '' || !class_exists($fqcn)) {
            return '';
        }

        try {
            return trim((string)(new $fqcn())($ref));
        } catch (\Throwable) {
            // A label is chrome. A project hook that stumbles must not cost the
            // page it decorates.
            return '';
        }
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
     * The same loop builds the AREA RAIL, for a page that wants the areas as
     * its left column (the dashboard). A caller asks for it by handing in
     * `railMeta` — the numbers it wants hung on the areas — and gets back a
     * `railItems` list whose names, links and ORDER come from the navigation.
     * A caller with its own `railItems` (a list of snippets, a property tree)
     * keeps them untouched.
     *
     * ⚠️ `railMeta` is addressed by the entry's ROUTING identity,
     * `controller/action` — the two fields the backend form actually offers,
     * compared trimmed and case-insensitively. NOT by {@see Navigation::$key}:
     * that one is server-controlled (ADR-032 import identity) and stays NULL
     * on every entry a human creates in the backend, so a binding on it would
     * be silently dead on exactly the installation that matters (Peter,
     * 2026-08-24 — the key is readable everywhere and settable nowhere).
     * A number for an entry that is not in the slot is simply not shown; an
     * entry with no number renders as a plain row instead of a stacked one.
     *
     * Three deliberate opt-outs:
     *   `areas => []`     a page with no chrome at all (the widget preview).
     *                     NOT for a page that merely lists the areas elsewhere:
     *                     this derivation cannot miss one, a hand-written rail
     *                     can — and did (nav 44 on the dashboard).
     *   `railItems`       set by the caller: its rail carries its own data.
     *   `areaName`        a detail page names itself; without it the routed nav
     *                     entry answers, and failing that the page title (the
     *                     profile carries no nav entry of its own).
     */
    private function addAreas(array &$context): void
    {
        $navigation = DI::getInstance()->get('NavigationService');
        $current    = '';
        $areas      = [];
        $rail       = [];

        $meta = [];
        foreach ((array)($context['railMeta'] ?? []) as $target => $text) {
            $meta[strtolower(trim((string)$target))] = (string)$text;
        }

        foreach ($navigation->getBySlot('member-main') as $entry) {
            $active  = $navigation->isActive($entry);
            $areas[] = [
                'name'   => $entry->getName(),
                'url'    => $navigation->urlFor($entry),
                'active' => $active,
            ];
            if ($active) {
                $current = $entry->getName();
                // The area one is standing IN is not a row to go to. The
                // switcher still lists it (marked active) — that panel says
                // where one is, the rail says where one can go.
                continue;
            }

            $target = strtolower(trim($entry->getController() . '/' . $entry->getAction()));
            $text   = $meta[$target] ?? null;
            $rail[] = [
                'name'    => $entry->getName(),
                'url'     => $navigation->urlFor($entry),
                'meta'    => $text,
                // Stacked only WITH a number: an empty second line would be a
                // taller row saying nothing.
                'stacked' => $text !== null,
            ];
        }

        if (!array_key_exists('areas', $context)) {
            $context['areas'] = $areas;
        }

        if (array_key_exists('railMeta', $context)) {
            if (!array_key_exists('railItems', $context)) {
                $context['railItems'] = $rail;
            }
            // Consumed — it never reaches a template, so nobody reads the raw
            // map where the built list is meant.
            unset($context['railMeta']);
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
