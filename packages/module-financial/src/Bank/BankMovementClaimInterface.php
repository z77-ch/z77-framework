<?php

namespace Z77\Module\Financial\Bank;

use Z77\Module\Financial\Entities\BankMovement;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * A module CLAIMS a bank movement: it is the module's to link, so the office cannot set it
 * «privat» (owner 2026-11-18). The case it was built for: debtor's collective credit of QR
 * payments — it carries no counterparty, so its pattern key is its text without numbers, and ONE
 * «Privat» would make every such credit private, the coming ones too; a forgotten camt.054 would
 * then vanish as private, the very thing ADR-061 forbids («nothing is private by silence»).
 *
 * Financial names no such module (Rule 9): the classes are declared under the config key
 * `bankMovementClaims` and collected by {@see BankMovementClaims}.
 */
interface BankMovementClaimInterface
{
    /**
     * @return string|null the German sentence the refusal shows — what the movement is and where it
     *                     is linked instead; null when this module does not claim the movement
     */
    public function claimOn(BankMovement $movement, UnifiedEntityManager $em): ?string;
}
