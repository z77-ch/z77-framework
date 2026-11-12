<?php
/**
 * «Journal verbuchen …» — the last look (board «Belegjournal» part 3, a PAGE like the Faktura-
 * Journal's `confirmPost`): the CUT-OFF (E11 — every captured receipt dated on or before it; later
 * ones stay in the journal), how many receipts and their total, what blocks the run, the VAT per
 * code and the entries the posting books summed per account — all from
 * `ReceiptJournalPostingService::preview()`, the builder the posting calls.
 *
 * Three parts: the POST form first with the action row (ADR-049: «Journal verbuchen» only while the
 * run is postable, «Zum Journal» quiet at the right), carrying the cut-off and every receipt's
 * `{id}:{version}` (`#[Csrf]`); the GET form of the cut-off («Anzeigen» recomputes the page); then
 * the run. Blocked: the reasons, each receipt a link to its window, no button.
 *
 * @var array<string, mixed> $preview  ReceiptJournalPostingService::preview()
 * @var \DateTimeImmutable $cutOff
 * @var string $note
 * @var list<array{id: int, label: string, reason: string}> $blockers
 * @var int $later  captured receipts after the cut-off
 * @var array<string, string> $taxLabels
 * @var array<string, string> $accountNames
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$seen       = $preview['seen'];
$count      = count($seen);
$canPost    = (bool) $preview['postable'];
?>
<div class="be-list" data-journal-confirm>
    <form method="post" action="<?= e($actionBase) ?>/post" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="cutoff" value="<?= e($cutOff->format('Y-m-d')) ?>">
        <?php foreach ($seen as $pair): ?>
        <input type="hidden" name="rec[]" value="<?= (int) $pair['id'] . ':' . (int) $pair['version'] ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $canPost ? 'Journal verbuchen' : '',
            'cancel'     => 'Zum Journal',
            'cancelHref' => $actionBase . '/list',
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Journal verbuchen <small class="be-list__cell--muted">· bis <?= e($cutOff->format('d.m.Y')) ?> · <?= $count ?> <?= $count === 1 ? 'Beleg' : 'Belege' ?><?= $canPost ? ' · ' . e($fmt($preview['gross'])) : '' ?></small></h2>
        </div>
    </form>

    <form method="get" action="<?= e($actionBase) ?>/confirm-post" class="be-list__section" data-journal-cutoff>
        <div class="be-form__grid be-form__grid--fill">
            <div class="be-form__field">
                <label for="receipt-cutoff">Stichtag</label>
                <input type="date" id="receipt-cutoff" name="cutoff" value="<?= e($cutOff->format('Y-m-d')) ?>" required data-help-key="cutoff">
            </div>
            <div class="be-form__field">
                <button type="submit" class="be-btn be-btn--ghost">Anzeigen</button>
            </div>
        </div>
        <?php if ($note !== ''): ?><p class="be-form__hint be-form__hint--warn"><?= e($note) ?></p><?php endif; ?>
        <p class="be-form__hint">Verbucht werden alle Belege mit Datum bis und mit Stichtag<?= $later > 0 ? ' — ' . ($later === 1 ? '1 späterer Beleg bleibt' : $later . ' spätere Belege bleiben') . ' im Journal' : '' ?>.</p>
    </form>

    <div class="be-list__section">
        <?php if ($count === 0): ?>
        <div class="be-modal__alert">Bis zum Stichtag <?= e($cutOff->format('d.m.Y')) ?> ist kein Beleg im Belegjournal — nichts zu verbuchen.</div>
        <?php endif; ?>
        <?php if ($blockers !== []): ?>
        <div class="be-modal__alert be-modal__alert--error" data-journal-blocked="<?= count($blockers) ?>">
            <strong>Verbuchen gesperrt — zuerst ergänzen, umdatieren oder löschen:</strong>
            <?php foreach ($blockers as $i => $blocker): $url = $actionBase . '/edit?id=' . (int) $blocker['id']; ?><?= $i > 0 ? ' · ' : ' ' ?><a href="<?= e($url) ?>" data-window-open="<?= e($url) ?>"><?= e($blocker['label']) ?></a> <small>(<?= e($blocker['reason']) ?>)</small><?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($canPost && ($preview['vat'] !== [] || !$preview['withoutVat']->isZero())): ?>
        <h3 class="be-list__section-title">MWST je Code</h3>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(10rem, 2fr) 6rem 9rem 9rem" data-journal-vat>
            <div class="be-list__head">
                <span class="be-list__col">MWST-Code</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Satz</span>
                <span class="be-list__col be-list__col--num">Basis</span>
                <span class="be-list__col be-list__col--num">MWST</span>
            </div>
            <?php foreach ($preview['vat'] as $vat): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e($vat['code']) ?></span>
                <span class="be-list__cell"><?= e($taxLabels[$vat['code']] ?? '') ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(TaxRate::formatPercent($vat['rate'])) ?> %</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($vat['base'])) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($vat['tax'])) ?></span>
            </div></div>
            <?php endforeach; ?>
            <?php if (!$preview['withoutVat']->isZero()): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono">ohne</span>
                <span class="be-list__cell">ohne MWST</span>
                <span class="be-list__cell be-list__cell--num">—</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($preview['withoutVat'])) ?></span>
                <span class="be-list__cell be-list__cell--num">—</span>
            </div></div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($canPost && $preview['rows'] !== []): ?>
        <h3 class="be-list__section-title">Was gebucht wird <small class="be-list__cell--muted">· <?= (int) $preview['entries'] ?> Journaleinträge (je Beleg einer, Datum = Belegdatum), summiert je Konto</small></h3>
        <div class="be-list__table" style="--be-list-cols: minmax(10rem, 1fr) 9rem 9rem" data-journal-preview>
            <div class="be-list__head">
                <span class="be-list__col">Konto</span>
                <span class="be-list__col be-list__col--num">Soll</span>
                <span class="be-list__col be-list__col--num">Haben</span>
            </div>
            <?php $debit = \Z77\Shared\Money\Money::zero($preview['gross']->currency); $credit = $debit; ?>
            <?php foreach ($preview['rows'] as $row): $debit = $debit->add($row['debit']); $credit = $credit->add($row['credit']); ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell"><span class="be-list__cell--mono"><?= e($row['account']) ?></span> <?= e($accountNames[$row['account']] ?? '') ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $row['debit']->isZero() ? '' : e($fmt($row['debit'])) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= $row['credit']->isZero() ? '' : e($fmt($row['credit'])) ?></span>
            </div></div>
            <?php endforeach; ?>
            <div class="be-list__item"><div class="be-list__row">
                <strong class="be-list__cell">Total</strong>
                <strong class="be-list__cell be-list__cell--num"><?= e($fmt($debit)) ?></strong>
                <strong class="be-list__cell be-list__cell--num"><?= e($fmt($credit)) ?></strong>
            </div></div>
        </div>
        <?php endif; ?>
        <p class="be-form__hint">Verbucht wird immer der <strong>ganze</strong> Lauf bis zum Stichtag, in einem Schritt — alles oder nichts. Danach sind die Belege <strong>verbucht</strong> und unveränderlich; das Foto bleibt am Beleg. Ist seit dieser Seite ein Beleg bis zum Stichtag dazugekommen (z.B. vom Handy) oder geändert worden, wird nichts verbucht und die Seite muss neu geladen werden.</p>
    </div>
</div>
