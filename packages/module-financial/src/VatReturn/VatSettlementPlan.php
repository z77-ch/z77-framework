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
 *     reason: `not-elapsed`, `not-next`, `no-fiscal-year`, `year-closed`, `account-missing` — the
 *     settlement / rounding account not set or not postable, or a VAT account of a line not
 *     postable), the return's own findings and the open-work registry's
 *     ({@see VatReturnService::findings()}), `settlement-unbalanced`;
 *   - `settlement` — the settlement entry as a `PostingRequest` (no tax code on any line), null
 *     for a NIL return (every line zero — ADR-042 addendum point 1 assumes an entry, a return
 *     without a coded line has none: owner decision 4a of 2026-11-24, financial.md «MWST buchen»)
 *     and while a blocking finding keeps it from being built (an account missing);
 *   - `lines` — the settlement lines in posting order, also of a blocked plan: `role` (`owed` /
 *     `input` — a VAT account, `settlement`, `rounding`, `correction` — a line of the block of
 *     correction entries, one per corrected period and account, on a VAT account and on the
 *     settlement account), `account` ('' where the mandator names none), `debit` / `credit`
 *     (exactly one positive), `text` (the line text of the entry);
 *   - `corrections` — the block «Korrekturen früherer Perioden» the return carries
 *     ({@see VatReturnCorrectionBlock}, one per corrected period; empty without correction entries)
 *     — stored with the return, cleared by the settlement entry as lines of its own (ADR-042
 *     addendum 2 of 2026-11-25, point 2);
 *   - `figuresFingerprint` — SHA-256 over every field's exact value, the settlement lines and the
 *     block (when there is one): the
 *     poster filed THESE figures in the portal first (owner decision 5a, financial.md «MWST buchen»);
 *   - `warningsFingerprint` — the `FiscalYearCloseService::warningsFingerprint()` shape over the
 *     warnings (message, reference), null when there are none;
 *   - `mappingHash` — SHA-256 of the rule file of the very `FormMapping` instance the figures were
 *     computed with ({@see VatReturnService::ruleFileHash()} asked on the computing instance) —
 *     what the posted return stores as `mapping_hash` (ADR-041 addendum 2026-11-24, point 5).
 */
final class VatSettlementPlan
{
    /**
     * @param list<VatReturnFinding> $findings blocking first
     * @param list<array{role: string, account: string, debit: Money, credit: Money, text: string}> $lines the settlement lines in posting order
     * @param list<VatReturnCorrectionBlock> $corrections the block, one per corrected period
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
        public readonly string $mappingHash,
        public readonly array $corrections = [],
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
