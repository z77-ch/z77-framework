<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\RequestMode,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Member\Entities\MemberAccount,
    Z77\Module\Member\Services\AccountDeletion,
    Z77\Module\Member\Services\DeviceKeys,
    Z77\Module\Member\Services\MemberAccounts,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Services\MemberLanguage,
    Z77\Module\Member\Services\MemberLog,
    Z77\Module\Member\Services\MemberSession,
    Z77\Module\Member\Services\MemberTotp,
    Z77\Module\Member\Services\TenantChoice,
    Z77\Module\Member\Services\TotpCodeCheck,
    Z77\Module\Member\Services\TotpSetup,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Persistence\Resolver\DataSourceResolver,
    Z77\Persistence\Resolver\UnifiedEntityManager,
    Z77\Shared\Auth\Totp,
    Z77\Shared\Qr\QrCode
;

/**
 * B8 profile — the session-guarded page: the account and its state (a
 * 'confirmed' account signs in but sees only this page + «wartet auf
 * Freischaltung», B7 decision 4), the 2FA setup, and the list of devices
 * that may stay signed in — each revocable, and all at once. The guard is
 * MemberAuth, not the framework ACL — member sessions are the customer
 * login, the admin login stays untouched.
 */
class ProfileController extends AbstractMemberController
{
    /**
     * The profile as an AREA of the shell (B10 spec v1.4.1): three sections in
     * the rail, one of them shown on the right. It is the same shape as every
     * other area — choose left, read right — and it is what carries the profile
     * when notifications or invoices join it later; one long scroll would not.
     *
     * The area itself has no action, so the action cell carries the SECTION's:
     * «Bearbeiten» belongs to the account, «Jetzt einrichten» to 2FA, «Alle
     * abmelden» to the devices. The last one renders quiet — an action that
     * ends something never wears the accent (B8 security review).
     */
    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $devices = DeviceKeys::create()->listFor($account);
        $request = DI::getRequest();

        $sections = [
            'konto'   => ['name' => t('member.profile.account'), 'meta' => $account->getEmail()],
            'zweifa'  => [
                'name' => t('member.profile.twoFactor'),
                'meta' => t($account->hasTotp() ? 'member.profile.on' : 'member.profile.off'),
            ],
            'geraete' => [
                'name' => t('member.profile.devices'),
                'meta' => self::deviceCount(count($devices)),
            ],
        ];

        // ⚠️ No fourth section. «Zugänge» was one until 2026-09-12 and is an
        // AREA now (ZugaengeController): the accesses belong to the
        // REFERENCE, this page to the PERSON — with a switched session the
        // section listed the home's accounts under a header naming another
        // reference, and nothing on the page said so.

        $section = (string) $request->getGetParameter('bereich');
        if (!array_key_exists($section, $sections)) {
            $section = 'konto';
        }

        $rail = [];
        foreach ($sections as $key => $data) {
            $rail[] = [
                'name'    => $data['name'],
                'meta'    => $data['meta'],
                'url'     => '/member/main/profile?bereich=' . $key,
                'active'  => $key === $section,
                'stacked' => true,
            ];
        }

        return $this->html([
            'pageTitle'    => t('member.shell.profile'),
            'account'      => $account,
            'devices'      => $devices,
            // Every membership the project reports, usable or not — the
            // template names the ones that are NOT open («ruht», «wartet»)
            // with the project's own sentence, so a paused person reads WHY
            // the areas are gone and whom to ask (2026-09-14). The account's
            // own state stays what it is: the pause is the tenant's.
            'memberships'  => \Z77\Module\Member\Services\TenantChoice::create()->memberships($account),
            'section'      => $section,
            'dialogId'     => self::ACCOUNT_DIALOG_ID,
            // ADR-056: the languages the account may choose; empty = no field.
            'languages'    => MemberLanguage::offered(),
            // «Konto löschen» — the dialog's id and what the PROJECT wants
            // the person to read before confirming (a tenant left without
            // its owner, an access that ends).
            'deleteDialogId'  => self::DELETE_DIALOG_ID,
            'deletionNotices' => AccountDeletion::noticesFor($account),
            'railItems'    => $rail,
            'crumbs'       => [
                ['label' => t('member.shell.profile')],
                ['label' => $sections[$section]['name'], 'here' => true],
            ],
            'shellAction' => $this->sectionAction($section, $account->hasTotp(), $devices !== []),
            // A section is always chosen, so on a narrow screen the detail is
            // what one came for — it opens, and `‹ Liste` goes back.
            'detailOpen'  => true,
        ]);
    }

    /**
     * The action of the chosen section, or none.
     *
     * @return array{label:string, href:string, method?:string, quiet?:bool}|null
     */
    private function sectionAction(string $section, bool $hasTotp, bool $hasDevices): ?array
    {
        if ($section === 'konto') {
            // Opens the dialog on the page — no second route, no fragment: the
            // two fields are already here, and a form the size of a business
            // card does not need a page of its own.
            return ['label' => t('member.profile.edit'), 'dialog' => self::ACCOUNT_DIALOG_ID];
        }

        if ($section === 'zweifa') {
            return $hasTotp
                ? null   // removal asks for a code and therefore lives in the form
                : ['label' => t('member.profile.setUp'), 'href' => '/member/main/profile/totp'];
        }

        if ($section === 'geraete' && $hasDevices) {
            return [
                'label'  => t('member.profile.signOutAll'),
                'href'   => '/member/main/profile/device-remove-all',
                'method' => 'post',
                'quiet'  => true,
            ];
        }

        return null;
    }

    /**
     * The tenant choice (ADR-037): which of the granted tenants this session
     * works for. A POST from the header's switcher, checked against the
     * available set by TenantChoice::choose() BEFORE it lands in the session —
     * the working requests afterwards read only the session, never a
     * parameter. It lives on the profile controller for the same reason the
     * theme does: it is a setting of the signed-in person, and the header is
     * only where one reaches it.
     *
     * Lands the person back where they stood (`back`, the page the switcher
     * was on) — own paths only, so a forged form cannot send anyone off-site.
     */
    #[HttpMethod('POST')]
    protected function mandantAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        $back    = (string)$request->getPostParameter('back');
        if ($back === '' || $back[0] !== '/' || str_starts_with($back, '//')) {
            $back = '/member/main/profile';
        }

        if (!DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->redirect($back);
        }

        $ref = trim((string)$request->getPostParameter('mandant'));
        $choice = TenantChoice::create();
        if ($choice->choose($account, $ref)) {
            $this->messageService->pushFlashAfterRedirect(
                'success',
                t('member.profile.tenantChosen', ['tenant' => $choice->labelFor($account, $ref)])
            );
        } else {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                t('member.profile.tenantRefused', \Z77\Module\Member\Services\TenantNoun::current())
            );
        }

        return $this->redirect($back);
    }

    /**
     * Hell/dunkel — the one setting the shell's header can change without
     * leaving the page. It answers as a fetch envelope like every other
     * immediate switch in this stack (spec 1.3.1): the display has already
     * moved when the request goes out, and springs back if the server refuses.
     *
     * It lives on the PROFILE controller because that is what it is — an
     * account setting; the header is only the place it is reachable from. Any
     * value other than the two known ones clears the choice back to «system»
     * (the entity's setter decides), so this action cannot be talked into an
     * invalid state.
     */
    #[Fetch, HttpMethod('POST')]
    protected function themeAction(): FetchResponse
    {
        $response = new FetchResponse();
        $account  = MemberAuth::create()->current();

        if ($account === null) {
            return $response->setStatus('error')
                ->addFlash('error', t('member.profile.sessionExpired'));
        }

        // No CSRF check here: `#[Fetch]` makes this action reachable in Fetch
        // mode only, and `AccessGuard` validates the `X-CSRF-Token` header for
        // every Fetch POST before any controller runs (forms.md,
        // CONTACT-CHECK-001). A second check in the action would only be a
        // second place to get it wrong.
        $wanted = (string) (DI::getRequest()->getJsonBody()['theme'] ?? '');
        $account->setTheme($wanted === '' ? null : $wanted);
        $this->accounts()->save($account);

        return $response->setData(['theme' => $account->getTheme() ?? '']);
    }

    /**
     * The id of the account dialog. Named once here, handed to the action cell
     * AND to the template — a literal in two files is a button that stops
     * opening the day one of them is renamed.
     */
    private const ACCOUNT_DIALOG_ID = 'me-konto-dialog';
    /** The id of the deletion dialog («Konto löschen»). */
    private const DELETE_DIALOG_ID = 'me-konto-loeschen';

    /**
     * The two fields of the account a customer may change himself: the NAME,
     * and the COMPANY.
     *
     * What is deliberately not here: the e-mail address. It IS the access — a
     * typo locks the account out —, so moving it needs a confirmation through
     * the NEW address, which is B7's path and not a text field.
     *
     * The company is here since 2026-08-12 (Peter) — and since ADR-038 it is
     * the PERSON's: where she works, hers to edit, copied ONCE into the
     * tenant's name at activation and independent of it afterwards. The
     * `profileHook` still lets a project react to a profile change; a
     * project that renamed its tenant from here re-created the very
     * conflation this ADR removed, and stopped.
     */
    #[HttpMethod('POST')]
    protected function kontoAction(): RedirectResponse|FetchResponse
    {
        $request = DI::getRequest();
        $isFetch = $request->getMode() === RequestMode::Fetch;

        $account = MemberAuth::create()->current();
        if ($account === null) {
            // A fetch cannot follow a page redirect into the shell — it is told where to go.
            return $isFetch
                ? $this->fetch()->setRedirect('/member/main/login')
                : $this->redirect('/member/main/login');
        }

        // Two transports (MEMBER-FORM-ACTIONS-001, 2026-10-10): the dialog posts
        // by fetch (JSON body; CSRF is AccessGuard's Fetch gate, as in
        // themeAction), and without script as a page (form body + csrf_token).
        if ($isFetch) {
            $body  = $request->getJsonBody();
            $input = static fn(string $key): mixed => $body[$key] ?? null;
        } else {
            if (!DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
                return $this->redirect('/member/main/profile?bereich=konto');
            }
            $input = static fn(string $key): mixed => $request->getPostParameter($key);
        }

        // Empty stays empty, not a blank string: the entity's fields are
        // nullable and «not stated» is a value the register form allows too.
        $clean = static function (mixed $raw): ?string {
            $value = trim((string)$raw);

            return $value === '' ? null : mb_substr($value, 0, 120);
        };

        $before = [$account->getFirstName(), $account->getLastName(), $account->getCompany(), $account->getLanguage()];
        $account->setFirstName($clean($input('first_name')));
        $account->setLastName($clean($input('last_name')));
        $account->setCompany($clean($input('company')));

        // ADR-056: the language, only where the profile offers one, and only an offered
        // one. The default is stored as «none» (null), so the account follows the
        // installation's default until the person picks another.
        $offered = MemberLanguage::offered();
        $wanted  = (string)$input('language');
        if ($offered !== [] && in_array($wanted, $offered, true)) {
            $account->setLanguage(MemberLanguage::storable($wanted));
        }

        $this->accounts()->save($account);
        $this->profileHook()?->__invoke($account);

        // WHICH fields changed, never the values — the log says a name was
        // changed on that day, not what it was.
        $languageChanged = $before[3] !== $account->getLanguage();
        $changed = array_keys(array_filter(
            ['first_name' => $before[0] !== $account->getFirstName(), 'last_name' => $before[1] !== $account->getLastName(), 'company' => $before[2] !== $account->getCompany(), 'language' => $languageChanged]
        ));
        if ($changed !== []) {
            MemberLog::write('profile.update', (string)$account->getId(), ['detail' => implode(',', $changed)]);
        }

        // A new language changes every text of the page, not just the values: the
        // page is rendered again (in the new language — the next request reads it
        // from the account), instead of swapping the value list in place.
        if ($languageChanged) {
            // In the NEW language: the page that shows it is rendered in it.
            $this->messageService->pushFlashAfterRedirect('success', t('member.profile.saved', [], MemberLanguage::of($account)));

            return $isFetch
                ? $this->fetch()->setRedirect('/member/main/profile?bereich=konto')
                : $this->redirect('/member/main/profile?bereich=konto');
        }

        if ($isFetch) {
            // What changed is the pane's values — the same partial the page
            // renders — and the dialog closes (`close-modal` falls back to the
            // open page `<dialog>` where the host has no popup). Nothing here
            // can be refused field by field: every field is optional and is
            // cut to 120 characters, so there is no error state to re-render.
            $this->messageService->pushFlash('success', t('member.profile.saved'));
            $fields = (new TemplateRenderer('Z77\\Module\\Member'))->partial('Main/ProfileController/_kontoFields', [
                'account'     => $account,
                'memberships' => TenantChoice::create()->memberships($account),
            ]);

            return $this->fetch()
                ->addCommand('replace-html', ['target' => '[data-konto-fields]', 'html' => $fields])
                ->addCommand('close-modal', []);
        }

        $this->messageService->pushFlashAfterRedirect('success', t('member.profile.saved'));

        return $this->redirect('/member/main/profile?bereich=konto');
    }

    /**
     * The person deletes her own account (2026-09-14). Two confirmations
     * inside the dialog — the address typed again and a checkbox — because
     * there is no password to ask for. The project is asked first
     * ({@see AccountDeletion}); when it refuses, nothing has changed and the
     * page says so. Afterwards the session ends and the login page reports
     * the deletion ONCE, through the query — the flash would die with the
     * session that carried it.
     */
    #[HttpMethod('POST')]
    protected function loeschenAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if (!DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->redirect('/member/main/profile?bereich=konto');
        }

        $typed     = mb_strtolower(trim((string)$request->getPostParameter('loeschen_bestaetigung')));
        $confirmed = (string)$request->getPostParameter('bestaetigt') === '1';
        if ($typed === '' || $typed !== mb_strtolower(trim((string)$account->getEmail())) || !$confirmed) {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                t('member.profile.deleteUnconfirmed')
            );

            return $this->redirect('/member/main/profile?bereich=konto');
        }

        try {
            AccountDeletion::create()->delete($account);
        } catch (\Throwable $e) {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                t('member.profile.deleteRefused', ['reason' => $e->getMessage()])
            );

            return $this->redirect('/member/main/profile?bereich=konto');
        }

        (new MemberSession(DI::getSessionManager()))->end();

        // The person is a guest now; the door keeps her language through the URL (ADR-056).
        return $this->redirect(localizedUrl('/member/main/login?konto=geloescht', DI::getTranslator()->currentLanguage()));
    }

    /**
     * The project side of a profile change — same seam shape as
     * `activationHook`: the config names an invokable class, the module never
     * learns what a project does with it.
     */
    private function profileHook(): ?object
    {
        $fqcn = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
            ->get('profileHook', '');

        return $fqcn !== '' && class_exists($fqcn) ? new $fqcn() : null;
    }

    /** File-store wiring, same shape the other member services use. */
    private function accounts(): MemberAccounts
    {
        return new MemberAccounts(new UnifiedEntityManager(new DataSourceResolver(['file' => 'File'])));
    }

    /**
     * One device out of the list — POST with its key id.
     *
     * Two transports, one rule set (MEMBER-FORM-ACTIONS-001, 2026-10-10): the
     * row's form posts by fetch and the answer is what changed — this row
     * gone, the count above the list and in the rail (ADR-047 addendum: a
     * one-row change answers with the row, not `reload`). The LAST device is
     * the exception: the list turns into its empty state and «Alle abmelden»
     * leaves the action cell — that is the page, so the answer goes there.
     * Without script the form posts as a page and lands back in the list.
     */
    #[HttpMethod('POST')]
    protected function deviceRemoveAction(): RedirectResponse|FetchResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            // A fetch cannot follow a page redirect into the shell — it is told where to go.
            return DI::getRequest()->getMode() === RequestMode::Fetch
                ? $this->fetch()->setRedirect('/member/main/login')
                : $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if ($request->getMode() === RequestMode::Fetch) {
            return $this->deviceRemoveInPlace($account);
        }
        if (DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
            && DeviceKeys::create()->revoke($account, (string)$request->getPostParameter('device'))
        ) {
            $this->messageService->pushFlashAfterRedirect(
                'success',
                t('member.profile.deviceRemoved')
            );
        } else {
            $this->messageService->pushFlashAfterRedirect('error', t('member.profile.deviceUnknown'));
        }

        // Back to WHERE ONE STOOD (the rule from the widget find, 2026-08-15):
        // without ?bereich the page falls back to Konto — the person was in
        // the device list and expects to still be there.
        return $this->redirect('/member/main/profile?bereich=geraete');
    }

    /**
     * The fetch half of {@see deviceRemoveAction}. No CSRF field check: a Fetch
     * POST passes `AccessGuard`'s `X-CSRF-Token` gate before any controller
     * runs (same reasoning as {@see themeAction}).
     */
    private function deviceRemoveInPlace(MemberAccount $account): FetchResponse
    {
        $device  = (string) (DI::getRequest()->getJsonBody()['device'] ?? '');
        $devices = DeviceKeys::create();

        if (!$devices->revoke($account, $device)) {
            $this->messageService->pushFlash('error', t('member.profile.deviceUnknown'));

            return $this->fetch()->setStatus('error');
        }

        $left = count($devices->listFor($account));
        if ($left === 0) {
            $this->messageService->pushFlashAfterRedirect(
                'success',
                t('member.profile.deviceRemoved')
            );

            return $this->fetch()->setRedirect('/member/main/profile?bereich=geraete');
        }

        $this->messageService->pushFlash(
            'success',
            t('member.profile.deviceRemoved')
        );

        // The id is one the account held (revoke() found it) — hex, so it is
        // safe inside the attribute selector.
        return $this->fetch()
            ->removeRow('device', $device)
            ->addCommand('update-text', [
                'target' => '[data-device-count]',
                'text'   => self::devicesStaying($left),
            ])
            ->addCommand('update-text', [
                'target' => 'a.me-item[href="/member/main/profile?bereich=geraete"] .me-item__meta',
                'text'   => self::deviceCount($left),
            ]);
    }

    /**
     * «Alle Geräte abmelden» — every device key dies, this one included; the
     * current session stays (the customer is standing on this page).
     */
    #[HttpMethod('POST')]
    protected function deviceRemoveAllAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        if (DI::getCsrfService()->validate((string)DI::getRequest()->getPostParameter('csrf_token'))) {
            DeviceKeys::create()->revokeAll($account);
            $this->messageService->pushFlashAfterRedirect(
                'success',
                t('member.profile.allRemoved')
            );
        }

        return $this->redirect('/member/main/profile?bereich=geraete');
    }

    /**
     * 2FA setup (B8): GET shows the QR (server-rendered data-URI via the
     * kernel Qr facade) plus the manual-entry key, with a NEW secret on every
     * open, held in the session as ciphertext (TotpSetup); POST confirms with
     * the app code — only then does anything reach the store. A wrong code
     * re-shows the same QR, so nobody has to scan twice for a typo.
     */
    protected function totpAction(): HtmlResponse|RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }
        if ($account->hasTotp()) {
            return $this->redirect('/member/main/profile?bereich=zweifa');
        }

        $setup   = TotpSetup::create();
        $request = DI::getRequest();
        $error   = '';

        $secret  = null;

        if ($request->isPost()) {
            if (DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
                // Dead counters refuse the right code too: switching on would lock the person out.
                if (!$setup->canCheckCodes($account)) {
                    $this->messageService->pushFlashAfterRedirect('error', t('member.totp.unavailable'));

                    return $this->redirect('/member/main/profile?bereich=zweifa');
                }
                if ($setup->confirm($account, (string)$request->getPostParameter('code'))) {
                    $this->messageService->pushFlashAfterRedirect(
                        'success',
                        t('member.profile.totpOn')
                    );

                    // Where one stood: the 2FA section, now showing the new state.
                    return $this->redirect('/member/main/profile?bereich=zweifa');
                }
            }
            $error  = t('member.profile.totpSetupWrong');
            $secret = $setup->running($account);
        }

        try {
            $secret ??= $setup->begin($account);
        } catch (\RuntimeException $e) {
            // A damaged key file (TotpVault never overwrites one): logged there, nothing to set up.
            error_log('ProfileController: 2FA setup refused — ' . $e->getMessage());
            $this->messageService->pushFlashAfterRedirect(
                'error',
                t('member.profile.totpSetupBroken')
            );

            return $this->redirect('/member/main/profile?bereich=zweifa');
        }
        $uri    = Totp::otpauthUri(MemberTotp::issuer(), $account->getEmail(), $secret);

        return $this->html([
            'pageTitle' => t('member.totpSetup.title'),
            'qrDataUri' => QrCode::pngDataUri($uri, 220),
            'secret'    => trim(chunk_split($secret, 4, ' ')),
            'error'     => $error,
        ]);
    }

    /**
     * Removing 2FA demands a valid app code (spec) — POST only.
     *
     * By fetch (MEMBER-FORM-ACTIONS-001, 2026-10-10): a wrong code answers in
     * place — a flash, the page and the typed code stay. A removal changes
     * the section, the rail's «aktiv» and the action cell («Jetzt
     * einrichten»), i.e. the page — so it answers with the page. Without
     * script the form posts as a page, as before.
     */
    #[HttpMethod('POST')]
    protected function totpRemoveAction(): RedirectResponse|FetchResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            // A fetch cannot follow a page redirect into the shell — it is told where to go.
            return DI::getRequest()->getMode() === RequestMode::Fetch
                ? $this->fetch()->setRedirect('/member/main/login')
                : $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if ($request->getMode() === RequestMode::Fetch) {
            // CSRF: the Fetch gate in AccessGuard, as in themeAction.
            $code    = (string) ($request->getJsonBody()['code'] ?? '');
            $outcome = TotpSetup::create()->removeWithCode($account, $code);
            if ($outcome !== TotpCodeCheck::PASSED) {
                $this->messageService->pushFlash('error', self::totpRemoveRefusal($outcome));

                return $this->fetch()->setStatus('error');
            }
            $this->messageService->pushFlashAfterRedirect('success', t('member.profile.totpOff'));

            return $this->fetch()->setRedirect('/member/main/profile?bereich=zweifa');
        }
        $outcome = DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
            ? TotpSetup::create()->removeWithCode($account, (string)$request->getPostParameter('code'))
            : TotpCodeCheck::WRONG;
        if ($outcome === TotpCodeCheck::PASSED) {
            $this->messageService->pushFlashAfterRedirect('success', t('member.profile.totpOff'));
        } else {
            $this->messageService->pushFlashAfterRedirect('error', self::totpRemoveRefusal($outcome));
        }

        return $this->redirect('/member/main/profile?bereich=zweifa');
    }

    /** «1 Gerät» / «3 Geräte» — the rail's line under «Angemeldete Geräte». */
    private static function deviceCount(int $n): string
    {
        return $n === 1 ? t('member.profile.deviceOne') : t('member.profile.deviceMany', ['count' => $n]);
    }

    /** The headline of the device list: how many stay signed in. */
    public static function devicesStaying(int $n): string
    {
        return match (true) {
            $n === 0 => t('member.profile.stayingNone'),
            $n === 1 => t('member.profile.stayingOne'),
            default  => t('member.profile.stayingMany', ['count' => $n]),
        };
    }

    /** What the person is told when a code did not remove 2FA. */
    private static function totpRemoveRefusal(string $outcome): string
    {
        return match ($outcome) {
            TotpCodeCheck::LOCKED      => t('member.totp.locked'),
            TotpCodeCheck::UNAVAILABLE => t('member.totp.unavailable'),
            default                    => t('member.profile.totpRemoveFailed'),
        };
    }
}
