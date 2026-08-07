<?php

namespace Z77\Module\Member\Ui\Controllers\Main;

use Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Module\Member\Services\LoginFlow,
    Z77\Module\Member\Services\MemberAuth,
    Z77\Module\Member\Ui\Controllers\AbstractMemberController,
    Z77\Module\Member\Ui\Form\LoginFormDefinition,
    Z77\Shared\Controller\PublicFormCheckTrait,
    Z77\Shared\Forms\PublicFormHandler
;

/**
 * B8 login pages: request the magic link (public-form standard — the handler
 * owns CSRF/bot/validation/rate limit; the flow adds the per-address throttle
 * and the anti-oracle mail choice) and redeem it. A signed-in visitor is sent
 * straight to the profile.
 */
class LoginController extends AbstractMemberController
{
    use PublicFormCheckTrait;

    protected function indexAction(): HtmlResponse|RedirectResponse
    {
        if (MemberAuth::create()->current() !== null) {
            return $this->redirect('/member/main/profile');
        }

        $this->layoutManager->addJs('public-form', 'Z77\\Module\\Frontend', 'footer', true);

        $form = PublicFormHandler::create(new LoginFormDefinition());

        $onValid = fn($valid): bool => $this->loginFlow()->request(
            (string)$valid->get('email'),
            (bool)$valid->get('remember')
        );

        if ($form->process($onValid)) {
            return $this->redirect('/member/main/login/warten');
        }

        return $this->html(['pageTitle' => 'Anmelden'] + $form->viewContext());
    }

    /**
     * PRG target — neutral by spec («falls ein Konto besteht, ist eine Mail
     * unterwegs»), plus the check digits this request is waiting under. The
     * page polls the status; a visitor who lands here without a waiting
     * record (bookmark, reload after the window) just sees the neutral text.
     */
    protected function wartenAction(): HtmlResponse
    {
        $pending = $this->loginFlow()->currentPending();

        if ($pending !== null) {
            $this->layoutManager->addJs('login-wait', self::NAMESPACE, 'footer', true);
        }

        return $this->html([
            'pageTitle' => 'Anmeldung angefordert',
            'digits'    => $pending?->getCheckDigits() ?? '',
        ]);
    }

    /** The old PRG target — kept so bookmarked/queued links do not 404. */
    protected function dankeAction(): RedirectResponse
    {
        return $this->redirect('/member/main/login/warten');
    }

    /**
     * The status poll of the waiting device (JSON). Says only how far its OWN
     * request got — never anything about an account, and never about someone
     * else's record: the id comes from this browser's session, nowhere else.
     */
    protected function statusAction(): JsonResponse
    {
        $outcome = $this->loginFlow()->poll();

        return new JsonResponse(match ($outcome) {
            LoginFlow::SESSION       => ['state' => 'session', 'redirect' => '/member/main/profile'],
            LoginFlow::TOTP_REQUIRED => ['state' => 'session', 'redirect' => '/member/main/login/totp'],
            LoginFlow::WAITING       => ['state' => 'waiting'],
            default                  => ['state' => 'dead'],
        });
    }

    /**
     * The link click — the confirmation page (spec 1.1.0, decision 5). GET
     * shows what was requested (time, device, check digits) and two buttons;
     * POST decides WHICH device gets the session:
     *
     *   confirm → releases the waiting record, the requesting device signs in
     *   here    → this device signs in (the plain magic-link behaviour)
     *
     * A link whose waiting record is gone still offers «hier anmelden».
     */
    protected function redeemAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $flow    = $this->loginFlow();
        $token   = (string)($request->isPost()
            ? $request->getPostParameter('token')
            : $request->getGetParameter('token'));

        if ($request->isPost() && DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
            if ((string)$request->getPostParameter('decision') === 'confirm') {
                if ($flow->approve($token) === LoginFlow::APPROVED) {
                    return $this->html([
                        'pageTitle' => 'Anmeldung bestätigt',
                        'confirmed' => true,
                        'pending'   => null,
                        'token'     => '',
                    ]);
                }
            } else {
                $outcome = $flow->redeem($token);
                if ($outcome === LoginFlow::SESSION) {
                    return $this->redirect('/member/main/profile');
                }
                if ($outcome === LoginFlow::TOTP_REQUIRED) {
                    return $this->redirect('/member/main/login/totp');
                }
            }

            return $this->deadLink();
        }

        $context = $flow->linkContext($token);
        if ($context === null) {
            return $this->deadLink();
        }

        return $this->html([
            'pageTitle' => 'Anmeldung bestätigen',
            'confirmed' => false,
            'pending'   => $context['pending'],
            'token'     => $token,
        ]);
    }

    private function deadLink(): RedirectResponse
    {
        $this->messageService->pushFlashAfterRedirect(
            'error',
            'Dieser Anmelde-Link ist nicht mehr gültig — fordern Sie einen neuen an.'
        );

        return $this->redirect('/member/main/login');
    }

    /**
     * The 2FA interstitial (B8: only the valid app code creates the session).
     * Reachable only while a redeemed link parks at the prompt (5-minute
     * window) — otherwise back to the request page.
     */
    protected function totpAction(): HtmlResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $flow    = $this->loginFlow();

        if ($request->isPost()) {
            if (!DI::getCsrfService()->validate((string)$request->getPostParameter('csrf_token'))) {
                return $this->redirect('/member/main/login/totp');
            }

            $outcome = $flow->confirmTotp((string)$request->getPostParameter('code'));

            if ($outcome === LoginFlow::SESSION) {
                return $this->redirect('/member/main/profile');
            }
            if ($outcome === LoginFlow::DEAD) {
                $this->messageService->pushFlashAfterRedirect(
                    'error',
                    'Die Anmeldung ist abgelaufen — fordern Sie einen neuen Link an.'
                );

                return $this->redirect('/member/main/login');
            }

            return $this->html([
                'pageTitle' => 'Code eingeben',
                'error'     => $outcome === LoginFlow::TOTP_LOCKED
                    ? 'Zu viele Fehlversuche — bitte warten Sie 15 Minuten.'
                    : 'Der Code ist ungültig — bitte versuchen Sie es erneut.',
            ]);
        }

        if (!$this->hasTotpPending()) {
            return $this->redirect('/member/main/login');
        }

        return $this->html(['pageTitle' => 'Code eingeben', 'error' => '']);
    }

    private function hasTotpPending(): bool
    {
        return (new \Z77\Module\Member\Services\MemberSession(DI::getSessionManager()))
            ->totpPendingAccountId() !== null;
    }

    /** Per-field blur validation (public-form standard). */
    protected function checkAction(): JsonResponse
    {
        return $this->blurCheck(new LoginFormDefinition());
    }

    private function loginFlow(): LoginFlow
    {
        return LoginFlow::create(
            $this->absoluteUrl('/member/main/login/redeem'),
            $this->absoluteUrl('/member/main/register'),
            $this->absoluteUrl('/member/main/confirm'),
        );
    }
}
