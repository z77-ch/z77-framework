<?php
/**
 * «Journal verbuchen …» — the last look (ADR-054 decision 6, a PAGE like the confirmation of
 * «Fakturieren»): how many documents, their total, the totals per VAT code, and the journal
 * entries the posting books — summed per account, from the same `PostingBuilder` the posting
 * calls. The form carries every document's `{id}:{version}` (the journal as THIS page shows it);
 * one green button posts them (`#[Csrf]`) to `post`. Blocked (a document flagged «Neu
 * fakturieren nötig»), empty, or without a receivable account: the reason, no button. The action
 * row stands FIRST (ADR-049).
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents
 * @var array<string, mixed> $totals
 * @var list<array{id: int, name: string, reason: string}> $blockers
 * @var array{entries: int, rows: list<array{account: string, debit: \Z77\Shared\Money\Money, credit: \Z77\Shared\Money\Money}>}|null $preview
 * @var string|null $problem
 * @var string $currency
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Shared\Money\Money;

$actionBase = $actionBase ?? '/backend/finance/invoice-journal';
$money      = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);
$canPost    = $documents !== [] && $blockers === [] && $problem === null;
?>
<div class="be-list" data-journal-confirm>
    <form method="post" action="<?= e($actionBase) ?>/post" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?= $this->partial('partials/modalActions', [
            'submit'     => $canPost ? 'Journal verbuchen' : '',
            'cancel'     => 'Zum Journal',
            'cancelHref' => $actionBase . '/list',
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Journal verbuchen <small class="be-list__cell--muted">· <?= count($documents) ?> <?= count($documents) === 1 ? 'Dokument' : 'Dokumente' ?> · <?= e($fmt($money($totals['gross']))) ?></small></h2>
        </div>
        <?php if ($documents === []): ?>
        <div class="be-modal__alert">Das Faktura-Journal ist leer — nichts zu verbuchen.</div>
        <?php endif; ?>
        <?php if ($blockers !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">Verbuchen gesperrt — zuerst neu fakturieren: <?= e(implode('; ', array_map(static fn(array $b) => $b['name'] . ' (' . $b['reason'] . ')', $blockers))) ?>.</div>
        <?php endif; ?>
        <?php if ($problem !== null): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($problem) ?></div>
        <?php endif; ?>
        <?php foreach ($documents as $document): ?>
        <input type="hidden" name="doc[]" value="<?= e($document->getId() . ':' . $document->getVersion()) ?>">
        <?php endforeach; ?>

        <?php if ($totals['vat'] !== []): ?>
        <h3 class="be-list__section-title">MWST je Code</h3>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(10rem, 2fr) 6rem 9rem 9rem">
            <?php foreach ($totals['vat'] as $vat): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e($vat['code']) ?></span>
                <span class="be-list__cell"><?= e($vat['label']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(TaxRate::formatPercent($vat['rate'])) ?> %</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($money($vat['base']))) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($money($vat['tax']))) ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($preview !== null && $preview['rows'] !== []): ?>
        <h3 class="be-list__section-title">Was gebucht wird <small class="be-list__cell--muted">· <?= (int) $preview['entries'] ?> Journaleinträge (je Dokument einer, Datum = Rechnungsdatum), summiert je Konto</small></h3>
        <div class="be-list__table" style="--be-list-cols: minmax(10rem, 1fr) 9rem 9rem" data-journal-preview>
            <div class="be-list__head">
                <span class="be-list__col">Konto</span>
                <span class="be-list__col be-list__col--num">Soll</span>
                <span class="be-list__col be-list__col--num">Haben</span>
            </div>
            <?php foreach ($preview['rows'] as $row): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e($row['account']) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $row['debit']->isZero() ? '' : e($fmt($row['debit'])) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $row['credit']->isZero() ? '' : e($fmt($row['credit'])) ?></span>
            </div></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p class="be-form__hint">Verbucht wird immer das <strong>ganze</strong> Journal, in einem Schritt — alles oder nichts. Danach sind die Rechnungen <strong>verbucht</strong> und unveränderlich (eine Korrektur ist eine Gutschrift), Zahlungen lassen sich zuordnen. Ist seit dieser Seite ein Dokument dazugekommen oder geändert worden, wird nichts verbucht und die Seite muss neu geladen werden.</p>
    </form>
</div>
