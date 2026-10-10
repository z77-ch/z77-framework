<?php
/**
 * The block state of one country in the «Woher» tally: «gesperrt» or the «Land sperren»
 * trigger. Rendered by the list and by FormLogController's block / unblock answer
 * (`update-html` of `[data-form-log-country="<code>"]`); core.js wires the trigger.
 *
 * @var string $code
 * @var bool   $isBlocked
 * @var bool   $blockedBroken
 * @var string $actionBase
 */
?>
<?php if ($isBlocked): ?>
<span class="badge badge--warning">gesperrt</span>
<?php elseif (!$blockedBroken): ?>
<button type="button" class="be-btn be-btn--ghost be-btn--sm"
        data-fetch-get="<?= e($actionBase) ?>/confirm-block?code=<?= e(rawurlencode($code)) ?>">Land sperren</button>
<?php endif; ?>
