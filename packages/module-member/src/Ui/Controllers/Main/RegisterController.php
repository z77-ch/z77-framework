<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Entities\MemberAccount,
    Z77\Module\Member\Services\InvitationFlow,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Module\Member\Ui\Form\InviteFormDefinition,
    Z77\Module\Member\Ui\Form\RegisterFormDefinition,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler
;

/**
 * B7 registration page (public-form standard: definition + handler own the
 * whole cascade — CSRF, bot checks, validation, session rate limit, PRG).
 *
 * The onValid callback hands the validated form to RegistrationFlow: per-
 * address throttle, then account + confirmation mail OR the existing-account
 * mail. Its true/false feeds straight back into the handler contract, so the
 * neutral answer (danke page) covers new and existing addresses alike — the
 * page cannot be used to probe which addresses have accounts.
 *
 * ⚠️ The same route carries the REDEMPTION of an invitation (B7 v1.1.0):
 * `?invite=…` switches to a second, shorter form. One route, because it is the
 * same act — a person gets an account — and the mail link the spec names is
 * this one. The two differ in what the visitor may state: an invited person
 * states his name, nothing else. The address comes from the token and the
 * project reference is already known, so there is no address field and no
 * company field to fill in or to tamper with.
 */
class RegisterController extends AbstractMemberController
{
    use PublicFormCheckTrait;

    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        $invite = trim((string) DI::getRequest()->getGetParameter('invite'));

        return $invite !== '' ? $this->redeemInvitation($invite) : $this->openRegistration();
    }

    private function openRegistration(): HtmlResponse|RedirectResponse
    {
        $this->layoutManager->addJs('public-form', 'Z77\\Module\\Frontend', 'footer', true);

        // WHICH offer was clicked. It survives the submit without a hidden
        // field and without a session: the form carries no `action`, so the
        // POST goes to the very URL that opened it, query string included.
        // A hidden field would have to be whitelisted in the FormDefinition and
        // would be no more trustworthy — this value is a hint for us, never a
        // permission (MemberAccount::normalizeOrigin() takes the sharp edges
        // off it).
        $origin = MemberAccount::normalizeOrigin(
            (string) DI::getRequest()->getGetParameter('via')
        );

        $form = PublicFormHandler::create(new RegisterFormDefinition())
            // Country rule + form log in one switch. Every submit leaves a
            // line, including the ones the visitor never notices (bot, limit,
            // stale token) — those are exactly the ones an operator needs to
            // see: a clean registration says little, a burst of refused ones
            // says where something is coming from. `origin` rides on every
            // line so the log also answers WHICH offer link the attempts use.
            ->withGeoGuard(extra: ['origin' => $origin]);

        $onValid = function ($valid) use ($origin): bool {
            return $this->flow()->register(
                (string)$valid->get('email'),
                (string)$valid->get('company') ?: null,
                (string)$valid->get('first_name') ?: null,
                (string)$valid->get('last_name') ?: null,
                null,
                $origin,
                $valid->get('terms') ? $this->termsVersion() : null,
            );
        };

        if ($form->process($onValid)) {
            return $this->redirect('/member/main/register/danke'
                . ($origin !== null ? '?via=' . $origin : ''));
        }

        return $this->html([
            'pageTitle'  => 'Registrieren',
            'invite'     => null,
            'origin'     => $origin,
            'originNote' => $this->originNote($origin),
        ] + $form->viewContext());
    }

    /**
     * The invitation branch. The token is inspected, never consumed, until the
     * form is actually submitted — a link opened and abandoned must still work
     * (a mail gets opened twice, on the phone and at the desk).
     *
     * A dead link — unknown, used, withdrawn or expired — gets ONE page, and
     * that page points at the person who invited, NOT at a resend: only the
     * master may renew an invitation, otherwise the recipient keeps his own
     * access to the reference alive.
     */
    private function redeemInvitation(string $plainToken): HtmlResponse|RedirectResponse
    {
        $invites = $this->invites();
        $token   = $invites->inspect($plainToken);

        if ($token === null) {
            return $this->html([
                'pageTitle'  => 'Einladung',
                'invite'     => ['dead' => true],
            ] + $this->emptyFormContext());
        }

        $this->layoutManager->addJs('public-form', 'Z77\\Module\\Frontend', 'footer', true);

        $form    = PublicFormHandler::create(new InviteFormDefinition());
        $outcome = null;

        $onValid = function ($valid) use ($invites, $plainToken, &$outcome): bool {
            $result  = $invites->redeem(
                $plainToken,
                (string)$valid->get('first_name') ?: null,
                (string)$valid->get('last_name') ?: null,
            );
            $outcome = $result['outcome'];

            // Only a real redemption leaves the form; the two failure outcomes
            // re-render this page with their message, because a PRG to the
            // thank-you page would claim an account that does not exist.
            return $outcome === InvitationFlow::REDEEMED;
        };

        if ($form->process($onValid)) {
            return $this->redirect('/member/main/register/danke?einladung=1');
        }

        return $this->html([
            'pageTitle' => 'Einladung annehmen',
            'invite'    => [
                'dead'    => false,
                'email'   => (string)$token->getEmail(),
                'outcome' => $outcome,
            ],
        ] + $form->viewContext());
    }

    /**
     * The sentence a project wants on the page when its own button led here
     * (memberConfig `originNotes`). The module knows no offers — an origin
     * without an entry simply shows nothing, which is also what happens when
     * somebody types a `?via=` of their own.
     */
    /**
     * The version label of the terms currently in force, from
     * `memberConfig.terms.version`. Empty (the default) means this project has
     * no terms box, and nothing is recorded.
     *
     * ⚠️ It comes from the CONFIG, never from the form. A hidden field would
     * be the visitor's own claim about which wording they agreed to — and the
     * whole point of recording it is that we can say which one it was.
     */
    private function termsVersion(): ?string
    {
        $terms = DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\Module\Member')
            ->get('terms', []);
        $version = is_array($terms) ? trim((string)($terms['version'] ?? '')) : '';

        return $version !== '' ? $version : null;
    }

    private function originNote(?string $origin): string
    {
        if ($origin === null) {
            return '';
        }

        $notes = DI::getConfigManager()
            ->getArrayConfig('App/Config/memberConfig', 'Z77\\Module\\Member')
            ->get('originNotes', []);

        return is_array($notes) ? (string)($notes[$origin] ?? '') : '';
    }

    /**
     * The dead-link page renders the same template as the form, so the template
     * still expects the public-form keys. Handing it empty ones is cheaper than
     * a second template that would drift from this one.
     */
    private function emptyFormContext(): array
    {
        return [
            'form'      => null,
            'fields'    => [],
            'errors'    => [],
            'formError' => '',
            'checkUrl'  => '',
        ];
    }

    /** PRG target — the confirmation is a page, not a session flag. */
    protected function dankeAction(): HtmlResponse
    {
        $fromInvite = trim((string) DI::getRequest()->getGetParameter('einladung')) !== '';

        return $this->html([
            'pageTitle'  => $fromInvite ? 'Zugang beantragt' : 'Registrierung erhalten',
            'fromInvite' => $fromInvite,
        ]);
    }

    /**
     * Per-field blur validation (public-form standard). Both forms declare
     * `first_name` and `last_name` with identical rules, and those are the only
     * fields the invitation form has — so one definition answers for both and
     * the check endpoint needs no knowledge of which form is on screen.
     */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new RegisterFormDefinition());
    }
}
