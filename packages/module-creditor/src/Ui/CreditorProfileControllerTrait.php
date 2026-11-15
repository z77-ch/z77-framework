<?php
namespace Z77\Module\Creditor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Core\Services\TemplateRenderer,
    Z77\Module\Creditor\Entities\CreditorIban,
    Z77\Module\Creditor\Entities\CreditorInvoice,
    Z77\Module\Creditor\Entities\CreditorProfile,
    Z77\Module\Creditor\Repositories\CreditorIbanRepository,
    Z77\Module\Creditor\Repositories\CreditorProfileRepository,
    Z77\Module\Creditor\Services\CreditorException,
    Z77\Module\Creditor\Services\CreditorProfileService,
    Z77\Module\Creditor\Services\InvalidCreditorProfileException,
    Z77\Module\Creditor\Validators\CreditorProfileValidator,
    Z77\Module\Receipt\Entities\PaymentMethod,
    Z77\Module\Receipt\Services\PaymentMethodService,
    Z77\Module\Receipt\Ui\ReceiptChoices,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Listing\ListDefinition
;

/**
 * Stammdaten › Finanzen › «Kreditoren» (creditor plan §8) — the payables side of a contact: UID,
 * IBANs and the defaults a new invoice line starts with. Mounted by a thin host controller in the
 * backend (ADR-018 pattern): URL `/backend/finance/creditor-profile/…`.
 *
 *   - `list` — a STANDARD LIST ({@see CreditorListing}): state icon, Kreditor, UID, IBAN (the first,
 *     «+ n»), Konto, MWST, «Bezahlt mit», the «Aktiv» switch; name, UID and IBAN searched in the
 *     database. The action cell is EMPTY (the debtor decision of 2026-10-12): a creditor is made by
 *     the role «Kreditor» in the contact editor (later also by «Kreditor übernehmen …»);
 *   - `edit` — the WINDOW: the contact (fixed), UID, the defaults (Konto, MWST — a code, «ohne
 *     MWST» = the select value `-` —, «Bezahlt mit»), when and by whom the defaults were decided,
 *     the IBAN rows (IBAN, hint, active; an empty row adds one, an emptied IBAN removes its row) and
 *     for a shared IBAN the other creditors behind it. One form, one save, the VERSION shown is
 *     posted (`CreditorProfileService::update()`);
 *   - `toggle-active` — the inline switch. No delete here (the role «Kreditor» off deletes).
 *
 * Every write goes through {@see CreditorProfileService}. No JavaScript of its own (Rule 7). The
 * using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`, `redirect()`,
 * `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait CreditorProfileControllerTrait
{
    private const CREDITOR_NS = 'Z77\\Module\\Creditor';

    /** The select value of «ohne MWST» in the VAT field (the receipt window's convention). */
    private const WITHOUT_VAT = '-';

    /** URL root of THIS mount — every row link and form is built from it. */
    protected function creditorBase(): string
    {
        return CreditorLayout::BASE;
    }

    /** The write path; the session actor. A harness host overrides it with a named actor. */
    private function creditorProfiles(): CreditorProfileService
    {
        return new CreditorProfileService($this->em());
    }

    private function creditorRepository(): CreditorProfileRepository
    {
        return $this->em()->getRepository(CreditorProfile::class);
    }

    private function creditorIbans(): CreditorIbanRepository
    {
        return $this->em()->getRepository(CreditorIban::class);
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $definition = CreditorListing::definition();
        $state      = $definition->readRequest($request);
        $search     = CreditorListing::search($state);
        $total      = $this->creditorRepository()->countSearch($search);
        $paging     = $state->paging($total);
        $profiles   = $total === 0 ? [] : $this->creditorRepository()->search($search, $paging->offset(), $paging->pageSize);
        if (!ListDefinition::isFetch($request)) {
            // The list's help (HELP-003): «Hilfe» at the right end of the toolbar.
            $this->help->attach('Backend/CreditorProfileController/list.help', self::CREDITOR_NS, [], 'Hilfe: Kreditoren', toolbar: true);
        }

        return $this->html([
            'profiles'   => $profiles,
            'ibans'      => $this->creditorIbans()->forProfiles(array_map(static fn(CreditorProfile $p) => (int) $p->getId(), $profiles)),
            'methods'    => $this->creditorMethodLabels(),
            'invoiceCounts' => $this->em()->getRepository(CreditorInvoice::class)->countByProfiles(array_map(static fn(CreditorProfile $p) => (int) $p->getId(), $profiles)),
            'definition' => $definition,
            'state'      => $state,
            'paging'     => $paging,
            'actionBase' => $this->creditorBase(),
        ]);
    }

    // ── edit ─────────────────────────────────────────────────────────────

    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        $profile = $id > 0 ? $this->creditorRepository()->find($id) : null;
        $listUrl = $this->creditorBase() . '/list';
        if (!$profile instanceof CreditorProfile || $profile->getContact() === null) {
            return $this->creditorRefuse('Kreditor nicht gefunden', $listUrl);
        }

        $draft     = $profile;
        $validator = new CreditorProfileValidator($profile);
        $rows      = null;
        if ($request->isPost()) {
            $post = $request->getPostParameters();
            if (!DI::getCsrfService()->validateEntityToken(trim((string) ($post['entity_csrf'] ?? '')), 'creditorProfile', $id)) {
                return $this->creditorRefuse('Der Kreditor wurde inzwischen geändert — bitte neu laden.', $listUrl);
            }
            $rows = CreditorProfileService::ibanRows(is_array($post['ibans'] ?? null) ? $post['ibans'] : []);
            try {
                $this->creditorProfiles()->update($profile, (int) ($post['version'] ?? 0), self::creditorValues($post), $rows);

                return $this->creditorSaved('Kreditor «' . $profile->getContact()->displayName() . '» gespeichert', $listUrl);
            } catch (InvalidCreditorProfileException $e) {
                $validator = $e->validator;
                $draft     = $e->profile;
                $rows      = $e->ibanRows;
            } catch (CreditorException $e) {
                return $this->creditorRefuse($e->getMessage(), $listUrl);
            }
            // fall through — re-render the window with the errors and what was typed
        }

        $this->help->attach('Backend/CreditorProfileController/edit.help', self::CREDITOR_NS, [], 'Hilfe: Kreditor');
        $choices  = new ReceiptChoices($this->em());
        $response = $this->html([
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'entry'      => $draft,
            'contact'    => $profile->getContact(),
            'version'    => $profile->getVersion(),
            'validator'  => $validator,
            'ibanRows'   => $rows ?? $this->creditorStoredRows($profile),
            'sharedWith' => $this->creditorSharedWith($profile, $rows ?? $this->creditorStoredRows($profile)),
            'choices'    => $choices,
            'accounts'   => $choices->accounts(),
            'taxCodes'   => $choices->taxCodes([(string) $profile->getDefaultTaxCode()]),
            'methods'    => $this->creditorSelectableMethods((string) $profile->getDefaultPaymentMethodCode()),
            'liable'     => $this->creditorProfiles()->liableToVat(),
            'entityCsrf' => DI::getCsrfService()->generateEntityToken('creditorProfile', $id),
            'actionBase' => $this->creditorBase(),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('edit', 'Backend/CreditorProfileController', self::CREDITOR_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id      = (int) DI::getRequest()->getGetParameter('id');
        $profile = $id > 0 ? $this->creditorRepository()->find($id) : null;
        if (!$profile instanceof CreditorProfile || $profile->getContact() === null) {
            return $this->fetchError('Kreditor nicht gefunden');
        }
        $body = DI::getRequest()->getJsonBody();
        try {
            $this->creditorProfiles()->setActive($profile, (bool) ($body['value'] ?? !$profile->isActive()));
        } catch (CreditorException $e) {
            return $this->fetchError($e->getMessage());
        }
        $profile = $this->creditorRepository()->find($id) ?? $profile;
        $html    = (new TemplateRenderer(self::CREDITOR_NS))->partial('Backend/CreditorProfileController/_row', [
            'profile'    => $profile,
            'ibans'      => $this->creditorIbans()->forProfile($profile),
            'methods'    => $this->creditorMethodLabels(),
            'invoices'   => $this->em()->getRepository(CreditorInvoice::class)->countForProfile($id),
            'definition' => CreditorListing::definition(),
            'actionBase' => $this->creditorBase(),
        ]);

        return $this->fetch()
            ->setStatus('success')
            ->setData(['id' => $id])
            ->replaceRow('creditorProfile', $id, $html);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The window's fields → the service's values. The VAT select carries a code, `-` («ohne MWST»)
     * or '' (undecided); it becomes `default_tax_code` + `default_without_vat`. The contact,
     * `active` and the version are never mapped from the body.
     *
     * @param array<string, mixed> $post
     * @return array<string, mixed>
     */
    private static function creditorValues(array $post): array
    {
        $values = BodyCleaner::cleanFor(CreditorProfile::class, [
            'uid'                         => (string) ($post['uid'] ?? ''),
            'default_account'             => (string) ($post['default_account'] ?? ''),
            'default_payment_method_code' => (string) ($post['default_payment_method_code'] ?? ''),
        ]);
        $tax = trim((string) ($post['default_tax'] ?? ''));
        $values['default_without_vat'] = $tax === self::WITHOUT_VAT;
        $values['default_tax_code']    = $tax === self::WITHOUT_VAT ? '' : $tax;

        return $values;
    }

    /** @return list<array{iban: string, hint: string, active: bool}> the stored IBAN rows as the window edits them */
    private function creditorStoredRows(CreditorProfile $profile): array
    {
        return array_map(
            static fn(CreditorIban $row) => ['iban' => $row->getIban(), 'hint' => $row->getHint(), 'active' => $row->isActive()],
            $this->creditorIbans()->forProfile($profile)
        );
    }

    /**
     * For every IBAN of the window, the OTHER creditors it is linked to — a shared IBAN (plan §2.2):
     * the window says so, and the hint is what tells them apart.
     *
     * @param list<array{iban: string, hint: string, active: bool}> $rows
     * @return array<string, list<string>> IBAN → display names
     */
    private function creditorSharedWith(CreditorProfile $profile, array $rows): array
    {
        $shared = [];
        foreach ($rows as $row) {
            foreach ($this->creditorIbans()->findProfilesByIban($row['iban'], activeOnly: false) as $other) {
                if ($other->getId() !== $profile->getId()) {
                    $shared[$row['iban']][] = (string) $other->getContact()?->displayName();
                }
            }
        }

        return $shared;
    }

    /** @return array<string, string> code → label of every payment method (the list's «Bezahlt mit») */
    private function creditorMethodLabels(): array
    {
        $labels = [];
        foreach ((new PaymentMethodService($this->em()))->all() as $method) {
            $labels[$method->getCode()] = $method->getLabel();
        }

        return $labels;
    }

    /**
     * What «Bezahlt mit» offers: the ACTIVE payment methods, plus the creditor's own when it was
     * deactivated since (ADR-043 decision 19 — kept, never newly chosen).
     *
     * @return list<PaymentMethod>
     */
    private function creditorSelectableMethods(string $current): array
    {
        return array_values(array_filter(
            (new PaymentMethodService($this->em()))->all(),
            static fn(PaymentMethod $m) => $m->isActive() || $m->getCode() === $current
        ));
    }

    /** Close the window and reload the list region it came from; without the script: flash + the list. */
    private function creditorSaved(string $flash, string $listUrl): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($listUrl, 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        }

        return $response;
    }

    private function creditorRefuse(string $message, string $listUrl): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($listUrl, 303);
    }
}
