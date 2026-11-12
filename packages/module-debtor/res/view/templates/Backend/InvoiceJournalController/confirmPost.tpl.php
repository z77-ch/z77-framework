<?php
/**
 * «Journal verbuchen …» — a WINDOW (owner decision E16, 2026-11-12; the shared building block
 * `Z77\Shared` `partials/postConfirm`, without a date: always the whole journal, O7; without the
 * script the same content is a page):
 *
 *   - a document flagged «Neu fakturieren nötig», or no receivable account for the posting
 *     (`preview()` throws) → ONLY the errors: per document what is wrong and a link that opens its
 *     mask as a window; no button;
 *   - an empty journal → the sentence, no button;
 *   - else the short summary — count, the SIGNED total (a credit note negative), the VAT per code —
 *     and «Jetzt buchen»: the POST (`#[Csrf]`) carries every document's `{id}:{version}`, the
 *     journal as THIS window shows it; the posting refuses when it changed since.
 *
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $documents
 * @var array<string, mixed> $totals  JournalPostingService::totals()
 * @var list<array{id: int, name: string, reason: string}> $blockers
 * @var string|null $problem
 * @var string $currency
 * @var callable $fmt
 * @var string $csrfToken
 * @var string $actionBase
 * @var string $invoiceBase
 * @var bool   $window
 * @var string $origin
 */
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Shared\Money\Money;

$actionBase  = $actionBase ?? '/backend/finance/invoice-journal';
$invoiceBase = $invoiceBase ?? '/backend/finance/invoice';
$money       = static fn(string $decimal): Money => Money::fromDecimal($decimal, $currency);
$count       = count($documents);
$errors      = array_map(static fn(array $b) => ['label' => $b['name'], 'reason' => $b['reason'], 'url' => $invoiceBase . '/edit?id=' . (int) $b['id']], $blockers);
if ($problem !== null) {
    $errors[] = ['label' => '', 'reason' => $problem];
}
?>
<?= $this->partial('partials/postConfirm', [
    'window'      => !empty($window),
    'origin'      => $origin ?? 'page',
    'mask'        => 'invoice-journal-post',
    'entity'      => 'invoice-journal:open',
    'title'       => 'Faktura-Journal verbuchen',
    'action'      => $actionBase . '/post',
    'csrfToken'   => $csrfToken ?? '',
    'hidden'      => ['doc' => array_map(static fn($d) => $d->getId() . ':' . $d->getVersion(), $documents)],
    'filter'      => null,
    'errors'      => $errors,
    'errorsTitle' => $blockers !== [] ? 'Verbuchen gesperrt — ' . (count($blockers) === 1 ? '1 Dokument muss' : count($blockers) . ' Dokumente müssen') . ' zuerst neu fakturiert werden:' : 'Verbuchen nicht möglich:',
    'empty'       => $count === 0 ? 'Das Faktura-Journal ist leer — nichts zu verbuchen.' : '',
    'facts'       => [[$count === 1 ? 'Dokument' : 'Dokumente', (string) $count], ['Total', $fmt($money($totals['gross']))]],
    'vat'         => array_map(static fn(array $v) => ['code' => $v['code'], 'label' => $v['label'], 'rate' => TaxRate::formatPercent($v['rate']) . ' %', 'base' => $fmt($money($v['base'])), 'tax' => $fmt($money($v['tax']))], $totals['vat']),
    'notes'       => [],
    'hint'        => 'Verbucht wird immer das ganze Journal in einem Schritt — alles oder nichts; danach sind die Rechnungen verbucht und unveränderlich (Korrektur = Gutschrift). Kam inzwischen ein Dokument dazu oder wurde eines geändert, wird nichts verbucht.',
    'back'        => $actionBase . '/list',
], 'Z77\\Shared') ?>
