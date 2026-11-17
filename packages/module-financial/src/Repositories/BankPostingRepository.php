<?php

namespace Z77\Module\Financial\Repositories;

use Z77\Module\Financial\Entities\BankPosting;
use Z77\Persistence\Doctrine\Repository\DoctrineRepository;

/**
 * Convention repository for {@see BankPosting} — the direct postings of a bank movement.
 * Doctrine-only (ADR-039 decision 8).
 */
class BankPostingRepository extends DoctrineRepository
{
    /** The posting that books the movement NOW (not taken back), or null. */
    public function currentOf(int $movementId): ?BankPosting
    {
        return $this->findOneBy(['movement' => $movementId, 'takenBackAt' => null]);
    }

    /**
     * The direct postings WITH a tax code and WITHOUT an archived voucher whose entry is dated from
     * $from to $to (`Y-m-d`, inclusive), not taken back: how many, and the tax of their entries —
     * the control line «Vorsteuer ohne Beleg» of the VAT return (ADR-061 decision 26). Doctrine-only (SQL).
     *
     * @return array{count: int, tax: string}
     */
    public function taxWithoutVoucher(string $from, string $to): array
    {
        $row = $this->connection()->fetchAssociative(
            'SELECT COUNT(DISTINCT p.id) AS n, COALESCE(SUM(l.tax_amount), 0) AS tax
               FROM bank_posting p
               JOIN fiscal_year y ON y.code = p.entry_year
               JOIN journal_entry e ON e.fiscal_year_id = y.id AND e.number = p.entry_number
               LEFT JOIN journal_line l ON l.entry_id = e.id AND l.tax_code IS NOT NULL
              WHERE p.tax_code IS NOT NULL AND p.voucher_path IS NULL AND p.taken_back_at IS NULL AND e.entry_date BETWEEN ? AND ?',
            [$from, $to]
        );

        return ['count' => (int) ($row['n'] ?? 0), 'tax' => (string) ($row['tax'] ?? '0.00')];
    }
}
