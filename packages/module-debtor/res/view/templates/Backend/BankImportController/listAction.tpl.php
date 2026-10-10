<?php
/**
 * Zahlungseingänge (P4 part 2, plan §6.4) — the imported CAMT.054 messages,
 * newest first, each with its counts per state.
 *
 * The upload itself lives in the ACTION CELL (`act.tpl.php`, UPLOAD-001): a click opens
 * the file dialog, a drop anywhere on this work area is taken, and the queue of that
 * component is moved into `[data-upload-progress]` here — the cell is 210px wide, a
 * progress bar is not. Owner 2026-10-10, after the live look: an upload box in the middle
 * of the page is a ceremony this everyday task does not need.
 *
 * Styling: the shared backend list classes only.
 *
 * @var list<\Z77\Module\Debtor\Entities\BankMessage> $messages
 * @var array<string, string> $states
 * @var string $csrfToken  provided by html()
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/bank-import';
?>
<div class="be-list">
    <?php // NO upload box in the middle (owner 2026-10-10, after the live look): reading a
          // camt.054 is everyday work, the action cell's button is the whole surface it
          // needs. What stays here is the AREA — files dropped anywhere on the work area
          // are taken — and the place the PROGRESS goes: `upload.js` moves the queue of a
          // `cell` component into `[data-upload-progress]`, because rows and bars are
          // unreadable in a 210px action cell. ?>
    <div class="be-list__section" id="bank-upload" data-upload-area data-upload-progress>
        <p class="be-form__hint">Die Meldung wird gespeichert und jede Gutschrift über die QR-Referenz oder die Mitteilung einer definitiven Rechnung zugeordnet. Verbucht wird erst auf der Meldung — nichts passiert beim Einlesen.</p>
    </div>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Meldungen</h2>
            <span class="be-list__section-badge"><?= count($messages) ?></span>
        </div>
        <div class="be-list__table" style="--be-list-cols: 7rem minmax(10rem, 2fr) 9rem 6rem 6rem 6rem 6rem">
            <div class="be-list__head">
                <span class="be-list__col">Importiert</span>
                <span class="be-list__col">Meldung</span>
                <span class="be-list__col">Konto</span>
                <span class="be-list__col be-list__col--num">offen</span>
                <span class="be-list__col be-list__col--num">zugeordnet</span>
                <span class="be-list__col be-list__col--num">verbucht</span>
                <span class="be-list__col be-list__col--num">ignoriert</span>
            </div>
            <?php if ($messages === []): ?>
            <p class="be-list__empty">Noch keine Meldung eingelesen.</p>
            <?php endif; ?>
            <?php foreach ($messages as $message): $counts = $message->countPerState(); ?>
            <div class="be-list__item">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($message->getImportedAt()->format('d.m.Y H:i')) ?></span>
                    <span class="be-list__cell be-list__cell--wrap">
                        <a href="<?= e($actionBase) ?>/detail?id=<?= (int) $message->getId() ?>"><?= e($message->getMessageId()) ?></a>
                        <small class="be-list__cell--muted">· <?= e($message->getFileName()) ?> · vom <?= e($message->getCreatedOn()->format('d.m.Y')) ?></small>
                    </span>
                    <span class="be-list__cell be-list__cell--mono"><?= e($message->getPaymentTargetCode()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $counts['unmatched'] > 0 ? '<span class="badge badge--warning">' . $counts['unmatched'] . '</span>' : '0' ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= $counts['matched'] > 0 ? '<span class="badge badge--info">' . $counts['matched'] . '</span>' : '0' ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= (int) $counts['booked'] ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= (int) $counts['ignored'] ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
