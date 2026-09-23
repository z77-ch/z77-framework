<?php

namespace Z77\Module\Debtor\Accounting;

use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The port for an installation WITHOUT z77 bookkeeping (plan §6.6, ADR-040
 * decision 5): `finalize()` sets the document `final`, opens its open amount
 * — and books nothing. Every document keeps a NULL ledger reference; the
 * books are kept elsewhere from the printed documents.
 *
 * Selected explicitly: `debtorConfig → accountingGateway =>
 * NullAccountingGateway::class`. It is never a fallback for a missing
 * financial ({@see AccountingUnavailableException}).
 */
final class NullAccountingGateway implements AccountingGateway
{
    /** The uniform constructor of the port's implementations; nothing here needs either argument. */
    public function __construct(UnifiedEntityManager $em, string $actor)
    {
    }

    public function post(PostingRequest $request): ?string
    {
        return null;
    }
}
