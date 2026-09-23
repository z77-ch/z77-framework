<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Debtor\Entities\DebtorProfile,
    Z77\Module\Debtor\Entities\PaymentTerms,
    Z77\Module\Debtor\Repositories\DebtorProfileRepository,
    Z77\Module\Debtor\Repositories\PaymentTermsRepository,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\DebtorMasterData,
    Z77\Module\Debtor\Services\InvalidMasterDataException,
    Z77\Module\Debtor\Services\MasterDataCodeChangedException,
    Z77\Module\Debtor\Validators\PaymentTermsValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod
;

/**
 * The payment-terms surface (plan §6.1) — mounted by a thin host controller
 * in the backend (ADR-018 pattern, like the tax codes and the chart next to
 * it): the host provides route + auth + shell, all logic and templates live
 * here in module-debtor. Host side: `use PaymentTermsControllerTrait` + a
 * one-line layout config delegating to {@see PaymentTermsLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list every terms row with its due days, its discount tiers and the
 *     languages its document text exists in, plus how many debtors use it —
 *     so a deactivation is an informed one;
 *   - add a row; edit label, due days, the tiers and the texts — the CODE is
 *     immutable once created (profiles and, from part 2, invoices carry it,
 *     ADR-043 decision 19);
 *   - activate / deactivate (inline switch). There is NO delete.
 *
 * The discount tiers and the per-language texts are read from the body FIELD
 * BY FIELD, never mapped raw: a percent arrives as «2» or «2.5» and becomes
 * an integer in hundredths ({@see PaymentTerms::percentToHundredths()}), and
 * a text field exists per language of `I18n::getLanguages()`.
 *
 * No JavaScript of its own: every action runs through the shared `core.js`
 * wiring (`data-fetch-get` modals, `data-fetch-post` forms, `data-fetch-toggle`
 * switch). The using class MUST provide (via its host base): `html()`,
 * `fetch()`, `fetchError()`, `em()`, `$layoutManager`, `$messageService`.
 */
trait PaymentTermsControllerTrait
{
    private const PAYMENT_TERMS_NS = 'Z77\\Module\\Debtor';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function paymentTermsListBase(): string
    {
        return '/backend/finance/payment-terms';
    }

    private function paymentTerms(): PaymentTermsRepository
    {
        return $this->em()->getRepository(PaymentTerms::class);
    }

    private function debtorProfiles(): DebtorProfileRepository
    {
        return $this->em()->getRepository(DebtorProfile::class);
    }

    private function debtorMasterData(): DebtorMasterData
    {
        return new DebtorMasterData($this->em());
    }

    /** @return list<string> the languages a document text is kept in ({@see DocumentTextForm}) */
    private function documentLanguages(): array
    {
        return DocumentTextForm::languages();
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $terms = $this->paymentTerms()->allInOrder();
        $usage = [];
        foreach ($terms as $row) {
            $usage[$row->getCode()] = $this->debtorProfiles()->countByPaymentTermsCode($row->getCode());
        }

        $response = $this->html([
            'terms'      => $terms,
            'usage'      => $usage,
            'languages'  => $this->documentLanguages(),
            'actionBase' => $this->paymentTermsListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»).
        $this->layoutManager->addPartials('addButton', 'Backend/PaymentTermsController', self::PAYMENT_TERMS_NS, 'hc1');

        return $response;
    }

    // ── add / edit ───────────────────────────────────────────────────────

    protected function addAction(): HtmlResponse|FetchResponse
    {
        return $this->editTerms(new PaymentTerms(['due_days' => 30]));
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $terms = $id ? $this->paymentTerms()->find($id) : null;
        if ($terms === null) {
            return $this->fetchError('Zahlungskonditionen nicht gefunden');
        }

        return $this->editTerms($terms);
    }

    private function editTerms(PaymentTerms $terms): HtmlResponse|FetchResponse
    {
        $isNew      = $terms->getId() === null;
        $validator  = null;
        $percents   = self::percentFields($terms);
        $tierErrors = [];

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim((string) ($body['entity_csrf'] ?? ''));
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'paymentTerms', $terms->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $originalActive = $terms->isActive();

            // Structured fields are built here, never mapped raw from the body.
            $values = BodyCleaner::cleanFor(PaymentTerms::class, $body);
            unset($values['discounts'], $values['document_text']);
            $terms->mapFromArray($values);
            // `active` has its own switch on the list; the form never touches it.
            $terms->setActive($isNew ? true : $originalActive);
            $terms->setDocumentText(DocumentTextForm::posted($body, $this->documentLanguages()));

            [$tiers, $percents, $tierErrors] = self::postedTiers($body);
            $terms->setDiscounts($tiers);

            if ($tierErrors === []) {
                try {
                    $this->debtorMasterData()->saveTerms($terms);

                    $this->messageService->pushFlashAfterRedirect(
                        'success',
                        'Zahlungskonditionen «' . $terms->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert')
                    );

                    return $this->fetch()
                        ->setStatus('success')
                        ->setData(['id' => $terms->getId()])
                        ->addCommand('close-modal')
                        ->addCommand('reload');
                } catch (MasterDataCodeChangedException) {
                    return $this->fetchError('Der Code ist nach dem Anlegen fix — für andere Konditionen einen neuen Code anlegen.');
                } catch (InvalidMasterDataException $e) {
                    /** @var PaymentTermsValidator $validator */
                    $validator = $e->validator;
                }
            } else {
                // Percent unparsable: still show the other fields' errors.
                $validator = new PaymentTermsValidator($terms, $this->paymentTerms());
                $validator->isValid();
            }
            // fall through — re-render the form with the errors
        }

        $response = $this->html([
            'entry'      => $terms,
            'percents'   => $percents,
            'tierErrors' => $tierErrors,
            'languages'  => $this->documentLanguages(),
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('paymentTerms', $terms->getId()),
            'validator'  => $validator ?? new PaymentTermsValidator($terms),
            'actionBase' => $this->paymentTermsListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/PaymentTermsController', self::PAYMENT_TERMS_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $terms = $id ? $this->paymentTerms()->find($id) : null;
        if ($terms === null) {
            return $this->fetchError('Zahlungskonditionen nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->debtorMasterData()->setTermsActive($terms, (bool) ($body['value'] ?? !$terms->isActive()));
        } catch (DebtorException $e) {
            // A hand-edited file can still refuse here; the switch says so instead of answering 500.
            return $this->fetchError($e->getMessage());
        }

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }

    // ── posted structures ────────────────────────────────────────────────

    /**
     * The tiers of a posted form: `discount_days[n]` / `discount_percent[n]`
     * for n = 1 … {@see PaymentTerms::MAX_TIERS}. A row with both fields
     * empty is no tier. The percent is parsed here — an unparsable one is a
     * row error, so the field comes back with what was typed.
     *
     * @return array{0: list<array{days: int, percent: int}>, 1: array<int, string>, 2: array<int, string>}
     *         tiers, the percent strings per row (for re-rendering), the row errors
     */
    private static function postedTiers(array $body): array
    {
        $days     = is_array($body['discount_days'] ?? null) ? $body['discount_days'] : [];
        $percents = is_array($body['discount_percent'] ?? null) ? $body['discount_percent'] : [];

        $tiers = [];
        $shown = [];
        $errors = [];
        for ($row = 1; $row <= PaymentTerms::MAX_TIERS; $row++) {
            $dayValue     = trim((string) ($days[$row] ?? ''));
            $percentValue = trim((string) ($percents[$row] ?? ''));
            $shown[$row]  = $percentValue;
            if ($dayValue === '' && $percentValue === '') {
                continue;
            }
            if ($percentValue === '') {
                $errors[$row] = 'Skonto in Prozent fehlt.';
                continue;
            }
            try {
                $tiers[] = ['days' => (int) $dayValue, 'percent' => PaymentTerms::percentToHundredths($percentValue)];
            } catch (\InvalidArgumentException) {
                $errors[$row] = 'Skonto in Prozent mit höchstens zwei Dezimalen, z.B. 2 oder 2.5';
            }
        }

        return [$tiers, $shown, $errors];
    }

    /** The percent strings of a STORED row, for the form's first render. @return array<int, string> */
    private static function percentFields(PaymentTerms $terms): array
    {
        $shown = [];
        for ($row = 1; $row <= PaymentTerms::MAX_TIERS; $row++) {
            $tier        = $terms->getDiscounts()[$row - 1] ?? null;
            $shown[$row] = $tier === null ? '' : PaymentTerms::formatPercent($tier['percent']);
        }

        return $shown;
    }

}
