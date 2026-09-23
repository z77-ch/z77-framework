<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Module\Financial\Ledger\PostingLine as LedgerLine;
use Z77\Module\Financial\Ledger\PostingRequest as LedgerRequest;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\VatAccountUnavailableException;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The default {@see AccountingGateway}: debtor's {@see PostingRequest}
 * translated 1:1 into financial's and handed to `LedgerService::post()`
 * inside the caller's unit of work (plan §5.4, §6.6; ADR-040 decisions 2, 5
 * and 7). This class is the ONLY one in debtor that names module-financial
 * (the soft account check moved to module-mandator with the account
 * settings, 2026-09-23) — the package only `suggest`s financial, and the
 * adapter refuses to run rather than fatal when `financial` is not a
 * REGISTERED module ({@see LedgerAccountCheck::available()}).
 *
 * What the translation adds, and why here:
 *
 *   - a line named by VAT CATEGORY gets its account number through
 *     financial's `LedgerService::vatAccountFor()` — since owner decision
 *     E2 the mandator record's VAT accounts, still the bookkeeping's one
 *     resolution (Rule 2), so debtor never carries a copy. A category
 *     without a number, or one whose account the ledger will not take, is
 *     refused HERE with a message naming the mandator
 *     ({@see AccountingRefusedException::VAT_ACCOUNT_MISSING}) — the
 *     ledger's own «account unknown» would not say where to look;
 *   - financial's `PostingRefusedException` (no fiscal year, period closed
 *     or VAT-settled, account unknown / group / inactive, tax code unknown,
 *     idempotency conflict) comes back as {@see AccountingRefusedException}
 *     with the same `reason`, the financial exception as `getPrevious()`.
 *
 * What is deliberately NOT here: any catch of the unique-index failure at
 * COMMIT (the same key in two parallel units of work — `financial.md`, the
 * race contract) or any retry. Both end the caller's unit of work; no
 * number is consumed; a retry is a new unit of work, and with the same key
 * the ledger then answers with the existing entry.
 *
 * The answer is `EntryRef` written as ONE string, `{fiscal-year}/{number}`
 * — how the document stores it and how a journal names an entry.
 */
final class LedgerAccountingGateway implements AccountingGateway
{
    private readonly LedgerService $ledger;

    /**
     * @param string $actor the author's name the ledger stamps (`Actor::current()` resolved by the caller)
     * @throws AccountingUnavailableException module-financial is not usable here
     */
    public function __construct(private readonly UnifiedEntityManager $em, string $actor)
    {
        if (!(new LedgerAccountCheck($em))->available()) {
            throw new AccountingUnavailableException(
                'debtorConfig → accountingGateway names ' . self::class . ', but module-financial is not a registered module here — '
                . 'register it, or configure ' . NullAccountingGateway::class . ' to keep the books elsewhere.'
            );
        }
        $this->ledger = new LedgerService($em, $actor);
    }

    public function post(PostingRequest $request): ?string
    {
        $lines = [];
        foreach ($request->lines as $line) {
            $account = $line->account ?? $this->vatAccount($line->vatCategory);
            $lines[] = new LedgerLine(
                $account,
                $line->debit,
                $line->credit,
                $line->taxCode,
                $line->taxRate,
                $line->taxBase,
                $line->taxAmount,
                $line->text,
            );
        }
        $ledgerRequest = LedgerRequest::generated(
            $request->date,
            $request->text,
            $request->sourceType,
            $request->sourceRef,
            $request->idempotencyKey,
            $lines,
        );

        try {
            $ref = $this->ledger->post($ledgerRequest);
        } catch (PostingRefusedException $e) {
            throw new AccountingRefusedException($e->reason, $e->getMessage(), $e);
        }

        return $ref->fiscalYear . '/' . $ref->number;
    }

    /** @throws AccountingRefusedException the category has no usable VAT account on the mandator record */
    private function vatAccount(string $category): string
    {
        try {
            $number = $this->ledger->vatAccountFor($category);
        } catch (VatAccountUnavailableException $e) {
            // A leftover config key or an unreadable mandator — the sentence names the next step (review 2026-09-23).
            throw new AccountingRefusedException(AccountingRefusedException::VAT_ACCOUNT_MISSING, $e->getMessage(), $e);
        }
        if ($number === null || !$this->ledger->accountExists($number)) {
            throw new AccountingRefusedException(
                AccountingRefusedException::VAT_ACCOUNT_MISSING,
                'Für die MWST-Kategorie «' . $category . '» ist kein buchbares Konto hinterlegt — ' . LedgerService::MANDATOR_HINT
                . ($number !== null ? ' (Konto ' . $number . ' gibt es nicht, ist eine Gruppe oder inaktiv).' : '.')
            );
        }

        return $number;
    }
}
