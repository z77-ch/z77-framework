<?php
/**
 * Zahlungseingänge (P4 part 2, plan §6.4) — the imported CAMT.054 messages,
 * newest first, each with its counts per state, and the upload form for
 * the next file (`#bank-upload` — the target of the action cell's
 * «+ camt.054 einlesen», `act.tpl.php`). A page form, no JavaScript
 * (Rule 7); `csrf_token` is the page-mode field (`#[Csrf]`).
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
    <form method="post" action="<?= e($actionBase) ?>/upload" enctype="multipart/form-data" class="be-list__section" id="bank-upload">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">camt.054 einlesen</h2>
        </div>
        <div class="be-form__grid">
            <div class="be-form__field">
                <label for="bank-file">Datei der Bank (camt.054, XML)</label>
                <input type="file" id="bank-file" name="file" accept=".xml,text/xml,application/xml" required>
            </div>
        </div>
        <p class="be-form__hint">Die Meldung wird gespeichert und jede Gutschrift über die QR-Referenz oder die Mitteilung einer definitiven Rechnung zugeordnet. Verbucht wird erst auf der Meldung — nichts passiert beim Einlesen.</p>
        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm">Einlesen</button>
        </div>
    </form>

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
