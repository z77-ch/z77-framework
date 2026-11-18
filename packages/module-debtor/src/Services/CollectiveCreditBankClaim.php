<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Financial\Bank\BankMovementClaimInterface;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Payment\Iban;

/**
 * Debtor claims the COLLECTIVE CREDIT of QR payments (owner 2026-11-18): a credit the bank codes
 * `PMNT/RCDT/VCOM` (a credit transfer with a QR / ISR reference) on a bank account whose IBAN an
 * ACTIVE payment target carries (plain or QR-IBAN). Such a credit is customer payments — the
 * camt.054 names them, «Bankabgleich …» links them — and it must never be set «privat»: it has
 * no counterparty, so ONE «Privat» would teach a pattern that takes every coming collective
 * credit, and a camt.054 nobody read would vanish as private.
 *
 * Declared under `bankMovementClaims` in the debtor config; loaded only when module-financial is
 * installed and asks. A bank that codes its collective credits otherwise is not recognised —
 * then «Privat» works as before (bank.md BANK-CLAIM-001).
 */
final class CollectiveCreditBankClaim implements BankMovementClaimInterface
{
    /** ISO 20022 bank transaction code: payments / received credit transfers / with a QR or ISR reference. */
    public const QR_CREDIT = 'PMNT/RCDT/VCOM';

    public function claimOn(BankMovement $movement, UnifiedEntityManager $em): ?string
    {
        if (!$movement->isCredit() || $movement->getBankTxCode() !== self::QR_CREDIT) {
            return null;
        }
        $iban = $movement->getBankAccount()->getIban();
        foreach ($em->getRepository(PaymentTarget::class)->allInOrder() as $target) {
            if ($target->isActive() && in_array($iban, [Iban::normalize($target->getIban()), Iban::normalize($target->getQrIban())], true)) {
                return 'Die Gutschrift vom ' . $movement->getBookingDate()->format('d.m.Y') . ' ist ein Sammeleingang von QR-Zahlungen — sie gehört zu den Zahlungseingängen und lässt sich nicht privat setzen. '
                    . 'Zugeordnet wird sie unter Debitoren › Zahlungseingänge mit «Bankabgleich …».';
            }
        }

        return null;
    }
}
