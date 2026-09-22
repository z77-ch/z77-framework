<?php
/**
 * Account statement (Kontoblatt, plan §5.5): one account over the range —
 * the opening balance (lines of the same fiscal year before «Von»), then
 * every line in date / number order with the counter account (the one other
 * account of the entry, «div.» when there are several), text, Soll, Haben
 * and the running balance, then the totals and the closing balance. Every
 * balance positive on the account's natural side. One page of
 * `LedgerReports::ACCOUNT_STATEMENT_PAGE_SIZE` lines; a later page starts
 * with the «Übertrag» (the balance before its first line). The entry number
 * links to the entry's detail page in the journal.
 *
 * Styling: the shared backend list v2 classes only.
 *
 * @var \Z77\Module\Financial\Reports\AccountStatement|null $report  null until an account is chosen
 * @var \Z77\Module\Financial\Reports\ReportRange $range
 * @var bool $accountMissing
 * @var string $accountNumber
 * @var callable $link
 * @var callable $fmt
 * @var string $journalBase
 */
$ns   = 'Z77\\Module\\Financial';
$cols = '--be-list-cols: 6rem 4rem minmax(12rem, 2fr) minmax(8rem, 1fr) 8rem 8rem 9rem';
?>
<div class="be-list">
    <?= $this->partial('Backend/ReportController/rangeForm', ['accounts' => $accounts, 'accountNumber' => $accountNumber] + ['range' => $range, 'tab' => $tab, 'link' => $link, 'months' => $months, 'reportBase' => $reportBase, 'notices' => $notices, 'keep' => $keep], $ns) ?>
    <?php if ($report === null): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Kontoblatt</h2>
        </div>
        <p class="be-list__empty"><?= $accountMissing ? 'Das Konto «' . e($accountNumber) . '» gibt es nicht.' : 'Konto wählen, um das Kontoblatt zu sehen.' ?></p>
    </div>
    <?php else:
        $account = $report->account;
        $paging  = $report->paging;
    ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Kontoblatt <code><?= e($account->getNumber()) ?></code> <?= e($account->getName()) ?>
                <small class="be-list__cell--muted">· <?= e($range->year->getCode()) ?> · <?= e($range->from->format('d.m.Y')) ?> – <?= e($range->to->format('d.m.Y')) ?><?= $paging->pageCount > 1 ? ' · Seite ' . $paging->page . ' von ' . $paging->pageCount : '' ?></small>
            </h2>
            <span class="be-list__section-badge" title="Zeilen im Zeitraum"><?= $paging->total ?></span>
        </div>
        <div class="be-list__frame">
            <div class="be-list__table" style="<?= $cols ?>">
                <div class="be-list__head">
                    <span class="be-list__col">Datum</span>
                    <span class="be-list__col be-list__col--num">Nr.</span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col">Gegenkonto</span>
                    <span class="be-list__col be-list__col--num">Soll</span>
                    <span class="be-list__col be-list__col--num">Haben</span>
                    <span class="be-list__col be-list__col--num">Saldo</span>
                </div>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?= $paging->page === 1 ? e($range->from->format('d.m.Y')) : '' ?></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><em><?= $paging->page === 1 ? 'Anfangssaldo' : 'Übertrag von Seite ' . ($paging->page - 1) ?></em></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--num"><em><?= e($fmt($report->carry)) ?></em></span>
                    </div>
                </div>
                <?php foreach ($report->lines as $line): ?>
                <div class="be-list__item" data-entry-id="<?= e((string) $line->entryId) ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?= e($line->date->format('d.m.Y')) ?></span>
                        <span class="be-list__cell be-list__cell--num be-list__cell--mono"><a href="<?= e($journalBase) ?>/detail?id=<?= e((string) $line->entryId) ?>"><?= $line->entryNumber ?></a></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($line->text) ?><?= $line->lineText !== null && $line->lineText !== '' ? ' <small class="be-list__cell--muted">· ' . e($line->lineText) . '</small>' : '' ?></span>
                        <span class="be-list__cell"><?= $line->hasSeveralCounterAccounts() ? 'div.' : ($line->counterNumber === null ? '–' : '<code>' . e($line->counterNumber) . '</code> ' . e((string) $line->counterName)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->debit->isZero() ? '' : e($fmt($line->debit)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= $line->credit->isZero() ? '' : e($fmt($line->credit)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($line->balance)) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if ($report->lines === []): ?>
                <div class="be-list__item"><div class="be-list__row">
                    <span class="be-list__cell"></span><span class="be-list__cell"></span>
                    <span class="be-list__cell be-list__cell--muted">Keine Buchung im Zeitraum.</span>
                    <span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span>
                </div></div>
                <?php endif; ?>
                <?php if ($paging->page === $paging->pageCount): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?= e($range->to->format('d.m.Y')) ?></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong>Total Zeitraum / Schlusssaldo</strong></span>
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalDebit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->totalCredit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($report->closing)) ?></strong></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <p class="be-form__hint">Anfangssaldo <?= e($fmt($report->opening)) ?> (Buchungen dieses Geschäftsjahres vor dem <?= e($range->from->format('d.m.Y')) ?>) · Saldo positiv auf der natürlichen Seite des Kontos (<?= \Z77\Module\Financial\Entities\AccountType::from($account->getType())->isDebitNormal() ? 'Soll' : 'Haben' ?>).</p>
        <?= $this->partial('Backend/ReportController/pager', [
            'paging'   => $paging,
            'unit'     => 'Zeilen',
            'pageLink' => fn(int $p) => $link('account-statement', ['account' => $account->getNumber(), 'page' => $p]),
        ], $ns) ?>
    </div>
    <?php endif; ?>
</div>
