<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\AccountDeletion,
    Z77\Module\Member\Services\DeviceKeys,
    Z77\Module\Member\Services\MemberAccounts,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Services\MemberLog,
    Z77\Module\Member\Services\MemberSession,
    Z77\Module\Member\Services\TenantChoice,
    Z77\Module\Member\Services\Totp,
    Z77\Module\Member\Services\TotpSetup,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Persistence\Resolver\DataSourceResolver,
    Z77\Persistence\Resolver\UnifiedEntityManager,
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
            'konto'   => ['name' => 'Konto', 'meta' => $account->getEmail()],
            'zweifa'  => [
                'name' => 'Zwei-Faktor-Schutz',
                'meta' => $account->hasTotp() ? 'aktiv' : 'nicht aktiv',
            ],
            'geraete' => [
                'name' => 'Angemeldete Geräte',
                'meta' => count($devices) === 1 ? '1 Gerät' : count($devices) . ' Geräte',
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
            'pageTitle'    => 'Profil',
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
            // «Konto löschen» — the dialog's id and what the PROJECT wants
            // the person to read before confirming (a tenant left without
            // its owner, an access that ends).
            'deleteDialogId'  => self::DELETE_DIALOG_ID,
            'deletionNotices' => AccountDeletion::noticesFor($account),
            'railItems'    => $rail,
            'crumbs'       => [
                ['label' => 'Profil'],
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
            return ['label' => 'Bearbeiten', 'dialog' => self::ACCOUNT_DIALOG_ID];
        }

        if ($section === 'zweifa') {
            return $hasTotp
                ? null   // removal asks for a code and therefore lives in the form
                : ['label' => 'Jetzt einrichten', 'href' => '/member/main/profile/totp'];
        }

        if ($section === 'geraete' && $hasDevices) {
            return [
                'label'  => 'Alle abmelden',
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

        if (!$request->isPost() || !DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->redirect($back);
        }

        $ref = trim((string)$request->getPostParameter('mandant'));
        $choice = TenantChoice::create();
        if ($choice->choose($account, $ref)) {
            $this->messageService->pushFlashAfterRedirect(
                'success',
                'Sie arbeiten jetzt für «' . $choice->labelFor($account, $ref) . '».'
            );
        } else {
            $this->messageService->pushFlashAfterRedirect('error', 'Diese Verwaltung steht Ihnen nicht zur Wahl.');
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
                ->addFlash('error', 'Ihre Sitzung ist abgelaufen — bitte melden Sie sich neu an.');
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
    protected function kontoAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if (!$request->isPost() || !DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->redirect('/member/main/profile?bereich=konto');
        }

        // Empty stays empty, not a blank string: the entity's fields are
        // nullable and «not stated» is a value the register form allows too.
        $clean = static function (mixed $raw): ?string {
            $value = trim((string)$raw);

            return $value === '' ? null : mb_substr($value, 0, 120);
        };

        $before = [$account->getFirstName(), $account->getLastName(), $account->getCompany()];
        $account->setFirstName($clean($request->getPostParameter('first_name')));
        $account->setLastName($clean($request->getPostParameter('last_name')));
        $account->setCompany($clean($request->getPostParameter('company')));

        $this->accounts()->save($account);
        $this->profileHook()?->__invoke($account);

        // WHICH fields changed, never the values — the log says a name was
        // changed on that day, not what it was.
        $changed = array_keys(array_filter(
            ['first_name' => $before[0] !== $account->getFirstName(), 'last_name' => $before[1] !== $account->getLastName(), 'company' => $before[2] !== $account->getCompany()]
        ));
        if ($changed !== []) {
            MemberLog::write('profile.update', (string)$account->getId(), ['detail' => implode(',', $changed)]);
        }

        $this->messageService->pushFlashAfterRedirect('success', 'Ihre Angaben sind gespeichert.');

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
    protected function loeschenAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if (!$request->isPost() || !DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->redirect('/member/main/profile?bereich=konto');
        }

        $typed     = mb_strtolower(trim((string)$request->getPostParameter('loeschen_bestaetigung')));
        $confirmed = (string)$request->getPostParameter('bestaetigt') === '1';
        if ($typed === '' || $typed !== mb_strtolower(trim((string)$account->getEmail())) || !$confirmed) {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                'Nichts gelöscht: zum Bestätigen tippen Sie Ihre E-Mail-Adresse genau so, wie sie oben steht, und setzen den Haken.'
            );

            return $this->redirect('/member/main/profile?bereich=konto');
        }

        try {
            AccountDeletion::create()->delete($account);
        } catch (\Throwable $e) {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                'Löschen nicht möglich — nichts wurde entfernt: ' . $e->getMessage()
            );

            return $this->redirect('/member/main/profile?bereich=konto');
        }

        (new MemberSession(DI::getSessionManager()))->end();

        return $this->redirect('/member/main/login?konto=geloescht');
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

    /** One device out of the list — POST with its key id. */
    protected function deviceRemoveAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if ($request->isPost()
            && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
            && DeviceKeys::create()->revoke($account, (string)$request->getPostParameter('device'))
        ) {
            $this->messageService->pushFlashAfterRedirect(
                'success',
                'Das Gerät ist abgemeldet — es verlangt beim nächsten Besuch einen neuen Anmelde-Link.'
            );
        } else {
            $this->messageService->pushFlashAfterRedirect('error', 'Dieses Gerät ist nicht (mehr) in der Liste.');
        }

        // Back to WHERE ONE STOOD (the rule from the widget find, 2026-08-15):
        // without ?bereich the page falls back to Konto — the person was in
        // the device list and expects to still be there.
        return $this->redirect('/member/main/profile?bereich=geraete');
    }

    /**
     * «Alle Geräte abmelden» — every device key dies, this one included; the
     * current session stays (the customer is standing on this page).
     */
    protected function deviceRemoveAllAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if ($request->isPost()
            && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
        ) {
            DeviceKeys::create()->revokeAll($account);
            $this->messageService->pushFlashAfterRedirect(
                'success',
                'Alle Geräte sind abgemeldet — jeder Zugang verlangt wieder einen Anmelde-Link.'
            );
        }

        return $this->redirect('/member/main/profile?bereich=geraete');
    }

    /**
     * 2FA setup (B8): GET shows the QR (server-rendered data-URI via the
     * kernel Qr facade) plus the manual-entry key; POST confirms with the
     * app code — only then is 2FA active. A reload re-shows the SAME pending
     * secret (TotpSetup::begin resumes).
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

        if ($request->isPost()) {
            if (DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
                && $setup->confirm($account, (string)$request->getPostParameter('code'))
            ) {
                $this->messageService->pushFlashAfterRedirect(
                    'success',
                    'Zwei-Faktor-Schutz ist aktiv — ab jetzt fragt die Anmeldung nach dem App-Code.'
                );

                // Where one stood: the 2FA section, now showing the new state.
                return $this->redirect('/member/main/profile?bereich=zweifa');
            }
            $error = 'Der Code ist ungültig — bitte scannen Sie den QR-Code und versuchen Sie es erneut.';
        }

        $secret = $setup->begin($account);
        $uri    = Totp::otpauthUri('AXO3', $account->getEmail(), $secret);

        return $this->html([
            'pageTitle' => 'Zwei-Faktor-Schutz einrichten',
            'qrDataUri' => QrCode::pngDataUri($uri, 220),
            'secret'    => trim(chunk_split($secret, 4, ' ')),
            'error'     => $error,
        ]);
    }

    /** Removing 2FA demands a valid app code (spec) — POST only. */
    protected function totpRemoveAction(): RedirectResponse
    {
        $account = MemberAuth::create()->current();
        if ($account === null) {
            return $this->redirect('/member/main/login');
        }

        $request = DI::getRequest();
        if ($request->isPost()
            && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
            && TotpSetup::create()->remove($account, (string)$request->getPostParameter('code'))
        ) {
            $this->messageService->pushFlashAfterRedirect('success', 'Zwei-Faktor-Schutz entfernt.');
        } else {
            $this->messageService->pushFlashAfterRedirect(
                'error',
                'Entfernen fehlgeschlagen — der App-Code ist erforderlich und muss gültig sein.'
            );
        }

        return $this->redirect('/member/main/profile?bereich=zweifa');
    }
}
