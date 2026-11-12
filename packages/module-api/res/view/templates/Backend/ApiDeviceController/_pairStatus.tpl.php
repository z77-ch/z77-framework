<?php
/**
 * The pairing window's status box — a LIVE LISTENER (fetch.md FETCH-LIVE-001) while the code
 * waits: `data-fetch-live` asks `pair-status` every few seconds, and the answer replaces this
 * box (time left anew), or turns the window into the paired device's window. Expired, the box
 * renders WITHOUT the attribute: the listening ends, «Neuer Code» is the way on.
 *
 * The bar is a native `<progress>`, the time a server-rendered text — nothing ticks in a
 * script (Rule 7); the text moves in the listener's steps.
 *
 * @var string $ticket      32 hex — this session's key of the code, never the code itself
 * @var bool   $waiting
 * @var int    $expiresAt
 * @var int    $now
 * @var string $statusUrl
 * @var int    $every       seconds between two questions
 */
$left = max(0, $expiresAt - $now);
$ttl  = \Z77\Module\Api\Services\ApiDevices::PAIRING_TTL_SECONDS;
?>
<div class="be-list__facts" data-api-device-pair="<?= e($ticket) ?>"<?= $waiting ? ' data-fetch-live="' . e($statusUrl) . '" data-fetch-live-every="' . (int) $every . '"' : '' ?>>
    <?php if ($waiting): ?>
    <progress max="<?= $ttl ?>" value="<?= min($ttl, $left) ?>" style="width:100%" aria-label="Gültigkeit des Codes"></progress>
    <div class="be-list__cell--muted">gültig bis <?= e(date('H:i', $expiresAt)) ?> · <span class="be-list__cell--mono">noch <?= e(sprintf('%d:%02d', intdiv($left, 60), $left % 60)) ?></span></div>
    <div><strong>Wartet auf das Handy …</strong></div>
    <?php else: ?>
    <div class="be-modal__alert be-modal__alert--error" role="status">Der Code ist abgelaufen oder zurückgezogen. «Neuer Code» erzeugt einen frischen.</div>
    <?php endif; ?>
</div>
