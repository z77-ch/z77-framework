<?php
/**
 * «Journal verbuchen …» of the Kreditorenjournal — a WINDOW (the shared `Z77\Shared`
 * `partials/postConfirm`, as the Belegjournal; without the script the same content is a page). The
 * CUT-OFF is on the PAYMENT date (owner answer 3): every invoice still in the journal paid on or
 * before it; later payments and unpaid invoices stay and block nothing. Proposed today, changeable:
 * a GET form inside the window re-renders it. Everything from `CreditorJournalPostingService::preview()`,
 * the strategy the posting calls:
 *
 *   - an invoice of the run that cannot be posted (blocked as a suspected duplicate, incomplete, a
 *     tax code while the mandator is not liable to VAT, no fiscal year, a closed period) → ONLY the
 *     errors: per invoice what is wrong, a link that opens its window; no button;
 *   - nothing paid up to the cut-off → the sentence, no button;
 *   - else the short summary: count, total, VAT per code (input tax caught up included), the grey
 *     lines — input tax caught up (board S5-3), import VAT whose invoice date lies in another VAT
 *     period than the payment (owner O-5, information only) — and the invoices that stay as muted
 *     lines, and «Jetzt buchen» — the POST carries the cut-off and every invoice's
 *     `{id}:{version}` (`inv[]`);
 *   - reverse charge (VAT return stage S6): the tax entries of open invoices NOT paid in the run whose
 *     invoice date lies on or before the cut-off (`tax[]`, `preview()['taxSeen']`) — counted in the
 *     facts, a grey line each («Bezugsteuer … am Rechnungsdatum …, Aufwand bei der Zahlung.»); their
 *     refusal is an error like a paid invoice's.
 *
 * @var int $unpaidBlocked  how many of the errors belong to such an unpaid invoice
 * @var array<string, mixed> $preview  CreditorJournalPostingService::preview()
 * @var \DateTimeImmutable $cutOff
 * @var string $note
 * @var array<int, string> $labels  invoice id → «Rechnung …» of the blocked ones
 * @var array<string, string> $taxLabels
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var bool   $window
 * @var string $origin
 */
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$seen       = $preview['seen'];
$taxSeen    = $preview['taxSeen'] ?? [];   // stage S6: the reverse-charge tax entries of invoices not paid in the run
$count      = count($seen);
$day        = $cutOff->format('d.m.Y');
$blocked    = $preview['blocked'];
$vat        = [];
foreach ($preview['vat'] as $row) {
    $vat[] = ['code' => $row['code'], 'label' => $taxLabels[$row['code']] ?? '', 'rate' => TaxRate::formatPercent($row['rate']) . ' %', 'base' => $fmt($row['base']), 'tax' => $fmt($row['tax'])];
}
if (!$preview['withoutVat']->isZero()) {
    $vat[] = ['code' => 'ohne', 'label' => 'ohne MWST', 'rate' => '—', 'base' => $fmt($preview['withoutVat']), 'tax' => '—'];
}
// The grey lines (boards S5-2 / S5-3, owner O-5): input tax caught up, import VAT across two VAT periods.
$notes = array_map(static fn(array $h): string => $h['text'], $preview['hints'] ?? []);
if ($preview['later'] > 0) {
    $notes[] = ($preview['later'] === 1 ? '1 Rechnung ist' : $preview['later'] . ' Rechnungen sind') . ' nach dem Stichtag bezahlt und bleiben im Journal.';
}
if ($preview['unpaid'] > 0) {
    $notes[] = ($preview['unpaid'] === 1 ? '1 unbezahlte Rechnung bleibt' : $preview['unpaid'] . ' unbezahlte Rechnungen bleiben') . ' im Journal.';
}
?>
<?= $this->partial('partials/postConfirm', [
    'window'      => !empty($window),
    'origin'      => $origin ?? 'page',
    'mask'        => 'creditor-journal-post',
    'entity'      => 'creditor-journal:open',
    'title'       => 'Kreditorenjournal verbuchen',
    'action'      => $actionBase . '/post',
    'csrfToken'   => $csrfToken ?? '',
    'hidden'      => ['cutoff' => $cutOff->format('Y-m-d'), 'inv' => array_map(static fn(array $p) => (int) $p['id'] . ':' . (int) $p['version'], $seen), 'tax' => array_map(static fn(array $p) => (int) $p['id'] . ':' . (int) $p['version'], $taxSeen)],
    'filter'      => [
        'action'  => $actionBase . '/confirm-post',
        'name'    => 'cutoff',
        'id'      => 'creditor-cutoff',
        'label'   => 'Stichtag (bezahlt bis)',
        'value'   => $cutOff->format('Y-m-d'),
        'note'    => $note,
        'hint'    => 'Verbucht werden alle Rechnungen, die bis und mit Stichtag bezahlt sind — jede mit ihrem Zahlungsdatum.',
    ],
    'errors'      => array_map(static fn(array $b) => ['label' => $labels[$b['id']] ?? 'Rechnung ' . $b['id'], 'reason' => $b['reason'], 'url' => $actionBase . '/edit?id=' . (int) $b['id']], $blocked),
    'errorsTitle' => ($unpaidBlocked ?? 0) > 0
        ? (count($blocked) === 1 ? '1 Rechnung' : count($blocked) . ' Rechnungen') . ' bis ' . $day . ' so nicht verbuchbar — zuerst ergänzen, entscheiden oder das Datum ändern:'
        : (count($blocked) === 1 ? '1 bezahlte Rechnung' : count($blocked) . ' bezahlte Rechnungen') . ' bis ' . $day . ' so nicht verbuchbar — zuerst ergänzen, entscheiden oder das Zahlungsdatum ändern:',
    'empty'       => $count === 0 && $taxSeen === [] ? 'Bis zum Stichtag ' . $day . ' ist keine bezahlte Rechnung im Kreditorenjournal — nichts zu verbuchen. Zuerst «Bezahlt am …».' : '',
    'facts'       => array_merge([['Rechnungen bezahlt bis ' . $day, (string) $count], ['Total', $fmt($preview['gross'])]],
        $taxSeen !== [] ? [['Bezugsteuer unbezahlter Rechnungen', (string) count($taxSeen)]] : []),
    'vat'         => $vat,
    'notes'       => $notes,
    'hint'        => 'Verbucht wird der ganze Lauf in einem Schritt — alles oder nichts; eine Buchung pro Rechnung, datiert mit «bezahlt am»'
        . (($preview['catchUps'] ?? 0) > 0 ? '; nachgeholte Vorsteuer als zweite Buchung am ersten Tag der laufenden Periode' : '')
        . (($preview['reverseCharges'] ?? 0) > 0 ? '; Bezugsteuer als eigene Buchung am Rechnungsdatum, auch vor der Zahlung' : '')
        . '. Danach sind die Rechnungen unveränderlich. Kam inzwischen eine dazu oder wurde eine geändert, wird nichts verbucht.',
    'back'        => $actionBase . '/list',
], 'Z77\Shared') ?>
