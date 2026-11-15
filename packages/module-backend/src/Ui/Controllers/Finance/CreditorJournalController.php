<?php
namespace Z77\Module\Backend\Ui\Controllers\Finance;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Creditor\Ui\CreditorJournalControllerTrait;

/**
 * Backend mount of the «Kreditorenjournal» (creditor plan §8, ADR-018 pattern): all logic and
 * templates live in `module-creditor` ({@see CreditorJournalControllerTrait}); this host only
 * mounts it under the backend route + auth + shell (module default role: ADMIN). The layout is
 * pinned via `Ui/Config/Finance/creditorJournalControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-creditor.
 *
 * URL: /backend/finance/creditor-journal/list (Finanzen › Kreditorenjournal).
 */
class CreditorJournalController extends BackendAbstractController
{
    use CreditorJournalControllerTrait;
}
