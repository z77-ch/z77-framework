<?php
/**
 * One VAT return — the hint line on top of «Herkunft» and «Abstimmung» of a POSTED return whose
 * recomputed view no longer matches what was posted (FIN-VAT-POSTED-VIEWS-001, owner 2026-11-25):
 * «Seit dem Buchen geändert — der Beleg zeigt den Stand vom {Datum}.», the word «Beleg» the link to
 * the archived voucher. Whether it differs is decided by the service
 * (`VatReturnService::changedSincePosting()`), never here; no line on an open period and none on
 * «Formular» (it shows the stored figures).
 *
 * @var ?\DateTimeImmutable $changedSince  the posting time when the view differs, else null
 * @var string $voucherUrl
 */
$changedSince = $changedSince ?? null;
?>
<?php if ($changedSince !== null): ?>
<p class="be-list__section-hint" role="status" data-vat-return-changed>Seit dem Buchen geändert — der <a href="<?= e($voucherUrl) ?>" target="_blank" rel="noopener" data-no-pending>Beleg</a> zeigt den Stand vom <?= e($changedSince->format('d.m.Y')) ?>.</p>
<?php endif; ?>
