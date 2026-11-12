<?php
/**
 * «Journal verbuchen …» — a WINDOW (owner decision E16, 2026-11-12; the shared building block
 * `Z77\Shared` `partials/postConfirm`; without the script the same content is a page). The
 * CUT-OFF (E11 — every captured receipt dated on or before it; later ones stay in the journal),
 * proposed today, changeable: a GET form inside the window re-renders it (core.js
 * `data-window-form`). Everything from `ReceiptJournalPostingService::preview()`, the builder the
 * posting calls:
 *
 *   - a receipt up to the cut-off that cannot be posted (incomplete, no fiscal year, a closed
 *     period) → ONLY the errors: per receipt what is wrong, a link that opens its window; no button;
 *   - nothing up to the cut-off → the sentence, no button;
 *   - else the short summary: count, total, VAT per code, the later receipts that stay as one muted
 *     line, and «Jetzt buchen» — the POST carries the cut-off and every receipt's `{id}:{version}`.
 *
 * @var array<string, mixed> $preview  ReceiptJournalPostingService::preview()
 * @var \DateTimeImmutable $cutOff
 * @var string $note
 * @var list<array{id: int, label: string, reason: string}> $blockers
 * @var int $later  captured receipts after the cut-off
 * @var array<string, string> $taxLabels
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$seen       = $preview['seen'];
$count      = count($seen);
$day        = $cutOff->format('d.m.Y');
$vat        = [];
foreach ($preview['vat'] as $row) {
    $vat[] = ['code' => $row['code'], 'label' => $taxLabels[$row['code']] ?? '', 'rate' => TaxRate::formatPercent($row['rate']) . ' %', 'base' => $fmt($row['base']), 'tax' => $fmt($row['tax'])];
}
if (!$preview['withoutVat']->isZero()) {
    $vat[] = ['code' => 'ohne', 'label' => 'ohne MWST', 'rate' => '—', 'base' => $fmt($preview['withoutVat']), 'tax' => '—'];
}
?>
<?= $this->partial('partials/postConfirm', [
    'window'      => !empty($window),
    'origin'      => $origin ?? 'page',
    'mask'        => 'receipt-journal-post',
    'entity'      => 'receipt-journal:open',
    'title'       => 'Belegjournal verbuchen',
    'action'      => $actionBase . '/post',
    'csrfToken'   => $csrfToken ?? '',
    'hidden'      => ['cutoff' => $cutOff->format('Y-m-d'), 'rec' => array_map(static fn(array $p) => (int) $p['id'] . ':' . (int) $p['version'], $seen)],
    'filter'      => [
        'action'  => $actionBase . '/confirm-post',
        'name'    => 'cutoff',
        'id'      => 'receipt-cutoff',
        'label'   => 'Stichtag',
        'value'   => $cutOff->format('Y-m-d'),
        'note'    => $note,
        'hint'    => 'Verbucht werden alle Belege mit Datum bis und mit Stichtag.',
    ],
    'errors'      => array_map(static fn(array $b) => ['label' => $b['label'], 'reason' => $b['reason'], 'url' => $actionBase . '/edit?id=' . (int) $b['id']], $blockers),
    'errorsTitle' => (count($blockers) === 1 ? '1 Beleg' : count($blockers) . ' Belege') . ' bis ' . $day . ' so nicht verbuchbar — zuerst ergänzen, umdatieren oder löschen:',
    'empty'       => $count === 0 ? 'Bis zum Stichtag ' . $day . ' ist kein Beleg im Belegjournal — nichts zu verbuchen.' : '',
    'facts'       => [['Belege bis ' . $day, (string) $count], ['Total', $fmt($preview['gross'])]],
    'vat'         => $vat,
    'notes'       => $later > 0 ? [($later === 1 ? '1 späterer Beleg bleibt' : $later . ' spätere Belege bleiben') . ' im Journal.'] : [],
    'hint'        => 'Verbucht wird der ganze Lauf in einem Schritt — alles oder nichts; danach sind die Belege unveränderlich. Kam inzwischen ein Beleg dazu oder wurde einer geändert, wird nichts verbucht.',
    'back'        => $actionBase . '/list',
], 'Z77\\Shared') ?>
