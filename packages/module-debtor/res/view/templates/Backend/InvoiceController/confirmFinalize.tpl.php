<?php
/**
 * «Definitiv stellen» — the last look before the batch (P3 part 3): the
 * documents selected in the invoicing view, each with the version its row
 * SHOWED (`doc[]` = `{id}:{version}`). One button posts the same pairs to
 * `finalize` (`#[Csrf]`), which hands them to `InvoicingService::finalize()`:
 * one unit of work, all or nothing — a document re-issued since is refused
 * by its version, nothing is posted then. A selection that is already stale
 * here (final, gone, re-issued) is named and left out of the button.
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents  still in invoicing at the version shown
 * @var list<array{id: int, version: int}> $pairs
 * @var list<int> $stale  ids that changed since the list was shown
 * @var \Z77\Shared\Money\Money|null $total
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/invoice';
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase) ?>/finalize" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Definitiv stellen <small class="be-list__cell--muted">· <?= count($documents) ?> Dokument<?= count($documents) === 1 ? '' : 'e' ?><?= $total !== null ? ' · ' . e($fmt($total)) : '' ?></small></h2>
        </div>
        <?php if ($stale !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">Seit der Auswahl geändert, definitiv oder nicht mehr vorhanden — ausgelassen: <?= e(implode(', ', array_map(static fn(int $id) => '#' . $id, $stale))) ?>. Liste neu laden.</div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 5rem 6.5rem minmax(10rem, 2fr) 8rem">
            <?php foreach ($documents as $document): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--num be-list__cell--mono"><?= e($document->documentName()) ?><input type="hidden" name="doc[]" value="<?= e($document->getId() . ':' . $document->getVersion()) ?>"></span>
                    <span class="be-list__cell"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($document->getGrossTotal())) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Definitiv gestellte Dokumente werden verbucht und sind danach unveränderlich — eine Korrektur ist eine Gutschrift.</p>
        <div class="z77-form-actions">
            <?php if ($documents !== []): ?>
            <button type="submit" class="be-btn be-btn--primary be-btn--sm">Definitiv stellen und verbuchen</button>
            <?php endif; ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/list">Abbrechen</a>
        </div>
    </form>
</div>
