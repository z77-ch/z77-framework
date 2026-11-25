<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Shared\Money\Money;

/**
 * What «MWST buchen» would post for one period — the answer of
 * {@see VatReturnPostingService::plan()}, computed LOCK-FREE from the journal; what a confirm
 * dialog shows and hands back. {@see VatReturnPostingService::post()} computes it again under its
 * locks and refuses when it differs from what was confirmed.
 *
 *   - `period`, `revision` (1 for the first return of the period, +1 after each lift);
 *   - `preview` / `reconciliation` — the form and the tab «Abstimmung» as computed;
 *   - `findings` — blocking first: the posting preconditions (code = a {@see VatReturnRefusal}
 *     reason: `not-elapsed`, `not-next`, `no-fiscal-year`, `year-closed`, `account-missing`), the
 *     return's own findings and the open-work registry's ({@see VatReturnService::findings()}),
 *     `settlement-unbalanced`;
 *   - `settlement` — the settlement entry as a `PostingRequest` (no tax code on any line), null
 *     for a NIL return (every line zero — ADR-042 addendum point 1 assumes an entry, a return
 *     without a coded line has none: owner 2026-11-24, plan §10 A 4) and while a blocking finding
 *     keeps it from being built (an account missing);
 *   - `lines` — the settlement lines in posting order, also of a blocked plan: `role` (`owed` /
 *     `input` — a VAT account, `settlement`, `rounding`), `account` ('' where the mandator names
 *     none), `debit` / `credit` (exactly one positive);
 *   - `figuresFingerprint` — SHA-256 over every field's exact value and the settlement lines: the
 *     poster filed THESE figures in the portal first (plan §3.5, owner decision 5a);
 *   - `warningsFingerprint` — the `FiscalYearCloseService::warningsFingerprint()` shape over the
 *     warnings (message, reference), null when there are none.
 */
final class VatSettlementPlan
{
    /**
     * @param list<VatReturnFinding> $findings blocking first
     * @param list<array{role: string, account: string, debit: Money, credit: Money}> $lines the settlement lines in posting order
     */
    public function __construct(
        public readonly VatPeriod $period,
        public readonly int $revision,
        public readonly VatReturnPreview $preview,
        public readonly VatReturnReconciliation $reconciliation,
        public readonly array $findings,
        public readonly array $lines,
        public readonly ?PostingRequest $settlement,
        public readonly string $figuresFingerprint,
        public readonly ?string $warningsFingerprint,
    ) {}

    public function payable(): Money
    {
        return $this->preview->form->payable;
    }

    /** Posted − declared, signed — the line on the VAT-return rounding account is its opposite side. */
    public function roundingDifference(): Money
    {
        return $this->preview->form->roundingDifference();
    }

    /** True when the return has no settlement entry: every line of it is zero. */
    public function isNil(): bool
    {
        return $this->lines === [];
    }

    public function isBlocked(): bool
    {
        return $this->blocking() !== [];
    }

    /** @return list<VatReturnFinding> */
    public function blocking(): array
    {
        return array_values(array_filter($this->findings, static fn(VatReturnFinding $f): bool => $f->blocking));
    }

    /** @return list<VatReturnFinding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, static fn(VatReturnFinding $f): bool => !$f->blocking));
    }

    /** The messages of the warnings — what the return stores as confirmed. @return list<string> */
    public function warningMessages(): array
    {
        return array_map(static fn(VatReturnFinding $f): string => $f->message, $this->warnings());
    }
}
