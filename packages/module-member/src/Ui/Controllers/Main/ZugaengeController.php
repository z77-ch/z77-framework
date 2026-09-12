<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Entities\MemberAccount,
    Z77\Module\Member\Services\InvitationFlow,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController
;

/**
 * «Zugänge» — who may work for THIS reference: the accounts whose home it
 * is, the grants on it, the open invitations; invite, withdraw, pause,
 * remove. An AREA of the shell (nav slot `member-main`), not a section of
 * the profile.
 *
 * ── Why it moved out of the profile (2026-09-12) ──
 * Until ADR-037 one account meant one reference, and «my accesses» and «my
 * profile» were the same page. Since then the header can be switched to a
 * GRANTED reference while the invitation right stays with the HOME
 * (`InvitationFlow::tenantRefOf()`) — so the profile's section listed the
 * home's accounts under a header naming another reference, and nothing on
 * the page said so. The accesses belong to the reference, like the stock and
 * the widgets do; the profile keeps what belongs to the PERSON (account, 2FA,
 * devices). Found by Peter on 2026-09-12 with two references in the switcher.
 *
 * ── When it exists ──
 * «Not present, not forbidden» (B10 v1.6.0), extended by the choice: the area
 * is there exactly when the session's choice IS the home and the account is
 * its master ({@see InvitationFlow::managesHere()}). A guest on a granted
 * reference sees no entry, and a direct request answers silently with the
 * member entry page — nothing about another reference is revealed.
 * `AbstractMemberController::addAreas()` drops the nav entry by the same
 * predicate, so the switcher and the rail never promise a page that answers
 * with a redirect.
 *
 * ── What the handgrips read ──
 * The four writes ask `mayManage()` and act on the HOME (the flow reads
 * `tenantRefOf()`), not on the choice: a POST from a page that showed the
 * home must hit the home, even if another tab switched the session meanwhile.
 * Visibility follows the choice; ownership never does (ADR-037).
 */
class ZugaengeController extends AbstractMemberController
{
    /**
     * The routing identity of this area, as the navigation stores it
     * (`controller/action`, lower-case) — what `addAreas()` compares against.
     * NOT `Navigation::$key`: that one is server-controlled (ADR-032) and null
     * on every entry a human creates in the backend.
     */
    public const AREA = 'zugaenge/index';

    private const URL = '/member/main/zugaenge';

    /** The id of the invitation dialog — named once, used by cell and template. */
    private const INVITE_DIALOG_ID = 'me-einladen-dialog';

    /**
     * Two sections in the rail, one shown on the right — the shape of every
     * area: WHO hangs on the reference (accounts and grants), and the
     * invitations still open. «Einladen» is the area's action and sits in
     * the action cell on both.
     */
    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $account = $this->master();
        if ($account === null) {
            return $this->redirect($this->entryUrl());
        }

        $context = $this->zugaengeContext($account);
        $request = DI::getRequest();

        $sections = [
            'konten' => [
                'name' => 'Konten',
                'meta' => count($context['accounts']) === 1
                    ? '1 Konto'
                    : count($context['accounts']) . ' Konten',
            ],
            'einladungen' => [
                'name' => 'Offene Einladungen',
                'meta' => count($context['invites']) === 1
                    ? '1 offen'
                    : count($context['invites']) . ' offen',
            ],
        ];

        $section = (string)$request->getGetParameter('bereich');
        if (!array_key_exists($section, $sections)) {
            $section = 'konten';
        }

        $rail = [];
        foreach ($sections as $key => $data) {
            $rail[] = [
                'name'    => $data['name'],
                'meta'    => $data['meta'],
                'url'     => self::URL . '?bereich=' . $key,
                'active'  => $key === $section,
                'stacked' => true,
            ];
        }

        return $this->html([
            'pageTitle'    => 'Zugänge',
            'account'      => $account,
            'section'      => $section,
            'tenantName'   => $this->invites()->tenantLabelFor((string)$account->getTenantRef()),
            'zugaenge'     => $context,
            'inviteDialog' => self::INVITE_DIALOG_ID,
            'railItems'    => $rail,
            'crumbs'       => [
                ['label' => 'Zugänge'],
                ['label' => $sections[$section]['name'], 'here' => true],
            ],
            // A dialog like the account's: one field, already on the page — a
            // route of its own would be a page for an address box.
            'shellAction'  => ['label' => 'Einladen', 'dialog' => self::INVITE_DIALOG_ID],
            // A section is always chosen, so on a narrow screen the detail is
            // what one came for — it opens, and `‹ Liste` goes back.
            'detailOpen'   => true,
        ]);
    }

    /**
     * The two lists: who hangs on the reference, and which invitations are
     * still open.
     *
     * @return array{accounts: list<array<string,mixed>>, invites: list<array<string,mixed>>}
     */
    private function zugaengeContext(MemberAccount $master): array
    {
        $invites = $this->invites();
        $rows    = [];

        foreach ($invites->accountsOf($master) as $account) {
            $rows[] = [
                'id'        => (string)$account->getId(),
                'email'     => $account->getEmail(),
                'name'      => trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? '')),
                'master'    => $account->isMaster(),
                'suspended' => $account->isSuspended(),
                // A confirmed account is one we have not activated yet — the
                // master should see that the wait is OURS, not his.
                'waiting'   => !$account->isActive(),
                'grant'     => false,
            ];
        }

        // The grants ON this reference (ADR-037): people whose account lives
        // at another reference and who may work here too. Same row shape,
        // same two handgrips — the id is the GRANT's, so pause/remove hit the
        // permission and never the person.
        foreach ($invites->grantsOf($master) as $row) {
            $rows[] = [
                'id'        => (string)$row['grant']->getId(),
                'email'     => $row['account']->getEmail(),
                'name'      => trim(($row['account']->getFirstName() ?? '') . ' ' . ($row['account']->getLastName() ?? '')),
                'master'    => false,
                'suspended' => $row['grant']->isSuspended(),
                'waiting'   => !$row['grant']->isActive(),
                'grant'     => true,
            ];
        }

        $open = [];
        foreach ($invites->openInvites($master) as $token) {
            $open[] = [
                'id'    => (int)$token->getId(),
                'email' => (string)$token->getEmail(),
                'until' => (string)$token->getValidUntil(),
            ];
        }

        return ['accounts' => $rows, 'invites' => $open];
    }

    /**
     * The master behind the request — WITH the session standing on his home —
     * or null when this account has no business here right now. The rule
     * itself lives in InvitationFlow, so a forgotten guard in a controller
     * cannot grant anything.
     *
     * ⚠️ The spec says «not present, not forbidden», and a 404 would say that
     * best — but this framework has no controller-level 404: the Bootstrap
     * catches FileNotFoundException around ROUTING only, so throwing one from
     * an action produces a 500. So this area does what the rest of the stack
     * does when someone cannot be where he is: it answers silently with the
     * member entry page. Nothing is shown, nothing is said, and nothing about
     * another reference is revealed.
     */
    private function master(): ?MemberAccount
    {
        $account = MemberAuth::create()->current();

        return $this->managesHere($account) ? $account : null;
    }

    /**
     * The master for a WRITE: signed in and owner of a home. The choice is not
     * asked here on purpose — the write acts on the home (the flow reads
     * `tenantRefOf()`), and a page that showed the home must be able to
     * finish what it started even if another tab switched the session.
     */
    private function writer(): ?MemberAccount
    {
        $account = MemberAuth::create()->current();

        return $account !== null && $this->invites()->mayManage($account) ? $account : null;
    }

    /** Where a request without business here lands: the member entry page. */
    private function entryUrl(): string
    {
        $url = (string)DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', self::NAMESPACE)
            ->get('afterLoginUrl', '');

        return $url !== '' && $url[0] === '/' && !str_starts_with($url, '//') ? $url : '/member/main/profile';
    }

    // ── the four handgrips ─────────────────────────────────────────────────

    protected function einladenAction(): RedirectResponse
    {
        $account = $this->writer();
        if ($account === null) {
            return $this->redirect($this->entryUrl());
        }

        $request = DI::getRequest();

        if (!$request->isPost() || !DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            return $this->redirect(self::URL . '?bereich=einladungen');
        }

        $email   = (string)$request->getPostParameter('email');
        $outcome = $this->invites()->invite($account, $email);

        // ⚠️ «Diese Adresse ist bereits einem Mandanten zugeordnet» is a
        // MESSAGE, not an error (B7 v1.1.0 / B10 v1.6.0): the master did
        // nothing wrong, and for US it is the signal that one human is to work
        // for a second tenant. Painting it red would file it as a mistake.
        //
        // ⚠️ But `info` was the wrong shelf: core.js AUTO-DISMISSES success and
        // info after 5 s, so the one outcome that changes nothing and needs a
        // decision was the one that vanished by itself — pale, at the top edge,
        // gone before the eye came back from the form (Peter, 2026-08-14, on
        // cyon: «fällt nicht auf»). `warning` is the shelf that STAYS until it
        // is closed, and amber says «look at this» without saying «you did
        // something wrong».
        [$type, $text] = match ($outcome) {
            InvitationFlow::SENT => ['success',
                'Die Einladung ist unterwegs an ' . $email . '.'],
            InvitationFlow::ALREADY_TAKEN => ['warning',
                'Keine Einladung verschickt: ' . $email . ' ist bereits einem Mandanten zugeordnet — '
                . 'es entsteht kein zweites Konto. '
                . 'Melden Sie sich bei uns, wenn diese Person für Sie arbeiten soll.'],
            InvitationFlow::THROTTLED => ['error',
                'Für heute sind genug Einladungen verschickt. Morgen geht es weiter.'],
            default => ['error', 'Diese E-Mail-Adresse können wir nicht verwenden.'],
        };

        $this->messageService->pushFlashAfterRedirect($type, $text);

        // Land where the result is: an open invitation appears in the second
        // section, so that is where the sender is taken.
        return $this->redirect(self::URL . '?bereich=einladungen');
    }

    /** The master withdraws an open invitation. */
    protected function einladungWiderrufenAction(): RedirectResponse
    {
        $account = $this->writer();
        if ($account === null) {
            return $this->redirect($this->entryUrl());
        }

        $request = DI::getRequest();

        if ($request->isPost()
            && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
            && $this->invites()->revoke($account, (int)$request->getPostParameter('einladung'))
        ) {
            $this->messageService->pushFlashAfterRedirect(
                'success',
                'Die Einladung ist zurückgezogen — ihr Link wirkt nicht mehr.'
            );
        } else {
            $this->messageService->pushFlashAfterRedirect('error', 'Diese Einladung ist nicht (mehr) offen.');
        }

        return $this->redirect(self::URL . '?bereich=einladungen');
    }

    /**
     * Pausing is the immediate switch of this stack (spec 1.3.1): the display
     * has already moved when the request goes out and springs back if the
     * server refuses. Deleting a person stays POST + confirm — see below.
     */
    #[Fetch, HttpMethod('POST')]
    protected function zugangPausierenAction(): FetchResponse
    {
        $response = new FetchResponse();
        $account  = MemberAuth::create()->current();

        // ⚠️ Two different refusals, and they must not share a sentence: nobody
        // signed in means the session is over and saying so is the help the
        // customer needs. A signed-in NON-master reaching this endpoint (his
        // page never renders the switch) is told nothing about why — «your
        // session expired» would simply be a lie, and the first draft of this
        // action told it.
        if ($account === null) {
            return $response->setStatus('error')
                ->addFlash('error', 'Ihre Sitzung ist abgelaufen — bitte melden Sie sich neu an.');
        }
        if (!$this->invites()->mayManage($account)) {
            return $response->setStatus('error')
                ->addFlash('error', 'Diese Änderung ist nicht möglich.');
        }

        $body   = DI::getRequest()->getJsonBody();
        $paused = (bool)($body['paused'] ?? false);

        if (!$this->invites()->pause($account, (string)($body['id'] ?? ''), $paused)) {
            return $response->setStatus('error')
                ->addFlash('error', 'Dieses Konto lässt sich hier nicht ändern.');
        }

        return $response->setData(['paused' => $paused])->addFlash(
            'success',
            $paused
                ? 'Der Zugang ruht. Konto, Zwei-Faktor-Schutz und Geräte bleiben bestehen.'
                : 'Der Zugang ist wieder offen.'
        );
    }

    /**
     * Removing an account deletes a person, not a state — POST with a
     * confirmation. Removing a GRANT (same route, the id says which) deletes
     * only the permission; the person keeps his account at his own tenant,
     * and the flash has to say so — «das Konto ist entfernt» would be a lie.
     */
    protected function zugangEntfernenAction(): RedirectResponse
    {
        $account = $this->writer();
        if ($account === null) {
            return $this->redirect($this->entryUrl());
        }

        $request = DI::getRequest();
        $removed = null;

        if ($request->isPost()
            && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))
        ) {
            $removed = $this->invites()->remove($account, (string)$request->getPostParameter('konto'));
        }

        [$type, $text] = match ($removed) {
            InvitationFlow::REMOVED_ACCOUNT => ['success',
                'Das Konto ist entfernt. Ihr Bestand und die übrigen Zugänge sind unberührt.'],
            InvitationFlow::REMOVED_GRANT => ['success',
                'Der Zugang ist entfernt. Das Konto dieser Person bleibt bei ihrer eigenen Verwaltung bestehen.'],
            default => ['error', 'Dieser Zugang lässt sich hier nicht entfernen.'],
        };
        $this->messageService->pushFlashAfterRedirect($type, $text);

        return $this->redirect(self::URL . '?bereich=konten');
    }
}
