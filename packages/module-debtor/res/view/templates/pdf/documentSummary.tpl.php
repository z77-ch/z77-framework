<?php
/**
 * PDF partial: the SUMMARY under a document's line table — Netto, one row per VAT code
 * («MWST 8.1 % auf …», code, tax), «Rundung» when there is one, «Total CHF». Shared by debtor's
 * `pdf/invoice` and module-order's `pdf/order` (Rule 9): one place for the totals' wording, and
 * one file a project overrides under `override/` to word them its own way.
 *
 * Without VAT (`$liableToVat` false — the document was computed without VAT, owner 2026-10-21)
 * no MWST row is drawn; the document has no tax rows then anyway, the flag keeps the rule visible.
 *
 * Drawn as a table WITHOUT header directly under the line table, in its columns (`$columns` =
 * what `DocumentColumns::table()` chose for the lines — the summary cells are cut to them with
 * `DocumentColumns::fit()`), so the amounts stand under the line amounts. Leaves the cursor below
 * the last row. Draws through `$pdf` only — echoes nothing.
 *
 * @var \Z77\Shared\Pdf\PdfDocument $pdf
 * @var \Z77\Module\Debtor\Entities\Invoice $document  the issued document or a preview (an order's)
 * @var list<array<string, mixed>> $columns  the line table's columns
 * @var bool $liableToVat
 * @var callable $fmt  Money → «1'234.50»
 * @var float $x
 */
use Z77\Module\Debtor\Pdf\DocumentColumns;
use Z77\Module\Vat\Entities\TaxRate;

$rows   = [];
$rows[] = ['cells' => ['Netto', '', '', '', '', '', $fmt($document->getNetTotal())], 'rule' => true];
if ($liableToVat) {
    foreach ($document->getTaxes() as $tax) {
        $rows[] = ['cells' => ['MWST ' . TaxRate::formatPercent($tax->getTaxRate()) . ' % auf ' . $fmt($tax->getBase()), '', '', '', '', $tax->getTaxCode(), $fmt($tax->getTax())], 'muted' => true];
    }
}
if (!$document->getRounding()->isZero()) {
    $rows[] = ['cells' => ['Rundung', '', '', '', '', '', $fmt($document->getRounding())], 'muted' => true];
}
$rows[] = ['cells' => ['Total ' . $document->getCurrency(), '', '', '', '', '', $fmt($document->getGrossTotal())], 'bold' => true, 'rule' => true];

$pdf->partial('pdf/table', [
    'x'       => $x,
    'y'       => $pdf->y(),
    'header'  => false,
    'columns' => $columns,
    'rows'    => DocumentColumns::fit($columns, $rows),
], 'Z77\\Shared');
