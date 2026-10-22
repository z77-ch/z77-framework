<?php
/**
 * «Buchungsdruck» (owner 2026-10-10; «Verbuchen» that morning, «Definitiv
 * stellen» until then) — the last look before the booking print: the drafts
 * selected in the invoicing view (or the one draft of a detail), each with
 * the version its row SHOWED (`doc[]` = `{id}:{version}`); no number yet.
 * One button posts the same pairs to `finalize` (`#[Csrf]`) in THIS tab:
 * `InvoicingService::finalize()` draws the numbers and posts — one unit of
 * work, all or nothing, a document re-issued since is refused by its version
 * — and answers with a redirect to the RESULT LIST of exactly these documents
 * (`booking-result?ids=…`, step 3 of the mail plan, owner 2026-10-22: per document the
 * PDF, «Alle als ein PDF», «Alle als ZIP», «Alle per E-Mail senden …»). Until
 * that day the answer was the combined PDF in a new tab (a blank target +
 * `data-submit-navigate`) — both gone: the result list replaces this page. A
 * repeated submit lands on the same result list; a refusal is a message page.
 * A selection that is already stale here (final, gone, re-issued) is named
 * and left out of the button.
 *
 * The action row (ADR-049, revision 2026-10-10) stands FIRST in the form —
 * the module's one shape for dialogs, `--end` is not used. Still a PAGE
 * (debtor.md DEBTOR-WIN-002): the selection bar's GET form carries the
 * ticked rows, and core.js has no opener that turns a GET form into a
 * window yet.
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
        <?= $this->partial('partials/modalActions', [
            'submit'     => $documents !== [] ? 'Buchungsdruck' : '',
            'cancel'     => 'Zur Liste',
            'cancelHref' => $actionBase . '/list',
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Buchungsdruck <small class="be-list__cell--muted">· <?= count($documents) ?> Dokument<?= count($documents) === 1 ? '' : 'e' ?><?= $total !== null ? ' · ' . e($fmt($total)) : '' ?></small></h2>
        </div>
        <?php if ($stale !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">Seit der Auswahl geändert, bereits verbucht oder nicht mehr vorhanden — ausgelassen: <?= e(implode(', ', array_map(static fn(int $id) => '#' . $id, $stale))) ?>. Liste neu laden.</div>
        <?php endif; ?>
        <div class="be-list__table" style="--be-list-cols: 5rem 6.5rem minmax(10rem, 2fr) 8rem">
            <?php foreach ($documents as $document): ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($document->kind()->label()) ?><input type="hidden" name="doc[]" value="<?= e($document->getId() . ':' . $document->getVersion()) ?>"></span>
                    <span class="be-list__cell"><?= e($document->getInvoiceDate()->format('d.m.Y')) ?></span>
                    <span class="be-list__cell"><?= e(trim($document->getAddress()->getFirstName() . ' ' . $document->getAddress()->getName())) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($document->getGrossTotal())) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="be-form__hint">Der Buchungsdruck vergibt die Belegnummern, verbucht die Dokumente und zeigt sie danach als Liste — jedes PDF einzeln, alle als ein PDF, als ZIP oder per E-Mail. Verbuchte Dokumente sind unveränderlich — eine Korrektur ist eine Gutschrift; das PDF lässt sich jederzeit wieder öffnen.</p>
    </form>
</div>
