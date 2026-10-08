<?php
/**
 * Mahnungen (P4 part 3, plan §6.5) — the due list as of a day, with a
 * checkbox per invoice and «Mahnlauf starten» in the selection bar ABOVE it
 * (green confirm, ADR-033 rev. 2026-10-08); below, the runs so far with
 * their notices and a «PDF» link each. Page forms, no JavaScript (Rule 7);
 * `csrf_token` is the page-mode field (`#[Csrf]`). The as-of day is a GET
 * form of its own (a different day re-reads the list).
 *
 * @var \DateTimeImmutable $asOf
 * @var list<array{invoice: \Z77\Module\Debtor\Entities\Invoice, open: \Z77\Shared\Money\Money, current: ?\Z77\Module\Debtor\Entities\DunningLevel, next: \Z77\Module\Debtor\Entities\DunningLevel, dueSince: \DateTimeImmutable}> $due
 * @var string|null $notice  why the due list could not be built (no levels)
 * @var list<\Z77\Module\Debtor\Entities\DunningRun> $runs
 * @var callable $levelOf  notice → DunningLevel|null
 * @var callable $fmt
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 * @var string $invoiceBase
 */
$actionBase  = $actionBase ?? '/backend/finance/dunning';
$invoiceBase = $invoiceBase ?? '/backend/finance/invoice';
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Fällige Mahnungen <small class="be-list__cell--muted">· Stand <?= e($asOf->format('d.m.Y')) ?></small></h2>
            <span class="be-list__section-badge"><?= count($due) ?></span>
        </div>
        <form method="get" action="<?= e($actionBase) ?>/list" class="be-form__grid">
            <div class="be-form__field">
                <label for="dunning-as-of">Stichtag</label>
                <input type="date" id="dunning-as-of" name="as_of" value="<?= e($asOf->format('Y-m-d')) ?>">
            </div>
            <div class="be-form__field"><label>&nbsp;</label><button type="submit" class="be-btn be-btn--ghost be-btn--sm">Liste aktualisieren</button></div>
        </form>
        <?php if ($notice !== null): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($notice) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= e($actionBase) ?>/run" id="dunning-run">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <input type="hidden" name="as_of" value="<?= e($asOf->format('Y-m-d')) ?>">
            <?php if ($due !== []): ?>
            <?php /* Bound to the ticked rows (ADR-033 exception 2), so it stands with them — ABOVE the
                     list, sticky while it scrolls; the run WRITES notices and fee postings → green. */ ?>
            <div class="z77-form-actions" data-selection-bar="dunning-run">
                <button type="submit" class="be-btn be-btn--confirm be-btn--sm">
                    <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
                    <span class="be-btn__label">Mahnlauf starten</span>
                </button>
                <small class="be-list__cell--muted">für die angekreuzten Rechnungen, datiert auf den <?= e($asOf->format('d.m.Y')) ?></small>
            </div>
            <?php endif; ?>
            <div class="be-list__table" style="--be-list-cols: 2.5rem 7rem minmax(10rem, 2fr) 7rem 8rem 8rem 9rem 7rem">
                <div class="be-list__head">
                    <span class="be-list__col"></span>
                    <span class="be-list__col">Rechnung</span>
                    <span class="be-list__col">Debitor</span>
                    <span class="be-list__col">fällig am</span>
                    <span class="be-list__col be-list__col--num">offen</span>
                    <span class="be-list__col">bisher</span>
                    <span class="be-list__col">nächste Stufe</span>
                    <span class="be-list__col be-list__col--num">Gebühr</span>
                </div>
                <?php if ($due === []): ?>
                <p class="be-list__empty">Nichts fällig am <?= e($asOf->format('d.m.Y')) ?>.</p>
                <?php endif; ?>
                <?php foreach ($due as $item): $inv = $item['invoice']; ?>
                <div class="be-list__item" data-due-invoice="<?= (int) $inv->getId() ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><input type="checkbox" name="doc[]" value="<?= (int) $inv->getId() ?>" checked aria-label="<?= e($inv->documentName()) ?> mahnen"></span>
                        <span class="be-list__cell"><a href="<?= e($invoiceBase) ?>/detail?id=<?= (int) $inv->getId() ?>"><?= e($inv->documentName()) ?></a></span>
                        <span class="be-list__cell be-list__cell--wrap"><?= e($inv->getAddress()->getName()) ?></span>
                        <span class="be-list__cell"><?= e($inv->getDueDate()->format('d.m.Y')) ?> <small class="be-list__cell--muted">· seit <?= e($item['dueSince']->format('d.m.Y')) ?></small></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($item['open'])) ?></span>
                        <span class="be-list__cell"><?= $item['current'] !== null ? e($item['current']->getLabel()) : '<span class="be-list__cell--muted">–</span>' ?></span>
                        <span class="be-list__cell"><span class="badge badge--warning"><?= e($item['next']->getLabel()) ?></span></span>
                        <span class="be-list__cell be-list__cell--num"><?= $item['next']->getFee() > 0 ? e($fmt($item['next']->fee($inv->getCurrency()))) : '–' ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="be-form__hint">Der Mahnlauf erstellt je angekreuzte Rechnung eine Mahnung auf der nächsten Stufe, datiert auf den Stichtag. Trägt die Stufe eine Gebühr, wird ein Gebühren-Beleg erstellt und verbucht (ohne MWST). Die Mahnung als PDF: unten in der Liste.</p>
        </form>
    </div>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Mahnläufe</h2>
            <span class="be-list__section-badge"><?= count($runs) ?></span>
        </div>
        <?php if ($runs === []): ?>
        <p class="be-list__empty">Noch kein Mahnlauf.</p>
        <?php endif; ?>
        <?php foreach ($runs as $run): ?>
        <div class="be-form__section">Mahnlauf vom <?= e($run->getRunDate()->format('d.m.Y')) ?> <small class="be-list__cell--muted">· <?= e($run->getCreatedBy()) ?>, <?= e($run->getCreatedAt()->format('d.m.Y H:i')) ?></small></div>
        <div class="be-list__table" style="--be-list-cols: 7rem minmax(10rem, 2fr) 9rem 8rem 9rem 5rem">
            <?php foreach ($run->getNotices() as $n): $lvl = $levelOf($n); $fee = $n->getFeeInvoice(); ?>
            <div class="be-list__item" data-dunning-notice="<?= (int) $n->getId() ?>">
                <div class="be-list__row">
                    <span class="be-list__cell"><a href="<?= e($invoiceBase) ?>/detail?id=<?= (int) $n->getInvoice()->getId() ?>"><?= e($n->getInvoice()->documentName()) ?></a></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($n->getInvoice()->getAddress()->getName()) ?></span>
                    <span class="be-list__cell"><?= e($lvl?->getLabel() ?? $n->getLevelCode()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($n->getOpenAmount())) ?></span>
                    <span class="be-list__cell"><?= $fee !== null ? '<a href="' . e($invoiceBase . '/detail?id=' . (int) $fee->getId()) . '">' . e($fee->documentName()) . '</a> ' . e($fmt($fee->getGrossTotal())) : '<span class="be-list__cell--muted">keine Gebühr</span>' ?></span>
                    <span class="be-list__cell"><a href="<?= e($actionBase) ?>/notice-pdf?id=<?= (int) $n->getId() ?>" target="_blank" rel="noopener">PDF</a></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
