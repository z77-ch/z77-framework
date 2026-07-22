<?php

namespace Z77\Shared\Forms;

use Z77\Core\DI;

/**
 * The submit flow of a public form — CSRF, bot checks, validation, rate limit,
 * send and the PRG hand-off, in one place (see
 * docs/03-development/public-form-bauplan.md). A controller action shrinks to
 * "process, then render":
 *
 *   $form = PublicFormHandler::create(new ContactFormDefinition());
 *   if ($form->process()) {
 *       return $this->redirect(localizedUrl('/kontakt/danke'));   // PRG
 *   }
 *   return $this->html(['pageTitle' => 'Kontakt'] + $form->viewContext());
 *
 * The order of checks is deliberate and must not change:
 *
 *   CSRF invalid              → friendly re-render (never a reject — the UX
 *                               reason for the in-action check instead of the
 *                               #[Csrf] attribute, see docs/topics/security.md)
 *   honeypot OR too fast      → pretend success (PRG), send nothing
 *   validation failed         → field errors + top banner, values kept
 *   rate limited              → generic error
 *   send ok                   → recordSend + PRG
 *   send failed               → generic error (a transport/config problem must
 *                               never escalate to a 500 on a public form)
 *
 * Responses stay with the controller (ADR-003): process() returns a bool —
 * "the caller must now redirect" — not a Response object.
 *
 * The handler RETURNS STATE and does nothing else. Everything it produces
 * reaches the page through viewContext(); it renders nothing, redirects
 * nothing, and pushes no messages of its own. What the visitor sees after a
 * successful submit is the controller's business: it redirects to a thank-you
 * PAGE (so "was this sent?" is answered by the URL, not by hidden session
 * state) and pushes a flash there if it wants one:
 *
 *   if ($form->process()) {
 *       $this->messageService->pushFlashAfterRedirect('success', t('form.flash.sent'));
 *       return $this->redirect(localizedUrl('/kontakt/danke'));
 *   }
 *
 * Its only cross-request effect is the FormGuard session state — bot defence,
 * not UI.
 *
 * Not a DI singleton — built per form like Mailer::create() / FormGuard::forKey().
 */
final class PublicFormHandler
{
    private FormGuard $guard;
    private PublicForm $form;

    /** @var array<string,string> field name => message */
    private array $errors = [];

    private string $formError = '';
    private bool $processed   = false;

    private string $csrfErrorKey       = 'form.error.csrf';
    private string $sendErrorKey       = 'form.error.send';
    private string $validationErrorKey = 'form.error.check';

    private function __construct(private FormDefinition $definition)
    {
        $this->guard = FormGuard::forKey($definition->guardKey());
        $this->form  = PublicForm::blank($definition);
    }

    public static function create(FormDefinition $definition): self
    {
        return new self($definition);
    }

    /**
     * Overrides the form-level message keys (translation keys). These are texts
     * the handler RETURNS through viewContext() — not messages it emits
     * somewhere on its own.
     */
    public function withMessageKeys(
        ?string $csrfError = null,
        ?string $sendError = null,
        ?string $validationError = null,
    ): self {
        $this->csrfErrorKey       = $csrfError ?? $this->csrfErrorKey;
        $this->sendErrorKey       = $sendError ?? $this->sendErrorKey;
        $this->validationErrorKey = $validationError ?? $this->validationErrorKey;

        return $this;
    }

    /**
     * Runs the flow for the current request.
     *
     * @param callable(PublicForm):bool|null $onValid Called with the validated
     *     form INSTEAD of the default sendForm() — this is where the project
     *     gives the go-ahead (return true = handled → PRG; false = generic
     *     error). Use it for dynamic recipients (EmailService::send()) or for
     *     anything else a valid submit should trigger.
     *
     * @return bool true → the caller must redirect (PRG): a real success or a
     *     bot pretending to be one. false → render the form via viewContext().
     */
    public function process(?callable $onValid = null): bool
    {
        $request = DI::getRequest();

        if (!$request->isReadMethod()) {
            $this->form = PublicForm::fromPost($this->definition, $request->getPostParameters());

            if (!DI::getCsrfService()->validate((string) $request->getPostParameter('csrf_token'))) {
                $this->formError = $this->translate($this->csrfErrorKey);
            } elseif ($this->form->isHoneypotTripped() || $this->guard->isTooFast()) {
                // Bot: pretend success, send nothing. Indistinguishable from the
                // real path below, which is the point.
                $this->guard->disarmTimeTrap();
                return true;
            } else {
                $validator = new PublicFormValidator($this->form);

                if (!$validator->isValid()) {
                    // Per-field errors + a form-level banner, so the reason is
                    // visible at the top without scrolling to the failed field.
                    $this->errors    = $validator->getFieldErrors();
                    $this->formError = $this->translate($this->validationErrorKey);
                } elseif ($this->guard->isRateLimited()) {
                    $this->formError = $this->translate($this->sendErrorKey);
                } elseif ($this->dispatch($onValid)) {
                    $this->guard->recordSend();
                    // Cycle closed: the next form render arms a new window.
                    $this->guard->disarmTimeTrap();
                    return true;
                } else {
                    $this->formError = $this->translate($this->sendErrorKey);
                }
            }
        }

        // Time-trap baseline: armed on the first render of a form cycle and
        // KEPT across re-renders (FormGuard::armTimeTrap() is idempotent) — a
        // corrected resubmit must not be measured against the error page it
        // came from. A completed submit disarms it (see the two return-true
        // paths above), so the next form render starts a fresh window.
        $this->guard->armTimeTrap();
        $this->processed = true;

        return false;
    }

    /** The validated submit: project callback if given, else the form mail. */
    private function dispatch(?callable $onValid): bool
    {
        if ($onValid !== null) {
            return (bool) $onValid($this->form);
        }

        $replyToField = $this->definition->replyToField();
        $routeField   = $this->definition->routeField();

        return DI::getEmailService()->sendForm(
            $this->definition->formKey(),
            ['form' => $this->form],
            $replyToField !== null ? $this->form->get($replyToField) : null,
            $routeField !== null ? $this->form->get($routeField) : null,
        );
    }

    /**
     * Template state — merge into the controller's html() context. Same keys the
     * hand-written forms used, so a project template keeps its shape.
     *
     * @return array{form:PublicForm,fields:array,errors:array,formError:string,checkUrl:string}
     */
    public function viewContext(): array
    {
        if (!$this->processed) {
            throw new \RuntimeException('PublicFormHandler::process() must run before viewContext().');
        }

        return [
            'form'      => $this->form,
            'fields'    => $this->definition->normalizedFields(),
            'errors'    => $this->errors,
            'formError' => $this->formError,
            'checkUrl'  => $this->checkUrl(),
        ];
    }

    public function form(): PublicForm
    {
        return $this->form;
    }

    /**
     * Blur-check endpoint: the definition's explicit value, else derived from
     * the current route (`/{module}/{group}/{controller}/check`).
     */
    private function checkUrl(): string
    {
        $explicit = $this->definition->checkUrl();
        if ($explicit !== null) {
            return $explicit;
        }

        // The Request getters already hold the cleaned URL segments — no name
        // transformation happens here, just assembly.
        $request = DI::getRequest();

        return '/' . implode('/', [
            $request->getModule(),
            $request->getGroup(),
            $request->getController(),
            'check',
        ]);
    }

    private function translate(string $key): string
    {
        return DI::getTranslator()->t($key);
    }
}
