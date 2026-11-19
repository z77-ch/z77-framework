<?php

namespace Z77\Module\Financial\Close;

use Z77\Core\DI;
use Z77\Module\Financial\Bank\BankEntryOwners;
use Z77\Module\Financial\Bank\BankReconciliation;
use Z77\Persistence\Doctrine\OpenWork\Finding;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkCheckInterface;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;

/**
 * Bank-driven bookkeeping at the close (ADR-061 decisions 25, 26, stage C4) — financial's answer
 * to the open-work registry for the scopes `period-close` (the year close; a month close once one
 * exists) and `vat-return`, registered in `financialConfig → openWorkChecks`. Parameters `from`
 * and `to` (`DateTimeImmutable`), as every check of those scopes gets them.
 *
 * For every ACTIVE bank account that is bank-driven («Kontoauszug einlesen» on, «Abgleich ab»
 * set) and whose driven time reaches into the range, BLOCKING — never downgraded to a warning:
 *
 *   - the statement is not read from «Abgleich ab» / up to the end of the range;
 *   - movements of the range are still «zu prüfen»;
 *   - the check fails: the change of the bank's ledger account ≠ the business movements — the
 *     difference and the postings without a movement are named (`BankCheck::problems()`);
 *   - once for all accounts: the transfer clearing account (the mandator's `bank-transfer`) is
 *     not at zero at the end of the range — one side of a transfer between own accounts is missing.
 *
 * WARNINGS: at the VAT return the control line «n Buchungen mit Vorsteuer ohne Beleg · CHF x
 * Vorsteuer» (direct postings with a tax code and no archived voucher — the owner's risk at an
 * audit, decision 15); at the year close an anchor that is not confirmed or no longer agrees
 * (decision 24: a mismatch asks, it does not block) — on an account that is not «gemischt geführt»
 * the warning says to correct the difference by a booking, since nothing is confirmed there (owner
 * 2026-11-19).
 *
 * An account that is switched off, or without «Abgleich ab», says nothing: the check stops there
 * (decision 3). Reads in the caller's snapshot, writes nothing.
 */
final class BankReconciliationCheck implements OpenWorkCheckInterface
{
    /** The link target of a finding — Finanzen › Bankkonto. */
    private const AREA = 'bank-movement';

    /** Both arguments are for a harness; the registry instantiates the check without any. */
    public function __construct(private readonly ?UnifiedEntityManager $em = null, private readonly ?BankEntryOwners $owners = null) {}

    public function check(string $scope, array $parameters): iterable
    {
        $from = $parameters['from'] ?? null;
        $to   = $parameters['to'] ?? null;
        if (!$from instanceof \DateTimeImmutable || !$to instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Open-work scope '{$scope}' passes 'from' and 'to' as DateTimeImmutable — got " . get_debug_type($from) . ' / ' . get_debug_type($to));
        }
        $reconciliation = new BankReconciliation($this->em ?? DI::getUnifiedEntityManager(), null, $this->owners);
        $any            = false;
        foreach ($reconciliation->drivenAccounts() as $account) {
            $check = $reconciliation->check($account, $from, $to);
            if ($check === null) {
                continue;   // the whole range lies before «Abgleich ab»
            }
            $any = true;
            foreach ($check->problems() as $problem) {
                yield Finding::blocking($problem, self::AREA . ':' . $account->getCode());
            }
            if ($scope !== 'vat-return') {
                $anchor = $reconciliation->anchor($account, $from);
                if ($anchor !== null && $anchor['state'] === BankReconciliation::ANCHOR_MISMATCH && !$account->isMixedUse()) {
                    // A purely business account confirms nothing (owner 2026-11-19): the difference is corrected by a booking.
                    yield Finding::warning(
                        'Bankkonto «' . $account->getLabel() . '»: die Differenz Bank ↔ Buchhaltung per ' . $anchor['asOf']->format('d.m.Y') . ' (' . AmountFormat::of($anchor['difference']) . ') ist nicht '
                        . AmountFormat::of($anchor['expected']) . ' — das Konto ist nicht «gemischt geführt»: die Differenz mit einer Buchung korrigieren.',
                        self::AREA . ':' . $account->getCode()
                    );
                } elseif ($anchor !== null && in_array($anchor['state'], [BankReconciliation::ANCHOR_OPEN, BankReconciliation::ANCHOR_MISMATCH], true)) {
                    yield Finding::warning(
                        'Bankkonto «' . $account->getLabel() . '»: die Differenz Bank ↔ Buchhaltung per ' . $anchor['asOf']->format('d.m.Y') . ' (' . AmountFormat::of($anchor['difference']) . ') ist '
                        . ($anchor['state'] === BankReconciliation::ANCHOR_OPEN ? 'noch nicht bestätigt' : 'nicht mehr die bestätigte (' . AmountFormat::of($anchor['expected']) . ')')
                        . ' — unter Finanzen › Bankkonto, «Abgleich …» bestätigen.',
                        self::AREA . ':' . $account->getCode()
                    );
                }
            }
        }
        if (!$any) {
            return;
        }
        $transfer = $reconciliation->transferBalance($to);
        if ($transfer !== null && !$transfer['balance']->isZero()) {
            yield Finding::blocking(
                'Übertrag eigene Bankkonten: Konto ' . $transfer['account']->label() . ' steht per ' . $to->format('d.m.Y') . ' auf ' . AmountFormat::of($transfer['balance'])
                . ' statt 0.00 — bei einem Übertrag zwischen zwei eigenen Konten fehlt eine Seite (Finanzen › Bankkonto, «Geschäftlich buchen …» › «Übertrag eigenes Konto»).',
                'account:' . $transfer['account']->getNumber()
            );
        }
        if ($scope === 'vat-return') {
            $tax = $reconciliation->taxWithoutVoucher($from, $to);
            if ($tax['count'] > 0) {
                yield Finding::warning(
                    ($tax['count'] === 1 ? '1 Buchung' : $tax['count'] . ' Buchungen') . ' mit Vorsteuer ohne Beleg · ' . $tax['tax']->currency . ' ' . AmountFormat::of($tax['tax']) . ' Vorsteuer',
                    self::AREA
                );
            }
        }
    }
}
