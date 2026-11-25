<?php
/**
 * The refusal of a window's POST («MWST jetzt buchen», «Aufheben») — replaces the window's alert
 * slot (`replace-html`), the window stays and nothing was written: the service's sentence as it is
 * and, for `blocked` / `warnings-*`, the findings it refers to. The slot keeps its own marker so a
 * second refusal replaces this one.
 *
 * @var 'post'|'lift' $slot  the window's alert slot
 * @var string $message
 * @var list<\Z77\Module\Financial\VatReturn\VatReturnFinding> $findings
 */
?>
<div data-vat-<?= $slot === 'lift' ? 'lift' : 'post' ?>-refusal data-vat-window-refusal>
    <div class="be-modal__alert be-modal__alert--error" role="alert"><?= e($message) ?></div>
    <?php if ($findings !== []): ?>
    <ul data-vat-window-findings>
        <?php foreach ($findings as $finding): ?>
        <li><?= e($finding->message) ?></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
