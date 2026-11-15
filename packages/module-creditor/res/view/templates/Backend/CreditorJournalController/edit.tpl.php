<?php
/**
 * The creditor invoice WINDOW (creditor plan §8) — the PDF large on the left (`.be-docview`), the
 * fields on the right: the supplier's invoice lies beside what is typed off it. One form, one save
 * (`CreditorInvoiceService::update()` with the version shown).
 *
 *   - «Rechnung»: Kreditor (choosing one fills IBAN, «Bezahlt mit», Haben and the first line's
 *     account and VAT through core.js «fill» — the server answers; the save fills what is still
 *     empty itself), IBAN, Rechnungs-Nr., Rechnungsdatum, Fällig am, Referenz, Mitteilung, Betrag;
 *   - «Bezahlung»: «Bezahlt mit» (fills Haben), Haben, «Bezahlt am» (the payment date — the posting
 *     date; empty = unpaid; never in the future);
 *   - «Kontierung»: the LINES (core.js «rows»: «+ Zeile» and × — submits `op=add` / `op=remove:<i>`
 *     without the script, nothing saved then); a mandator not liable to VAT offers only «ohne MWST»;
 *     «als Standard übernehmen» when the invoice deviates from the creditor's defaults.
 *
 * Action row first (ADR-049): «Speichern», «Kein Doppel — übernehmen» for a blocked invoice (a
 * submit of THIS form to `release`, so it carries the version), «Kreditor übernehmen …» for a
 * proposed creditor, «Löschen …» / «Verwerfen …» (`data-window-link`), «Schliessen» quiet at the
 * right. A posted invoice is read-only (no delete — it is in the books). No explanations in the form — `edit.help` has a section
 * per field. No script of its own (Rule 7).
 *
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice $invoice
 * @var array<string, string> $values
 * @var list<array{text: string, account: string, tax: string, amount: string}> $rows
 * @var list<\Z77\Module\Creditor\Entities\CreditorInvoiceLine> $lines  as stored (for «— fehlt»)
 * @var array<string, string> $errors
 * @var string $error
 * @var array<string, mixed> $pdf  ReceiptPhoto
 * @var array<int, string> $creditors  profile id → name
 * @var list<\Z77\Module\Receipt\Entities\PaymentMethod> $methods
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var bool $liable
 * @var string|null $deviation  «6500 · VI · bank» when the invoice deviates from the creditor's defaults
 * @var \Z77\Module\Creditor\Entities\CreditorInvoice|null $duplicate  the invoice it may duplicate
 * @var array<string, string> $proposal  the proposed creditor (stage 2), [] = none
 * @var array<string, mixed> $recognized  what the recognition noted (stage 2: steps, fields, match, candidates, warnings, notes)
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Services\CreditorInvoiceService;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $invoice->getId();
$editable   = $invoice->isEditable();
$name       = $invoice->getCreditor()?->getContact()?->displayName() ?? ($proposal['name'] ?? '');
$title      = $invoice->label() . ($name !== '' ? ' · ' . $name : '');
$missing    = $invoice->missing($lines);
$fehlt      = static fn(string $label): string => in_array($label, $missing, true) ? ' <span class="be-form__missing">— fehlt</span>' : '';
$disabled   = $editable ? '' : ' disabled';
$withoutVat = CreditorInvoiceService::WITHOUT_VAT;
$taxCodes   = $liable ? $choices->taxCodes(array_column($rows, 'tax')) : [];

// «n Fehler» in the action row (ADR-049): the ids in document order, the first one is the target.
$head   = ['creditor', 'iban', 'invoice_number', 'invoice_date', 'due_date', 'reference', 'message', 'amount', 'payment_method_code', 'credit_account', 'paid_on'];
$idOf   = static fn(string $field): string => 'ci-' . str_replace('_', '-', $field);
$ids    = array_map($idOf, array_values(array_filter($head, static fn(string $f) => isset($errors[$f]))));
foreach (array_keys($errors) as $key) {
    if (preg_match('/^line_(\d+)_(text|account|tax|amount)$/', $key, $m)) {
        $ids[] = 'ci-line-' . $m[1] . '-' . $m[2];
    }
}
$fieldError = static fn(string $field): string => isset($errors[$field]) ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$field]) . '</small>' : '';
$invalid    = static fn(string $field): string => isset($errors[$field]) ? 'true' : 'false';

/** One line row; $key is the row key of the field names (`__i__` in the template). Fill keys only on the first row. */
$lineRow = static function (string $key, array $row, bool $first) use ($errors, $liable, $taxCodes, $withoutVat, $disabled, $editable): string {
    $err  = static fn(string $f): string => isset($errors["line_{$key}_{$f}"]) ? 'true' : 'false';
    $msg  = static fn(string $f): string => isset($errors["line_{$key}_{$f}"]) ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors["line_{$key}_{$f}"]) . '</small>' : '';
    $fill = static fn(string $k): string => $first ? ' data-fill-key="' . $k . '"' : '';
    $tax  = '<select class="be-input be-input--sm" id="ci-line-' . e($key) . '-tax" name="lines[' . e($key) . '][tax]" aria-label="MWST" data-help-key="line_tax" aria-invalid="' . $err('tax') . '"' . $fill('line_tax') . $disabled . '>';
    if ($liable) {
        $tax .= '<option value=""' . ($row['tax'] === '' ? ' selected' : '') . '>— wählen —</option>';
    }
    $tax .= '<option value="' . $withoutVat . '"' . ($row['tax'] === $withoutVat || !$liable ? ' selected' : '') . '>ohne MWST</option>';
    foreach ($taxCodes as $code) {
        $tax .= '<option value="' . e($code->getCode()) . '"' . ($row['tax'] === $code->getCode() ? ' selected' : '') . '>' . e($code->getCode() . ' — ' . $code->getLabel()) . '</option>';
    }
    $tax .= '</select>';

    return '<div class="be-list__item" data-row><div class="be-list__row">'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="ci-line-' . e($key) . '-text" name="lines[' . e($key) . '][text]" value="' . e($row['text']) . '" maxlength="' . CreditorInvoiceLine::TEXT_LENGTH . '" aria-label="Text" data-help-key="line_text" aria-invalid="' . $err('text') . '"' . $disabled . '>' . $msg('text') . '</span>'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="ci-line-' . e($key) . '-account" name="lines[' . e($key) . '][account]" value="' . e($row['account']) . '" list="ci-accounts" inputmode="numeric" aria-label="Konto" data-help-key="line_account" aria-invalid="' . $err('account') . '"' . $fill('line_account') . $disabled . '>' . $msg('account') . '</span>'
        . '<span class="be-list__cell">' . $tax . $msg('tax') . '</span>'
        . '<span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="ci-line-' . e($key) . '-amount" name="lines[' . e($key) . '][amount]" value="' . e($row['amount']) . '" inputmode="decimal" aria-label="Betrag" data-help-key="line_amount" aria-invalid="' . $err('amount') . '"' . $disabled . '>' . $msg('amount') . '</span>'
        . '<span class="be-list__cell be-rows__end">' . ($editable ? '<button type="submit" class="be-rows__remove" name="op" value="remove:' . e($key) . '" formnovalidate data-row-remove title="Zeile entfernen" aria-label="Zeile entfernen">×</button>' : '') . '</span>'
        . '</div></div>';
};

$extra = '';
if ($editable && $invoice->isBlocked()) {
    $extra .= '<button type="submit" class="be-btn be-btn--confirm" formaction="' . e($actionBase . '/release?id=' . $id) . '" formnovalidate data-help-key="release" title="Die Rechnung ist kein Doppel — wieder ins Journal">Kein Doppel — übernehmen</button>';
}
if ($editable && $invoice->getCreditor() === null && $proposal !== []) {
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/adopt?id=' . $id) . '" data-window-link title="Den vorgeschlagenen Kreditor anlegen oder einem Kontakt zuordnen">Kreditor übernehmen …</a>';
}
if ($editable) {
    $extra .= '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . $id) . '" data-window-link title="' . ($invoice->isBlocked() ? 'Als Doppel verwerfen — dieselbe Datei bleibt gesperrt' : 'Die Rechnung ganz löschen — dieselbe Datei kann wieder hochgeladen werden') . '">' . ($invoice->isBlocked() ? 'Verwerfen …' : 'Löschen …') . '</a>';
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
            Ist es kein Doppel: «Kein Doppel — übernehmen». Ist es eines: «Verwerfen …».
        </div>
        <?php endif; ?>
        <?php
        $recognized = $recognized ?? [];
        $rSteps     = (array) ($recognized['steps'] ?? []);
        $rHints     = array_merge((array) ($recognized['warnings'] ?? []), (array) ($recognized['notes'] ?? []));
        $rChoose    = $invoice->getCreditor() === null ? (array) ($recognized['candidates'] ?? []) : [];
        $rLevel     = ['high' => 'hoch', 'medium' => 'mittel', 'low' => 'tief'];
        $rConf      = (string) ($rSteps['ai']['confidence'] ?? $rSteps['text']['confidence'] ?? '');
        $rBy        = ['iban' => 'Kreditor über die IBAN gefunden', 'uid' => 'Kreditor über die UID gefunden', 'hint' => 'Kreditor über den Hinweis der IBAN gefunden'];
        ?>
        <?php if ($rSteps !== []): ?>
        <div class="be-modal__alert" data-invoice-recognition="<?= e($invoice->recognitionSource()) ?>">
            <strong>Erkannt: <?= e($invoice->recognitionSource()) ?></strong><?= $rConf !== '' ? ' · Sicherheit ' . e($rLevel[$rConf] ?? $rConf) : '' ?><?= isset($rBy[$recognized['match'] ?? '']) && $invoice->getCreditor() !== null ? ' · ' . e($rBy[$recognized['match']]) : '' ?>.
            <?php if ($invoice->recognitionSource() !== 'von Hand'): ?>Erkannte Werte stehen nur in leeren Feldern — prüfen und speichern.<?php endif; ?>
            <?php if ($rChoose !== []): ?><br><span data-invoice-candidates>Die IBAN gehört <?= ($recognized['match'] ?? '') === 'conflict' ? 'einem anderen Kreditor als die UID' : 'mehreren Kreditoren' ?> — Kreditor wählen: <?= e(implode(', ', array_column($rChoose, 'name'))) ?>.</span><?php endif; ?>
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
                        <select id="ci-creditor" name="creditor" data-fill-url="<?= e($actionBase . '/creditor-fill') ?>" aria-invalid="<?= $invalid('creditor') ?>"<?= in_array('Kreditor', $missing, true) ? ' data-missing' : '' ?><?= $disabled ?>>
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
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="ci-amount">Betrag CHF (inkl. MWST)<?= raw($fehlt('Betrag')) ?></label>
                        <input type="text" id="ci-amount" name="amount" value="<?= e($values['amount']) ?>" inputmode="decimal" aria-invalid="<?= $invalid('amount') ?>"<?= in_array('Betrag', $missing, true) ? ' data-missing' : '' ?><?= $disabled ?>>
                        <?= raw($fieldError('amount')) ?>
                    </div>
                </div>

                <h3 class="be-form__section">Bezahlung</h3>
                <datalist id="ci-accounts">
                    <?php foreach ($choices->accounts() as $account): ?>
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
                        <input type="date" id="ci-paid-on" name="paid_on" value="<?= e($values['paid_on'] ?? '') ?>" max="<?= e((new \DateTimeImmutable('today'))->format('Y-m-d')) ?>" aria-invalid="<?= $invalid('paid_on') ?>"<?= $disabled ?>>
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
                        <span class="be-switch__label">als Standard übernehmen — weicht vom Standard ab (<?= e($deviation) ?>)</span>
                    </label>
                </div>
                <?php endif; ?>

                <p class="be-form__hint" data-invoice-facts>
                    eingelesen <?= e($invoice->getReceivedBy()) ?> · <?= e($invoice->getReceivedAt()->format('d.m.Y H:i')) ?>
                    <?php if ($invoice->getChangedBy() !== null && $invoice->getChangedAt() !== null): ?> · geändert <?= e($invoice->getChangedBy()) ?> · <?= e($invoice->getChangedAt()->format('d.m.Y H:i')) ?><?php endif; ?>
                    · <?= $invoice->getPaidOn() !== null ? 'bezahlt am ' . e($invoice->getPaidOn()->format('d.m.Y')) : 'unbezahlt' ?>
                    · <?= $editable ? 'im Journal, nicht verbucht' : 'verbucht als ' . e((string) $invoice->getLedgerEntryRef()) ?>
                </p>
            </div>
        </div>
    </form>
</div>
