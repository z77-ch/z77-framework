<?php
/**
 * One VAT return that cannot be shown — the settings gate (not liable, method, rhythm, «Abrechnung
 * ab»), a day outside the liability, or a refused period (reverse-charge lines until stage S6, an
 * unmapped or unknown tax code, no form edition). The service's German sentence as it is, and the
 * link to the place that clears it — never a half-computed form.
 *
 * @var \Z77\Module\Financial\VatReturn\VatReturnRefusal $refusal
 * @var array{url: string, label: string}|null $fix
 * @var string $periodLabel
 * @var string $base
 */
?>
<div class="be-list" data-vat-return-refused="<?= e($refusal->reason) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">MWST-Abrechnung<?= $periodLabel !== '' ? ' ' . e($periodLabel) : '' ?></h2>
        </div>
        <div class="be-modal__alert be-modal__alert--error" role="status">
            <?= e($refusal->message) ?>
            <?php if ($fix !== null): ?> <a href="<?= e($fix['url']) ?>"><?= e($fix['label']) ?></a><?php endif; ?>
        </div>
        <p class="be-list__note"><a href="<?= e($base . '/list') ?>">Zur Liste der Abrechnungen</a></p>
    </div>
</div>
