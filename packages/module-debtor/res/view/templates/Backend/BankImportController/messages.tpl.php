<?php
/**
 * The imported messages, newest first, each with its counts per state — the REGION
 * `[data-bank-messages]` of the list: the page renders it, and an upload answers it as
 * `replace-html`, so the files that got through show up without a reload while the rows of
 * the refused ones stay (owner 2026-11-13).
 *
 * @var list<\Z77\Module\Debtor\Entities\BankMessage> $messages
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/bank-import';
?>
<div class="be-list__section" data-bank-messages>
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
    <?php if ($messages === []): ?>
    <p class="be-list__empty">Noch keine Meldung eingelesen.</p>
    <?php endif; ?>
</div>
