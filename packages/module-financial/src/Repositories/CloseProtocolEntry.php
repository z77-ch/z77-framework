<?php

namespace Z77\Module\Financial\Repositories;

/**
 * One row of the protocol «Abschlüsse und MWST» — a {@see \Z77\Module\Financial\Entities\FiscalYearCloseLog}
 * row (a year closed / reopened) or a `vat_return_log` row (a VAT return posted / lifted), read as
 * plain values by {@see FiscalYearCloseLogRepository::protocolPage()}. Immutable, no entity.
 *
 *   - a YEAR row carries `yearId` / `yearCode`, the reason of a reopen, the confirmed warnings of a close;
 *   - a VAT row carries the period, the revision, the return's id, the reason of a lift and the
 *     event's `detail` (entry, reversal, confirmed warnings — as the writer stored them).
 */
final class CloseProtocolEntry
{
    /**
     * @param string       $kind     one of {@see CloseProtocolSearch::KINDS}
     * @param list<string> $warnings the confirmed warnings (a close, a post)
     * @param array<string, mixed> $detail the VAT event's detail; [] for a year row
     */
    public function __construct(
        public readonly string $kind,
        public readonly int $id,
        public readonly \DateTimeImmutable $actedAt,
        public readonly string $actor,
        public readonly ?int $yearId,
        public readonly ?string $yearCode,
        public readonly ?\DateTimeImmutable $periodFrom,
        public readonly ?\DateTimeImmutable $periodTo,
        public readonly ?int $revision,
        public readonly ?int $vatReturnId,
        public readonly ?string $reason,
        public readonly array $warnings,
        public readonly array $detail,
    ) {}

    public function isVat(): bool
    {
        return $this->vatReturnId !== null;
    }
}
