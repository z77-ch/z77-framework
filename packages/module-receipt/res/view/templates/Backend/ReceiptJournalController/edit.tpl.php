<?php
/**
 * The receipt WINDOW (board «Beleg als Fenster», owner-approved 2026-11-12) — the PHOTO large on
 * the left, the fields on the right: when the amount and the date are typed off the slip, the slip
 * lies beside them. One form, one save (`ReceiptJournalService::update()` with the version shown).
 *
 *   - «Vom Handy»: Datum, Betrag (gross, incl. VAT), Text;
 *   - «Kontierung»: Vorlage → fills Soll and MWST, «Bezahlt mit» → fills Haben (core.js «fill»,
 *     `data-fill-url` — the server answers the values; the two selects themselves are not saved:
 *     the receipt keeps what the phone chose, receipt.md), Soll, Haben (the chart's `<datalist>`),
 *     MWST (a code, «ohne MWST» — the explicit decision —, or «— wählen —» = not decided);
 *   - what is missing for «vollständig» is marked «— fehlt» at its label (saving an incomplete
 *     receipt is fine; only «Journal verbuchen» needs it complete).
 *
 * Action row first (ADR-049): «Speichern», «Aufteilen …» (E12) and «Löschen …» (both load into THIS
 * window, `data-window-link`), «Schliessen» quiet at the right. A posted receipt is read-only. The
 * photo comes through the DMS's gated preview (`ReceiptPhoto`); «Original öffnen» opens it in a new
 * tab. No explanations in the form — the help (`edit.help`) has a section per field.
 *
 * @var \Z77\Module\Receipt\Entities\Receipt $receipt
 * @var array{date: string, amount: string, text: string, debit: string, credit: string, tax_code: string} $values
 * @var array<string, string> $errors
 * @var string $error
 * @var array<string, mixed> $photo  ReceiptPhoto
 * @var list<\Z77\Module\Receipt\Entities\ReceiptTemplate> $templates
 * @var list<\Z77\Module\Receipt\Entities\PaymentMethod> $methods
 * @var \Z77\Module\Receipt\Ui\ReceiptChoices $choices
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Receipt\Ui\ReceiptChoices;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$window     = !empty($window);
$id         = (int) $receipt->getId();
$editable   = $receipt->isCaptured();
$title      = $receipt->getReceiptDate()->format('d.m.Y') . ' · ' . ($receipt->getText() !== '' ? $receipt->getText() : 'Beleg ' . $id);
$fields     = ['date', 'amount', 'text', 'debit', 'credit', 'tax_code'];
$invalidIds = array_values(array_map(static fn(string $f) => 'receipt-' . $f, array_filter($fields, static fn(string $f) => isset($errors[$f]))));
$fieldError = static fn(string $name): string => isset($errors[$name])
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$name]) . '</small>'
    : '';
$invalid    = static fn(string $name): string => isset($errors[$name]) ? 'true' : 'false';
$missing    = $receipt->missing();
$lacks      = static fn(string $label): bool => in_array($label, $missing, true);
$fehlt      = static fn(string $label): string => in_array($label, $missing, true) ? ' <span class="be-form__missing">— fehlt</span>' : '';
$disabled   = $editable ? '' : ' disabled';
$template   = $receipt->getTemplateCode();
$method     = $receipt->getPaymentMethodCode();
$taxCodes   = $choices->taxCodes([(string) $receipt->getTaxCode(), $values['tax_code']]);
$complete   = $receipt->isComplete();

$extra = '';
if ($editable) {
    $extra .= '<a class="be-btn be-btn--ghost" href="' . e($actionBase . '/split?id=' . $id) . '" data-window-link title="Einen Teil des Betrags als eigenen Beleg abteilen — für einen zweiten MWST-Satz">Aufteilen …</a>';
    $extra .= '<a class="be-btn be-btn--danger" href="' . e($actionBase . '/confirm-delete?id=' . $id) . '" data-window-link title="Den Beleg aus dem Journal löschen">Löschen …</a>';
}
$winAttr = $window
    ? ' data-window="receipt-edit" data-window-entity="receipt:' . $id . '" data-window-title="' . e($title) . '" data-window-width="72rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/edit?id=' . $id) ?>" class="be-list__section" id="receipt-form" autocomplete="off" novalidate data-fill-scope>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) $receipt->getVersion() ?>">

        <?= $this->partial('partials/modalActions', [
            'submit'     => $editable ? 'Speichern' : '',
            'extra'      => $extra,
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
            <?php if ($complete): ?><span class="badge badge--success">vollständig</span><?php else: ?><span class="badge badge--warning">es fehlt: <?= e(implode(', ', $missing)) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($error) ?></div>
        <?php endif; ?>

        <div class="be-docview">
            <figure class="be-docview__media" data-receipt-photo>
                <?php if ($photo['state'] === 'doc' && $photo['isImage']): ?>
                <a class="be-docview__frame" href="<?= e($photo['previewUrl']) ?>" target="_blank" rel="noopener" title="Original öffnen"><img src="<?= e($photo['previewUrl']) ?>" alt="Foto des Belegs"></a>
                <?php elseif ($photo['state'] === 'doc'): ?>
                <a class="be-docview__frame be-docview__frame--file" href="<?= e($photo['previewUrl']) ?>" target="_blank" rel="noopener">
                    <?php if ($photo['thumbUrl'] !== null): ?><img src="<?= e($photo['thumbUrl']) ?>" alt=""><?php endif; ?>
                    <span>PDF öffnen</span>
                </a>
                <?php else: ?>
                <div class="be-docview__frame be-docview__frame--empty">Das Foto (Dokument Nr. <?= (int) $photo['id'] ?>) ist nicht verfügbar.</div>
                <?php endif; ?>
                <figcaption class="be-docview__meta">
                    <?php if ($photo['state'] === 'doc'): ?>
                    <span class="be-docview__name"><?= e($photo['name']) ?> · <?= e($photo['size']) ?></span>
                    <a href="<?= e($photo['previewUrl']) ?>" target="_blank" rel="noopener">Original öffnen</a>
                    <?php endif; ?>
                </figcaption>
            </figure>

            <div class="be-docview__fields">
                <h3 class="be-form__section">Vom Handy</h3>
                <div class="be-form__grid">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="receipt-date">Datum</label>
                        <input type="date" id="receipt-date" name="date" value="<?= e($values['date']) ?>" max="<?= e((new \DateTimeImmutable('today'))->format('Y-m-d')) ?>" required aria-invalid="<?= $invalid('date') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('date')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="receipt-amount">Betrag (inkl. MWST)</label>
                        <input type="text" id="receipt-amount" name="amount" value="<?= e($values['amount']) ?>" inputmode="decimal" required aria-invalid="<?= $invalid('amount') ?>"<?= $disabled ?>>
                        <?= raw($fieldError('amount')) ?>
                    </div>
                    <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                        <label for="receipt-text">Text<?= raw($fehlt('Text')) ?></label>
                        <input type="text" id="receipt-text" name="text" value="<?= e($values['text']) ?>" maxlength="<?= \Z77\Module\Receipt\Entities\Receipt::TEXT_LENGTH ?>" aria-invalid="<?= $invalid('text') ?>"<?= $lacks('Text') ? ' data-missing' : '' ?><?= $disabled ?>>
                        <?= raw($fieldError('text')) ?>
                    </div>
                </div>

                <h3 class="be-form__section">Kontierung</h3>
                <datalist id="receipt-accounts">
                    <?php foreach ($choices->accounts() as $account): ?>
                    <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="be-form__grid">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="receipt-template">Vorlage</label>
                        <select id="receipt-template" data-fill-url="<?= e($actionBase . '/template-fill') ?>" data-help-key="template"<?= $disabled ?>>
                            <option value=""<?= $template === null ? ' selected' : '' ?>>ohne Vorlage</option>
                            <?php foreach ($templates as $t): if (!$t->isActive() && $t->getCode() !== $template) { continue; } ?>
                            <option value="<?= e($t->getCode()) ?>"<?= $t->getCode() === $template ? ' selected' : '' ?>><?= e($t->getLabel()) ?><?= $t->isActive() ? '' : ' (inaktiv)' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="receipt-payment-method">Bezahlt mit</label>
                        <select id="receipt-payment-method" data-fill-url="<?= e($actionBase . '/payment-fill') ?>" data-help-key="payment_method"<?= $disabled ?>>
                            <option value=""<?= $method === null ? ' selected' : '' ?>>— nicht gewählt —</option>
                            <?php foreach ($methods as $m): if (!$m->isActive() && $m->getCode() !== $method) { continue; } ?>
                            <option value="<?= e($m->getCode()) ?>"<?= $m->getCode() === $method ? ' selected' : '' ?>><?= e($m->getLabel()) ?><?= $m->isActive() ? '' : ' (inaktiv)' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="receipt-debit">Soll<?= raw($fehlt('Soll')) ?></label>
                        <input type="text" id="receipt-debit" name="debit" list="receipt-accounts" value="<?= e($values['debit']) ?>" inputmode="numeric" data-fill-key="debit" aria-invalid="<?= $invalid('debit') ?>"<?= $lacks('Soll') ? ' data-missing' : '' ?><?= $disabled ?>>
                        <?php if ($choices->accountName($values['debit']) !== ''): ?><small class="be-form__resolved"><?= e($choices->accountName($values['debit'])) ?></small><?php endif; ?>
                        <?= raw($fieldError('debit')) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="receipt-credit">Haben<?= raw($fehlt('Haben')) ?></label>
                        <input type="text" id="receipt-credit" name="credit" list="receipt-accounts" value="<?= e($values['credit']) ?>" inputmode="numeric" data-fill-key="credit" aria-invalid="<?= $invalid('credit') ?>"<?= $lacks('Haben') ? ' data-missing' : '' ?><?= $disabled ?>>
                        <?php if ($choices->accountName($values['credit']) !== ''): ?><small class="be-form__resolved"><?= e($choices->accountName($values['credit'])) ?></small><?php endif; ?>
                        <?= raw($fieldError('credit')) ?>
                    </div>
                    <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                        <label for="receipt-tax-code">MWST-Code<?= raw($fehlt('MWST')) ?></label>
                        <select id="receipt-tax-code" name="tax_code" data-fill-key="tax_code" aria-invalid="<?= $invalid('tax_code') ?>"<?= $lacks('MWST') ? ' data-missing' : '' ?><?= $disabled ?>>
                            <option value=""<?= $values['tax_code'] === '' ? ' selected' : '' ?>>— wählen —</option>
                            <option value="<?= ReceiptChoices::WITHOUT_VAT ?>"<?= $values['tax_code'] === ReceiptChoices::WITHOUT_VAT ? ' selected' : '' ?>>ohne MWST</option>
                            <?php foreach ($taxCodes as $code): ?>
                            <option value="<?= e($code->getCode()) ?>"<?= $values['tax_code'] === $code->getCode() ? ' selected' : '' ?>><?= e($code->getCode() . ' — ' . $code->getLabel()) ?><?= $code->isActive() ? '' : ' (inaktiv)' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= raw($fieldError('tax_code')) ?>
                    </div>
                </div>

                <p class="be-form__hint" data-receipt-facts>
                    erfasst <?= e($receipt->capturedByLabel()) ?> · <?= e($receipt->getCapturedAt()->format('d.m.Y H:i')) ?>
                    <?php if ($receipt->getChangedBy() !== null && $receipt->getChangedAt() !== null): ?> · geändert <?= e($receipt->getChangedBy()) ?> · <?= e($receipt->getChangedAt()->format('d.m.Y H:i')) ?><?php endif; ?>
                    · <?= $editable ? 'im Journal, nicht verbucht' : 'verbucht als ' . e((string) $receipt->getLedgerEntryRef()) . ($receipt->getPostedAt() !== null ? ' am ' . e($receipt->getPostedAt()->format('d.m.Y')) : '') ?>
                </p>
            </div>
        </div>
    </form>
</div>
