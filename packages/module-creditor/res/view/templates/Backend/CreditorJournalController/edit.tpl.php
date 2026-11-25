<?php
/**
 * The creditor invoice WINDOW (creditor plan §8) — the PDF large on the left (`.be-docview`), the
 * fields on the right: the supplier's invoice lies beside what is typed off it. One form, one save
 * (`CreditorInvoiceService::update()` with the version shown).
 *
 *   - «Rechnung»: Kreditor (choosing one fills IBAN, «Bezahlt mit», Haben and the first line's
 *     account and VAT through core.js «fill» — the server answers; the save fills what is still
 *     empty itself), IBAN, Rechnungs-Nr., Rechnungsdatum, Fällig am, Referenz, Mitteilung, Währung
 *     + «Betrag in Währung» (stage A0: CHF or a currency of receipt's list; a foreign invoice keeps
 *     its printed amount), «Betrag CHF» (on a foreign invoice what the bank debited — may stay
 *     empty, the invoice is then incomplete; the derived rate is shown under it);
 *   - «Bezahlung»: «Bezahlt mit» (fills Haben), Haben, «Bezahlt am» (the payment date — the posting
 *     date; empty = unpaid; never in the future);
 *   - «Kontierung»: the LINES (core.js «rows»: «+ Zeile» and × — submits `op=add` / `op=remove:<i>`
 *     without the script, nothing saved then); the VAT select is module-receipt's ONE select
 *     (`ReceiptChoices::taxChoices()` — «ohne MWST», «Vorsteuer», «Nur Steuer», «Umsatzsteuer»); a
 *     line «nur Steuer» shows its «Konto» locked «automatisch» (z77 derives it; the save clears
 *     it); a mandator not liable to VAT offers only «ohne MWST»; the account list leaves out what
 *     the lock of the VAT accounts refuses on the day the invoice is booked on ($lockDate);
 *     «als Standard übernehmen» when the invoice deviates from the creditor's defaults.
 *
 * Action row first (ADR-049): «Speichern», «Kein Doppel — übernehmen» for a blocked invoice (a
 * submit of THIS form to `release`, so it carries the version), «Jetzt erkennen» on every
 * open or blocked invoice (owner 2026-11-16; a submit to `recognize` — it only queues the job;
 * `CreditorInvoice::wantsRecognition()`), «Kreditor übernehmen …» for a
 * proposed creditor, «Löschen …» (`data-window-link`; a blocked invoice offers «Verwerfen …» AND
 * «Löschen …» — `completely=1`, owner 2026-11-16), «Schliessen» quiet at the
 * right. A posted invoice is read-only (no delete — it is in the books). No explanations in the form — `edit.help` has a section
 * per field. No script of its own (Rule 7).
 *
 * While the job recognises the invoice, the block on top says «in Arbeit …» («KI liest …» while the
 * job's AI step reads it, `CreditorInvoice::recognitionProgress()`); opened as a window it carries
 * the LIVE LISTENER (`data-fetch-live`, the controller's `live`): when the job is done the window
 * reloads itself — held while a field here is changed (core.js «live listener»).
 *
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice $invoice
 * @var array<string, string> $values
 * @var list<string> $currencies  CHF first, then receipt's `foreignCurrencies` (+ the invoice's own)
 * @var list<array{text: string, account: string, tax: string, amount: string}> $rows
 * @var list<\Z77\Module\Creditor\Entities\CreditorInvoiceLine> $lines  as stored (for «— fehlt»)
 * @var array<string, string> $errors
 * @var string $error
 * @var array<string, mixed> $pdf  ReceiptPhoto
 * @var array<int, string> $creditors  profile id → name
 * @var list<\Z77\Module\Receipt\Entities\PaymentMethod> $methods
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var \DateTimeImmutable $lockDate  the day the accounts are judged for (`CreditorInvoiceService::lockDate()`)
 * @var bool $liable
 * @var string|null $deviation  «6500 · VI · bank · «KI-Entgelte»» when the invoice deviates from the creditor's defaults or standard line text ('' = none set yet)
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice|null $duplicate  the invoice it may duplicate
 * @var array<string, string> $proposal  the proposed creditor (stage 2), [] = none
 * @var array<string, mixed> $recognized  what the recognition noted (stage 2: steps, fields, match, candidates, warnings, notes)
 * @var string|null $progress  CreditorInvoice::PROGRESS_* while the job recognises it, else null
 * @var string|null $live  the live listener's address (a window, GET), else null
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Services\CreditorInvoiceService;
use Z77\Module\Receipt\Services\ReceiptFields;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $invoice->getId();
$editable   = $invoice->isEditable();
$name       = $invoice->getCreditor()?->getContact()?->displayName() ?? ($proposal['name'] ?? '');
$title      = $invoice->label() . ($name !== '' ? ' · ' . $name : '');
$missing    = $invoice->missing($lines);
$fehlt      = static fn(string $label): string => in_array($label, $missing, true) ? ' <span class="be-form__missing">— fehlt</span>' : '';
$disabled   = $editable ? '' : ' disabled';
// ADR-061 stage C1: linked to a bank movement → «Bezahlt am» and the amounts are the bank's
// (read-only; they still post, the service refuses a change). «Zahlung suchen …» while unlinked.
$bankLink   = $bankLink ?? null;
$bankLocked = $bankLink !== null && $editable ? ' readonly' : '';
$withoutVat = CreditorInvoiceService::WITHOUT_VAT;
// module-receipt's ONE VAT select (option groups, «nur Steuer» included); the kept codes are the rows' own.
$keptCodes  = array_map(static fn(string $t): string => str_ends_with(strtolower($t), ReceiptFields::TAX_ONLY_SUFFIX) ? substr($t, 0, -strlen(ReceiptFields::TAX_ONLY_SUFFIX)) : $t, array_column($rows, 'tax'));
$taxGroups  = $liable ? array_values(array_filter($choices->taxChoices($keptCodes), static fn(array $g): bool => $g['group'] !== '')) : [];
$lockDate   = $lockDate ?? new \DateTimeImmutable('today');
$taxFillUrl = $actionBase . '/tax-fill';

// «n Fehler» in the action row (ADR-049): the ids in document order, the first one is the target.
$head   = ['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'currency', 'original_amount', 'amount', 'exchange_rate', 'payment_method_code', 'credit_account', 'paid_on'];
$idOf   = static fn(string $field): string => 'ci-' . str_replace('_', '-', $field);
$ids    = array_map($idOf, array_values(array_filter($head, static fn(string $f) => isset($errors[$f]))));
foreach (array_keys($errors) as $key) {
    if (preg_match('/^line_(\d+)_(text|account|tax|amount)$/', $key, $m)) {
        $ids[] = 'ci-line-' . $m[1] . '-' . $m[2];
    }
}
if (isset($errors['lines'])) {
    $ids[] = 'ci-line-0-amount';   // the lines do not add up to «Betrag CHF» (owner 2026-11-17)
}
$fieldError = static fn(string $field): string => isset($errors[$field]) ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$field]) . '</small>' : '';
$invalid    = static fn(string $field): string => isset($errors[$field]) ? 'true' : 'false';

/**
 * One line row; $key is the row key of the field names (`__i__` in the template). The creditor fill's
 * keys only on the first row. A row is its own fill scope: its VAT select asks `tax-fill`, «nur
 * Steuer» locks the row's «Konto» (`line_account` on the first row, `row_account` on the others) —
 * display only, the save clears and derives the account (S56-2).
 */
$lineRow = static function (string $key, array $row, bool $first) use ($errors, $liable, $taxGroups, $withoutVat, $disabled, $editable, $taxFillUrl): string {
    $err     = static fn(string $f): string => isset($errors["line_{$key}_{$f}"]) ? 'true' : 'false';
    $msg     = static fn(string $f): string => isset($errors["line_{$key}_{$f}"]) ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors["line_{$key}_{$f}"]) . '</small>' : '';
    $fill    = static fn(string $k): string => $first ? ' data-fill-key="' . $k . '"' : '';
    $taxOnly = $liable && str_ends_with(strtolower($row['tax']), ReceiptFields::TAX_ONLY_SUFFIX);
    $tax     = '<select class="be-input be-input--sm" id="ci-line-' . e($key) . '-tax" name="lines[' . e($key) . '][tax]" aria-label="MWST" data-help-key="line_tax" aria-invalid="' . $err('tax') . '"'
        . $fill('line_tax') . ($editable && $liable ? ' data-fill-url="' . e($taxFillUrl) . '"' : '') . $disabled . '>';
    if ($liable) {
        $tax .= '<option value=""' . ($row['tax'] === '' ? ' selected' : '') . '>— wählen —</option>';
    }
    $tax .= '<option value="' . $withoutVat . '"' . ($row['tax'] === $withoutVat || !$liable ? ' selected' : '') . '>ohne MWST</option>';
    foreach ($taxGroups as $group) {
        $tax .= '<optgroup label="' . e($group['group']) . '">';
        foreach ($group['options'] as $option) {
            $tax .= '<option value="' . e($option['value']) . '"' . ($row['tax'] === $option['value'] ? ' selected' : '') . '>' . e($option['label']) . '</option>';
        }
        $tax .= '</optgroup>';
    }
    $tax .= '</select>';
    $account = ($taxOnly ? ' value="" placeholder="automatisch" readonly' : ' value="' . e($row['account']) . '"') . ' list="ci-accounts"';

    return '<div class="be-list__item" data-row data-fill-scope><div class="be-list__row">'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="ci-line-' . e($key) . '-text" name="lines[' . e($key) . '][text]" value="' . e($row['text']) . '" maxlength="' . CreditorInvoiceLine::TEXT_LENGTH . '" aria-label="Text" data-help-key="line_text" aria-invalid="' . $err('text') . '"' . $disabled . '>' . $msg('text') . '</span>'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="ci-line-' . e($key) . '-account" name="lines[' . e($key) . '][account]"' . $account . ' inputmode="numeric" aria-label="Konto" data-help-key="line_account" aria-invalid="' . $err('account') . '"'
        . ($first ? $fill('line_account') : ' data-fill-key="row_account"') . $disabled . '>' . $msg('account') . '</span>'
        . '<span class="be-list__cell">' . $tax . $msg('tax') . '</span>'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="ci-line-' . e($key) . '-amount" name="lines[' . e($key) . '][amount]" value="' . e($row['amount']) . '" inputmode="decimal" aria-label="Betrag" data-help-key="line_amount" aria-invalid="' . $err('amount') . '"' . $disabled . '>' . $msg('amount') . '</span>'
        . '<span class="be-list__cell be-rows__end">' . ($editable ? '<button type="submit" class="be-rows__remove" name="op" value="remove:' . e($key) . '" formnovalidate data-row-remove title="Zeile entfernen" aria-label="Zeile entfernen">×</button>' : '') . '</span>'
        . '</div></div>';
};

$extra = '';
if ($editable && $invoice->isBlocked()) {
    $extra .= '<button type="submit" class="be-btn be-btn--confirm" formaction="' . e($actionBase . '/release?id=' . $id) . '" formnovalidate data-help-key="release" title="Die Rechnung ist kein Doppel — wieder ins Journal">Kein Doppel — übernehmen</button>';
}
if ($editable && $invoice->wantsRecognition()) {
    $extra .= '<button type="submit" class="be-btn be-btn--ghost" formaction="' . e($actionBase . '/recognize?id=' . $id) . '" formnovalidate data-help-key="recognize" title="Die Erkennung (nochmals) laufen lassen — füllt nur leere Felder; Eingaben hier werden nicht gespeichert">Jetzt erkennen</button>';
}
if ($bankLink !== null && $editable) {
    $extra .= '<button type="submit" class="be-btn be-btn--ghost" formaction="' . e($actionBase . '/release-movement?id=' . $id) . '" formnovalidate data-help-key="bank" title="Die Bankbewegung gehört nicht zu dieser Rechnung — die Rechnung ist danach wieder unbezahlt">Zuordnung lösen</button>';
} elseif ($bankLink === null && !empty($bankAvailable) && !$invoice->isBlocked()) {
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/confirm-bank?id=' . $id) . '" data-window-link data-help-key="bank" title="Auf dem Kontoauszug die Zahlung zu dieser Rechnung suchen">Zahlung suchen …</a>';
}
if ($editable && $invoice->getCreditor() === null && $proposal !== []) {
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/adopt?id=' . $id) . '" data-window-link title="Den vorgeschlagenen Kreditor anlegen oder einem Kontakt zuordnen">Kreditor übernehmen …</a>';
}
if ($editable && $invoice->isBlocked()) {
    // A blocked invoice offers both (owner 2026-11-16): discard as a duplicate, or delete it completely.
    $extra .= '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . $id) . '" data-window-link data-help-key="discard" title="Als Doppel verwerfen — dieselbe Datei bleibt gesperrt">Verwerfen …</a>';
    $extra .= '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . $id . '&completely=1') . '" data-window-link data-help-key="delete" title="Die Rechnung ganz löschen — dieselbe Datei kann wieder hochgeladen werden">Löschen …</a>';
} elseif ($editable) {
    $extra .= '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . $id) . '" data-window-link data-help-key="delete" title="Die Rechnung ganz löschen — dieselbe Datei kann wieder hochgeladen werden">Löschen …</a>';
}
$winAttr = $window
    ? ' data-window="creditor-invoice-edit" data-window-entity="creditorInvoice:' . $id . '" data-window-title="' . e($title) . '" data-window-width="76rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/edit?id=' . $id) ?>" class="be-list__section" id="creditor-invoice-form" autocomplete="off" novalidate data-fill-scope>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) $invoice->getVersion() ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => $editable ? 'Speichern' : '',
            'extra'      => $extra,
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => count($ids), 'target' => $ids[0] ?? ''],
        ], 'Z77\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($invoice->isBlocked()): ?>
        <div class="be-modal__alert be-modal__alert--error" data-invoice-blocked>
            <strong>Gesperrt:</strong> <?= e($invoice->getDuplicateReason()) ?>.
            <?php if ($duplicate !== null): $url = $actionBase . '/edit?id=' . (int) $duplicate->getId(); ?><a href="<?= e($url) ?>" data-window-open="<?= e($url) ?>">Die andere Rechnung öffnen</a>.<?php else: ?>Die andere Rechnung gibt es nicht mehr.<?php endif; ?>
            Ist es kein Doppel: «Kein Doppel — übernehmen». Ist es eines: «Verwerfen …». Soll die Rechnung ganz weg: «Löschen …».
        </div>
        <?php endif; ?>
        <?php
        $recognized = $recognized ?? [];
        $rSteps     = (array) ($recognized['steps'] ?? []);
        $rHints     = array_merge((array) ($recognized['warnings'] ?? []), (array) ($recognized['notes'] ?? []));
        $rChoose    = $invoice->getCreditor() === null ? (array) ($recognized['candidates'] ?? []) : [];
        $rLevel     = ['high' => 'hoch', 'medium' => 'mittel', 'low' => 'tief'];
        $rConf      = (string) ($rSteps['ai']['confidence'] ?? $rSteps['text']['confidence'] ?? '');
        $rBy        = ['iban' => 'Kreditor über die IBAN gefunden', 'uid' => 'Kreditor über die UID gefunden', 'hint' => 'Kreditor über den Hinweis der IBAN gefunden', 'adopted' => 'Kreditor übernommen'];
        // learning per creditor (owner 2026-11-16): found by what it learned from earlier invoices
        $rKinds     = ['vat' => 'MWST-Nr.', 'domain' => 'Domain', 'sender' => 'Absender'];
        $rLearnedBy = array_map(static function ($f) use ($rKinds): string { [$k, $v] = array_pad(explode(':', (string) $f, 2), 2, ''); return ($rKinds[$k] ?? $k) . ' ' . ($k === 'sender' ? '«' . $v . '»' : $v); }, (array) ($rSteps['text']['learned_by'] ?? []));
        $rBy['learned'] = 'Kreditor am Gelernten erkannt' . ($rLearnedBy !== [] ? ' (' . implode(', ', $rLearnedBy) . ')' : '');
        $rLearned   = ['trusted' => 'Werte an den gelernten Stellen gelesen, die KI war nicht nötig', 'used' => 'Werte an den gelernten Stellen gelesen', 'compare' => 'die KI liest noch mit, bis die gelernten Stellen geprüft sind'][(string) ($rSteps['text']['learned'] ?? '')] ?? '';
        $rWhy       = match ((string) ($recognized['match'] ?? '')) {
            'conflict'         => 'Die IBAN gehört einem anderen Kreditor als die UID',
            'learned-conflict' => 'IBAN oder UID und das Gelernte zeigen auf verschiedene Kreditoren',
            'learned-choose'   => 'Das Gelernte passt zu mehreren Kreditoren oder nicht eindeutig',
            default            => 'Die IBAN gehört mehreren Kreditoren',
        };
        if (($recognized['match'] ?? '') === 'adopted' && (int) ($recognized['adopted_from'] ?? 0) > 0) {
            // carried over from another invoice of the same party (owner 2026-11-16)
            $rSame = ['uid' => 'gleiche UID', 'iban' => 'gleiche IBAN', 'name' => 'gleicher Name'];
            $rBy['adopted'] = 'Kreditor mit Rechnung #' . (int) $recognized['adopted_from'] . ' übernommen (' . ($rSame[(string) ($recognized['adopted_by'] ?? '')] ?? 'gleicher Kreditor') . ')';
        }
        ?>
        <?php if ($invoice->isRecognitionPending() && $editable): ?>
        <?php $live = $live ?? null; $reading = ($progress ?? null) === CreditorInvoice::PROGRESS_AI; ?>
        <div class="be-modal__alert" data-invoice-recognition-pending="<?= $reading ? 'ai' : 'pending' ?>">
            <?php if ($live !== null): ?><span hidden data-creditor-live="invoice:<?= $id ?>" data-fetch-live="<?= e($live) ?>" data-fetch-live-every="<?= \Z77\Module\Creditor\Ui\CreditorJournalListing::LIVE_EVERY ?>"></span><?php endif; ?>
            <?php if ($reading): ?>
            <span class="badge badge--info badge--busy">KI liest …</span> Die KI liest diese Rechnung gerade; danach stehen ihre Werte in den leeren Feldern.
            <?php else: ?>
            <span class="badge badge--muted badge--busy">in Arbeit …</span> Der Job «Kreditoren-Eingang» liest diese Rechnung (meist innert einer Minute, mit KI einige Minuten) und füllt die leeren Felder.
            <?php endif; ?>
            <?= $live !== null ? 'Dieses Fenster aktualisiert sich danach selbst, solange du hier nichts änderst.' : 'Danach das Fenster neu öffnen.' ?>
        </div>
        <?php endif; ?>
        <?php if ($rSteps !== []): ?>
        <div class="be-modal__alert" data-invoice-recognition="<?= e($invoice->recognitionSource()) ?>">
            <strong>Erkannt: <?= e($invoice->recognitionSource()) ?></strong><?= $rConf !== '' ? ' · Sicherheit ' . e($rLevel[$rConf] ?? $rConf) : '' ?><?= isset($rBy[$recognized['match'] ?? '']) && $invoice->getCreditor() !== null ? ' · ' . e($rBy[$recognized['match']]) : '' ?><?= $rLearned !== '' ? ' · ' . e($rLearned) : '' ?>.
            <?php if ($invoice->recognitionSource() !== 'von Hand'): ?>Erkannte Werte stehen nur in leeren Feldern — prüfen und speichern.<?php endif; ?>
            <?php if ($rChoose !== []): ?><br><span data-invoice-candidates><?= e($rWhy) ?> — Kreditor wählen: <?= e(implode(', ', array_column($rChoose, 'name'))) ?>.</span><?php endif; ?>
            <?php foreach ($rHints as $hint): ?><br><span data-invoice-hint><?= e((string) $hint) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($invoice->getCreditor() === null && $proposal !== []): ?>
        <div class="be-modal__alert" data-invoice-proposal>Neuer Kreditor (Vorschlag): <strong><?= e($proposal['name'] ?? '') ?></strong><?= ($proposal['city'] ?? '') !== '' ? ', ' . e($proposal['city']) : '' ?> — «Kreditor übernehmen …» legt ihn an oder ordnet ihn einem Kontakt zu.</div>
        <?php endif; ?>

        <div class="be-docview">
            <figure class="be-docview__media" data-invoice-pdf>
                <?php if ($pdf['state'] === 'doc'): ?>
                <a class="be-docview__frame be-docview__frame--file" href="<?= e($pdf['previewUrl']) ?>" target="_blank" rel="noopener">
                    <?php if ($pdf['thumbUrl'] !== null): ?><img src="<?= e($pdf['thumbUrl']) ?>" alt=""><?php endif; ?>
                    <span>PDF öffnen</span>
                </a>
                <?php else: ?>
                <div class="be-docview__frame be-docview__frame--empty">Das PDF (Dokument Nr. <?= (int) $pdf['id'] ?>) ist nicht verfügbar.</div>
                <?php endif; ?>
                <figcaption class="be-docview__meta">
                    <?php if ($pdf['state'] === 'doc'): ?>
                    <span class="be-docview__name"><?= e($pdf['name']) ?> · <?= e($pdf['size']) ?></span>
                    <a href="<?= e($pdf['previewUrl']) ?>" target="_blank" rel="noopener">Original öffnen</a>
                    <?php endif; ?>
                </figcaption>
            </figure>

            <div class="be-docview__fields">
                <h3 class="be-form__section">Rechnung</h3>
                <div class="be-form__grid">
                    <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                        <label for="ci-creditor">Kreditor<?= raw($fehlt('Kreditor')) ?></label>
                        <select id="ci-creditor" name="creditor" data-fill-url="<?= e($actionBase . '/creditor-fill') ?>" data-fill-with="paid_on invoice_date" aria-invalid="<?= $invalid('creditor') ?>"<?= in_array('Kreditor', $missing, true) ? ' data-missing' : '' ?><?= $disabled ?>>
                            <option value=""<?= $values['creditor'] === '' ? ' selected' : '' ?>>— Kreditor wählen —</option>
                            <?php foreach ($creditors as $cid => $cname): ?>
                            <option value="<?= (int) $cid ?>"<?= (string) $cid === $values['creditor'] ? ' selected' : '' ?>><?= e($cname) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= raw($fieldError('creditor')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-iban">IBAN</label>
                        <input type="text" id="ci-iban" name="iban" value="<?= e($values['iban']) ?>" maxlength="42" data-fill-key="iban" aria-invalid="<?= $invalid('iban') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('iban')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-invoice-number">Rechnungs-Nr.</label>
                        <input type="text" id="ci-invoice-number" name="invoice_number" value="<?= e($values['invoice_number']) ?>" maxlength="<?= CreditorInvoice::NUMBER_LENGTH ?>" aria-invalid="<?= $invalid('invoice_number') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('invoice_number')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-invoice-date">Rechnungsdatum</label>
                        <input type="date" id="ci-invoice-date" name="invoice_date" value="<?= e($values['invoice_date']) ?>" aria-invalid="<?= $invalid('invoice_date') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('invoice_date')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-due-date">Fällig am</label>
                        <input type="date" id="ci-due-date" name="due_date" value="<?= e($values['due_date']) ?>" aria-invalid="<?= $invalid('due_date') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('due_date')) ?>
                    </div>
                    <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                        <label for="ci-reference">Referenz</label>
                        <input type="text" id="ci-reference" name="reference" value="<?= e($values['reference']) ?>" maxlength="40" aria-invalid="<?= $invalid('reference') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('reference')) ?>
                    </div>
                    <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                        <label for="ci-message">Mitteilung</label>
                        <input type="text" id="ci-message" name="message" value="<?= e($values['message']) ?>" maxlength="<?= CreditorInvoice::MESSAGE_LENGTH ?>" aria-invalid="<?= $invalid('message') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('message')) ?>
                    </div>
                    <?php $currencies = $currencies ?? [\Z77\Module\Receipt\Services\ReceiptFields::currency()]; $base = $currencies[0]; $rate = $invoice->derivedRate(); ?>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-currency">Währung</label>
                        <select id="ci-currency" name="currency" aria-invalid="<?= $invalid('currency') ?>"<?= $disabled ?>>
                            <?php foreach ($currencies as $code): ?>
                            <option value="<?= e($code) ?>"<?= $code === ($values['currency'] ?? $base) ? ' selected' : '' ?>><?= e($code) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= raw($fieldError('currency')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-original-amount">Betrag in Währung</label>
                        <input type="text" id="ci-original-amount" name="original_amount" value="<?= e($values['original_amount'] ?? '') ?>" inputmode="decimal" aria-invalid="<?= $invalid('original_amount') ?>"<?= $disabled ?><?= $bankLocked ?>>
                        <?= raw($fieldError('original_amount')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <?php // S56-3: the label is neutral — with input tax the amount includes VAT, «nur Steuer» it is the tax (the help explains per kind) ?>
                        <label for="ci-amount">Betrag <?= e($base) ?><?= raw($fehlt($invoice->amountLabel())) ?></label>
                        <input type="text" id="ci-amount" name="amount" value="<?= e($values['amount']) ?>" inputmode="decimal" aria-invalid="<?= $invalid('amount') ?>"<?= in_array($invoice->amountLabel(), $missing, true) ? ' data-missing' : '' ?><?= $disabled ?><?= $bankLocked ?>>
                        <?php if ($rate !== null): ?><small class="be-form__resolved" data-invoice-rate>Kurs <?= e($rate) ?> (<?= e($invoice->originalLabel() . ' → ' . $base . ' ' . $fmt($invoice->getAmount())) ?>)</small><?php endif; ?>
                        <?= raw($fieldError('amount')) ?>
                    </div>
                    <?php // The rate typed by hand (owner 2026-11-18): with «Betrag CHF» empty the server computes it on save. ?>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-exchange-rate">Kurs</label>
                        <input type="text" id="ci-exchange-rate" name="exchange_rate" value="<?= e($values['exchange_rate'] ?? '') ?>" inputmode="decimal" autocomplete="off" aria-invalid="<?= $invalid('exchange_rate') ?>"<?= $disabled ?><?= $bankLocked ?>>
                        <?= raw($fieldError('exchange_rate')) ?>
                    </div>
                </div>

                <h3 class="be-form__section">Bezahlung</h3>
                <datalist id="ci-accounts">
                    <?php foreach ($choices->accounts($lockDate) as $account): ?>
                    <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="be-form__grid">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-payment-method-code">Bezahlt mit<?= raw($fehlt('Bezahlt mit')) ?></label>
                        <select id="ci-payment-method-code" name="payment_method_code" data-fill-url="<?= e($actionBase . '/payment-fill') ?>" data-fill-key="payment_method_code" aria-invalid="<?= $invalid('payment_method_code') ?>"<?= $disabled ?>>
                            <option value=""<?= $values['payment_method_code'] === '' ? ' selected' : '' ?>>— nicht gewählt —</option>
                            <?php foreach ($methods as $m): ?>
                            <option value="<?= e($m->getCode()) ?>"<?= $m->getCode() === $values['payment_method_code'] ? ' selected' : '' ?>><?= e($m->getLabel()) ?><?= $m->isActive() ? '' : ' (inaktiv)' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= raw($fieldError('payment_method_code')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-credit-account">Haben</label>
                        <input type="text" id="ci-credit-account" name="credit_account" list="ci-accounts" value="<?= e($values['credit_account']) ?>" inputmode="numeric" data-fill-key="credit_account" aria-invalid="<?= $invalid('credit_account') ?>"<?= $disabled ?>>
                        <?php if ($choices->accountName($values['credit_account']) !== ''): ?><small class="be-form__resolved"><?= e($choices->accountName($values['credit_account'])) ?></small><?php endif; ?>
                        <?= raw($fieldError('credit_account')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-paid-on">Bezahlt am</label>
                        <input type="date" id="ci-paid-on" name="paid_on" value="<?= e($values['paid_on'] ?? '') ?>" max="<?= e((new \DateTimeImmutable('today'))->format('Y-m-d')) ?>" aria-invalid="<?= $invalid('paid_on') ?>"<?= $invoice->isBlocked() ? ' disabled' : $disabled ?><?= $bankLocked ?>>
                        <?php if ($invoice->isBlocked()): ?><small class="be-form__resolved">gesperrt — zuerst entscheiden</small><?php endif; ?>
                        <?php if ($bankLink !== null): $bankMovement = $bankLink->getMovement(); ?><small class="be-form__resolved" data-bank-link="<?= (int) $bankMovement->getId() ?>">Bank · <?= e($bankMovement->getBookingDate()->format('d.m.Y')) ?> · <?= e($fmt($bankMovement->getAmount())) ?><?= $bankMovement->getOriginalAmount() !== null ? ' · ' . e($bankMovement->getOriginalAmount() . ' ' . $bankMovement->getOriginalCurrency()) : '' ?></small><?php endif; ?>
                        <?= raw($fieldError('paid_on')) ?>
                    </div>
                </div>


                <h3 class="be-form__section">Kontierung<?= raw($fehlt('Konto')) ?><?= in_array('Zeilen = Betrag', $missing, true) ? ' <span class="be-form__missing">— Zeilen ergeben nicht den Betrag</span>' : '' ?></h3>
                <div class="be-form__field" data-z77-field-wrapper>
                    <div class="be-list__frame">
                        <div class="be-list__table be-rows" style="--be-list-cols: minmax(8rem, 1.4fr) 6rem minmax(7rem, 1fr) 7rem 2rem" data-rows="lines" data-rows-enter="line">
                            <div class="be-list__head">
                                <span class="be-list__col">Text</span>
                                <span class="be-list__col">Konto</span>
                                <span class="be-list__col">MWST</span>
                                <span class="be-list__col be-list__col--num">Betrag</span>
                                <span class="be-list__col"></span>
                            </div>
                            <?php foreach ($rows as $i => $row): ?>
                            <?= raw($lineRow((string) $i, $row, $i === 0)) ?>
                            <?php endforeach; ?>
                            <?php if ($editable): ?>
                            <template data-row-template="line"><?= raw($lineRow('__i__', ['text' => '', 'account' => '', 'tax' => '', 'amount' => ''], false)) ?></template>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?= raw($fieldError('lines')) ?>
                    <?php if ($editable): ?>
                    <div class="be-rows__adders">
                        <button type="submit" class="be-btn be-btn--add be-btn--sm" name="op" value="add" formnovalidate data-row-add="line" data-help-key="split"><span aria-hidden="true">+</span> Zeile</button>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($editable && $deviation !== null): ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label class="be-switch">
                        <input type="checkbox" class="be-switch__input" id="ci-take-default" name="take_default" value="1" data-help-key="take_default">
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                        <span class="be-switch__label">als Standard übernehmen<?= $deviation !== '' ? ' — weicht vom Standard ab (' . e($deviation) . ')' : '' ?></span>
                    </label>
                </div>
                <?php endif; ?>

                <p class="be-form__hint" data-invoice-facts>
                    eingelesen <?= e($invoice->getReceivedBy()) ?> · <?= e($invoice->getReceivedAt()->format('d.m.Y H:i')) ?>
                    <?php if ($invoice->getChangedBy() !== null && $invoice->getChangedAt() !== null): ?> · geändert <?= e($invoice->getChangedBy()) ?> · <?= e($invoice->getChangedAt()->format('d.m.Y H:i')) ?><?php endif; ?>
                    · <?= $invoice->getPaidOn() !== null ? 'bezahlt am ' . e($invoice->getPaidOn()->format('d.m.Y')) : 'unbezahlt' ?>
                    · <?= $editable ? 'im Journal, nicht verbucht' : 'verbucht als ' . e((string) $invoice->getLedgerEntryRef()) . ($invoice->getTaxEntryRef() !== null ? ' · Vorsteuer ' . e($invoice->getTaxEntryRef()) . ' (nachgeholt)' : '') ?>
                </p>
            </div>
        </div>
    </form>
</div>
