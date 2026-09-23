<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Module\Debtor\Entities\DunningLevel,
    Z77\Module\Debtor\Repositories\DunningLevelRepository,
    Z77\Module\Debtor\Services\DebtorAccounts,
    Z77\Module\Debtor\Services\DebtorCurrency,
    Z77\Module\Debtor\Services\DebtorException,
    Z77\Module\Debtor\Services\DebtorMasterData,
    Z77\Module\Debtor\Services\InvalidMasterDataException,
    Z77\Module\Debtor\Services\MasterDataCodeChangedException,
    Z77\Module\Debtor\Validators\DunningLevelValidator,
    Z77\Persistence\Cleaning\BodyCleaner,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\Money\Money
;

/**
 * The dunning-ladder surface (plan §6.5) — mounted by a thin host controller
 * in the backend (ADR-018 pattern). Host side: `use DunningLevelControllerTrait`
 * + a one-line layout config delegating to {@see DunningLevelLayout::config()}.
 *
 * What the screen can do, and deliberately cannot:
 *
 *   - list the ladder in `level` order with days after due, fee and the
 *     languages its notice text exists in;
 *   - add a level; edit label, level, days and fee and the texts — the CODE
 *     is immutable once created (notices carry it, ADR-043 decision 19);
 *   - activate / deactivate (inline switch). There is NO delete.
 *
 * The fee is typed as an amount («20», «20.00») and stored as MINOR UNITS
 * (plan §3) — parsed through `Money::fromDecimal()` in the installation's
 * base currency, so the same string rules as everywhere else apply and no
 * float touches it. It carries **no VAT** (plan §6.5) and therefore no tax
 * code; the account it is posted to is the mandator's dunning-fee account
 * (`DebtorAccounts`, key `dunningFee`), shown here so the ladder and its
 * account are read together.
 *
 * No JavaScript of its own. The using class MUST provide (via its host
 * base): `html()`, `fetch()`, `fetchError()`, `em()`, `$layoutManager`,
 * `$messageService`.
 */
trait DunningLevelControllerTrait
{
    private const DUNNING_LEVEL_NS = 'Z77\\Module\\Debtor';

    /** URL root of THIS mount — every row button and modal form is built from it. */
    protected function dunningLevelListBase(): string
    {
        return '/backend/finance/dunning-level';
    }

    private function dunningLevels(): DunningLevelRepository
    {
        return $this->em()->getRepository(DunningLevel::class);
    }

    private function dunningMasterData(): DebtorMasterData
    {
        return new DebtorMasterData($this->em());
    }

    /** @return list<string> the languages a notice text is kept in ({@see DocumentTextForm}) */
    private function dunningLanguages(): array
    {
        return DocumentTextForm::languages();
    }

    /** The installation's base currency — `systemConfig → baseCurrency`, held once (Rule 2). */
    private function dunningCurrency(): string
    {
        return DebtorCurrency::base();
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $accounts = new DebtorAccounts($this->em());
        $feeRow   = $accounts->status()['dunningFee'] ?? ['number' => '', 'error' => null];

        $response = $this->html([
            'levels'     => $this->dunningLevels()->allInOrder(),
            'currency'   => $this->dunningCurrency(),
            'languages'  => $this->dunningLanguages(),
            'feeAccount' => $feeRow,
            'accountsNotice' => $accounts->notice(),
            'actionBase' => $this->dunningLevelListBase(),
        ]);
        // The fragment owns its header slot (financial.md, «fragment slots»).
        $this->layoutManager->addPartials('addButton', 'Backend/DunningLevelController', self::DUNNING_LEVEL_NS, 'hc1');

        return $response;
    }

    // ── add / edit ───────────────────────────────────────────────────────

    protected function addAction(): HtmlResponse|FetchResponse
    {
        $next = 1;
        foreach ($this->dunningLevels()->allInOrder() as $existing) {
            $next = max($next, $existing->getLevel() + 1);
        }

        return $this->editLevel(new DunningLevel(['level' => $next, 'days_after_due' => 10]));
    }

    protected function editAction(): HtmlResponse|FetchResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $level = $id ? $this->dunningLevels()->find($id) : null;
        if ($level === null) {
            return $this->fetchError('Mahnstufe nicht gefunden');
        }

        return $this->editLevel($level);
    }

    private function editLevel(DunningLevel $level): HtmlResponse|FetchResponse
    {
        $isNew     = $level->getId() === null;
        $currency  = $this->dunningCurrency();
        $validator = null;
        $feeField  = Money::of($level->getFee(), $currency)->toDecimal();
        $feeError  = '';

        if (DI::getRequest()->isPost()) {
            $body = DI::getRequest()->getJsonBody();

            if (!$isNew) {
                $csrf = trim((string) ($body['entity_csrf'] ?? ''));
                if (!DI::getCsrfService()->validateEntityToken($csrf, 'dunningLevel', $level->getId())) {
                    return $this->fetchError('Invalid token');
                }
            }

            $originalActive = $level->isActive();

            // `fee` arrives as an AMOUNT and is parsed here; the entity holds minor units.
            $values = BodyCleaner::cleanFor(DunningLevel::class, $body);
            unset($values['fee'], $values['document_text']);
            $level->mapFromArray($values);
            $level->setActive($isNew ? true : $originalActive);
            $level->setDocumentText(DocumentTextForm::posted($body, $this->dunningLanguages()));

            $feeField = trim((string) ($body['fee'] ?? ''));
            try {
                $level->setFee(Money::fromDecimal($feeField === '' ? '0' : str_replace(["'", ','], ['', '.'], $feeField), $currency)->minor);
            } catch (\InvalidArgumentException) {
                $feeError = 'Mahngebühr als Betrag mit höchstens zwei Dezimalen, z.B. 20 oder 20.00';
            }

            if ($feeError === '') {
                try {
                    $this->dunningMasterData()->saveLevel($level);

                    $this->messageService->pushFlashAfterRedirect(
                        'success',
                        'Mahnstufe «' . $level->getLabel() . '» ' . ($isNew ? 'angelegt' : 'gespeichert')
                    );

                    return $this->fetch()
                        ->setStatus('success')
                        ->setData(['id' => $level->getId()])
                        ->addCommand('close-modal')
                        ->addCommand('reload');
                } catch (MasterDataCodeChangedException) {
                    return $this->fetchError('Der Code ist nach dem Anlegen fix — für eine andere Mahnstufe einen neuen Code anlegen.');
                } catch (InvalidMasterDataException $e) {
                    /** @var DunningLevelValidator $validator */
                    $validator = $e->validator;
                }
            } else {
                $validator = new DunningLevelValidator($level, $this->dunningLevels());
                $validator->isValid();
            }
            // fall through — re-render the form with the errors
        }

        $response = $this->html([
            'entry'      => $level,
            'currency'   => $currency,
            'feeField'   => $feeField,
            'feeError'   => $feeError,
            'languages'  => $this->dunningLanguages(),
            'entityCsrf' => $isNew ? '' : DI::getCsrfService()->generateEntityToken('dunningLevel', $level->getId()),
            'validator'  => $validator ?? new DunningLevelValidator($level),
            'actionBase' => $this->dunningLevelListBase(),
        ]);
        $this->layoutManager->addPartials('edit', 'Backend/DunningLevelController', self::DUNNING_LEVEL_NS);

        return $response;
    }

    // ── active switch ────────────────────────────────────────────────────

    /** Inline switch on the list row (`data-fetch-toggle`, session CSRF via header). */
    #[Fetch, HttpMethod('POST')]
    protected function toggleActiveAction(): FetchResponse
    {
        $id    = (int) DI::getRequest()->getGetParameter('id');
        $level = $id ? $this->dunningLevels()->find($id) : null;
        if ($level === null) {
            return $this->fetchError('Mahnstufe nicht gefunden');
        }

        $body = DI::getRequest()->getJsonBody();
        try {
            $this->dunningMasterData()->setLevelActive($level, (bool) ($body['value'] ?? !$level->isActive()));
        } catch (DebtorException $e) {
            // A hand-edited file can still refuse here; the switch says so instead of answering 500.
            return $this->fetchError($e->getMessage());
        }

        return $this->fetch()->setStatus('success')->addCommand('reload');
    }
}
