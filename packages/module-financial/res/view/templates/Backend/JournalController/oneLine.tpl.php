<?php
/**
 * The ONE-LINE manual entry (owner, 2026-09-22 — P2 exit check 3b), the
 * default capture form: Soll | Datum | Bu-Nr | Text | Haben | Betrag, the
 * wdv row. A PAGE with a plain form, no JavaScript (Rule 7):
 *
 *   - the «MwSt» checkbox reveals the VAT row through CSS (`.be-reveal`, the
 *     `:checked` sibling selector); it is a submitted field, so the server
 *     knows whether the row was on and renders it `checked` again;
 *   - the Bu-Nr is shown (an `<output>`), never entered: «neu» for a new entry (the ledger
 *     draws the number at post time — it is not known before), the number
 *     when editing;
 *   - accounts are typed by number with the shared `<datalist>` (the option
 *     text shows «1020 Bankguthaben»); after a submit the resolved name
 *     stands under the field;
 *   - NO placeholders in the fields (P2 exit check 3a: a grey «1020» read as
 *     a prefilled value).
 *
 * New entry: below the form the year's latest entries, numbered, each linked
 * to its detail. Edit: the entity token, the version and a link to the
 * Sammelbuchung form of the same entry.
 *
 * @var \Z77\Module\Financial\Ui\OneLineEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = new
 * @var list<\Z77\Module\Financial\Entities\JournalEntry> $recent  new only — latest first, lines loaded
 * @var int $version  edit only — the entry's version this form was rendered from (optimistic lock)
 * @var string $entityCsrf  edit only
 * @var string $csrfToken  provided by html()
 * @var callable $fmt
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$isNew      = $entry === null;
$recent     = $recent ?? [];
$yearParam  = rawurlencode($year->getCode());
$action     = $isNew ? $actionBase . '/add?year=' . $yearParam : $actionBase . '/edit?id=' . (int) $entry->getId();
$compound   = $isNew
    ? $actionBase . '/add-compound?year=' . $yearParam . ($form->date() !== '' ? '&date=' . rawurlencode($form->date()) : '')
    : $actionBase . '/edit?id=' . (int) $entry->getId() . '&form=compound';

$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
$invalid    = static fn(string $field): string => $form->error($field) !== '' ? 'true' : 'false';
/** The account numbers on one side of an entry — «div.» beyond two. */
$side = static function (\Z77\Module\Financial\Entities\JournalEntry $e, bool $debit): string {
    $numbers = [];
    foreach ($e->getLines() as $line) {
        if (($debit ? $line->getDebit() : $line->getCredit())->isPositive()) {
            $numbers[$line->getAccount()->getNumber()] = true;
        }
    }
    return count($numbers) > 2 ? 'div.' : implode(', ', array_keys($numbers));
};
?>
<div class="be-list">
    <form method="post" action="<?= e($action) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="form" value="one-line">
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <input type="hidden" name="version" value="<?= (int) ($version ?? $entry->getVersion()) ?>">
        <?php endif; ?>

        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                <?= $isNew ? 'Buchung erfassen' : 'Buchung <code>' . e($year->getCode() . '/' . $entry->getNumber()) . '</code> bearbeiten' ?>
                <small class="be-list__cell--muted">· Geschäftsjahr <?= e($year->getCode()) ?> (<?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?>)</small>
            </h2>
        </div>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <p class="be-form__hint">Das Soll-Konto erhält den Betrag, das Haben-Konto gibt ihn ab. Betrag = Bruttobetrag laut Beleg oder Bank; mit «MwSt» rechnet das System die Steuer heraus und bucht die Steuerzeile selbst.</p>

        <datalist id="journal-accounts">
            <?php foreach ($form->postableAccounts() as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-reveal">
            <input class="be-reveal__toggle" type="checkbox" id="journal-vat" name="vat" value="1"<?= $form->vat() ? ' checked' : '' ?>>
            <div class="be-form__row" style="--be-form-cols: 8rem 9.5rem 4.5rem minmax(10rem, 1fr) 8rem 8rem">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-debit">Soll</label>
                    <input type="text" id="journal-debit" name="debit" list="journal-accounts" value="<?= e($form->debit()) ?>" inputmode="numeric" required aria-invalid="<?= $invalid('debit') ?>">
                    <?php if ($form->accountName($form->debit()) !== ''): ?><small class="be-form__resolved"><?= e($form->accountName($form->debit())) ?></small><?php endif; ?>
                    <?= raw($fieldError($form->error('debit'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-date">Datum</label>
                    <input type="date" id="journal-date" name="date" value="<?= e($form->date()) ?>" required
                           min="<?= e($year->getStartDate()->format('Y-m-d')) ?>" max="<?= e($year->getEndDate()->format('Y-m-d')) ?>" aria-invalid="<?= $invalid('date') ?>">
                    <?= raw($fieldError($form->error('date'))) ?>
                </div>
                <div class="be-form__field">
                    <label for="journal-number">Bu-Nr</label>
                    <output class="be-form__static" id="journal-number" title="<?= $isNew ? 'Die Nummer vergibt das Journal beim Buchen — lückenlos pro Geschäftsjahr.' : 'Die Nummer bleibt.' ?>"><?= $isNew ? 'neu' : e((string) $entry->getNumber()) ?></output>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-text">Text</label>
                    <input type="text" id="journal-text" name="text" value="<?= e($form->text()) ?>" maxlength="255" required aria-invalid="<?= $invalid('text') ?>">
                    <?= raw($fieldError($form->error('text'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-credit">Haben</label>
                    <input type="text" id="journal-credit" name="credit" list="journal-accounts" value="<?= e($form->credit()) ?>" inputmode="numeric" required aria-invalid="<?= $invalid('credit') ?>">
                    <?php if ($form->accountName($form->credit()) !== ''): ?><small class="be-form__resolved"><?= e($form->accountName($form->credit())) ?></small><?php endif; ?>
                    <?= raw($fieldError($form->error('credit'))) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="journal-amount">Betrag</label>
                    <input type="text" id="journal-amount" name="amount" value="<?= e($form->amount()) ?>" inputmode="decimal" required aria-invalid="<?= $invalid('amount') ?>">
                    <?= raw($fieldError($form->error('amount'))) ?>
                    <label class="be-reveal__label" for="journal-vat">MwSt</label>
                </div>
            </div>

            <div class="be-reveal__panel">
                <div class="be-form__row" style="--be-form-cols: minmax(12rem, 20rem) 9rem minmax(10rem, 1fr)">
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="journal-tax-code">MWST-Code</label>
                        <select id="journal-tax-code" name="tax_code" aria-invalid="<?= $invalid('tax_code') ?>">
                            <option value=""<?= $form->taxCode() === '' ? ' selected' : '' ?>>– wählen –</option>
                            <?php foreach ($form->selectableCodes() as $code): ?>
                            <option value="<?= e($code->getCode()) ?>"<?= $form->taxCode() === $code->getCode() ? ' selected' : '' ?>><?= e($code->getCode() . ' — ' . $code->getLabel()) ?><?= $code->isActive() ? '' : ' (inaktiv)' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?= raw($fieldError($form->error('tax_code'))) ?>
                    </div>
                    <div class="be-form__field" data-z77-field-wrapper>
                        <label for="journal-tax-amount">Steuerbetrag</label>
                        <input type="text" id="journal-tax-amount" name="tax_amount" value="<?= e($form->taxAmount()) ?>" inputmode="decimal" aria-invalid="<?= $invalid('tax_amount') ?>">
                        <?= raw($fieldError($form->error('tax_amount'))) ?>
                    </div>
                    <div class="be-form__field">
                        <small class="be-form__hint">Leer = aus dem Betrag berechnet (Satz am Buchungsdatum). Ein Wert laut Beleg darf um <?= (int) \Z77\Module\Financial\Ui\OneLineEntryForm::TAX_CORRECTION_PERCENT ?> % der berechneten Steuer daneben liegen — mindestens <?= e(\Z77\Module\Financial\Ui\OneLineEntryForm::TAX_CORRECTION_MIN) ?>, höchstens <?= e(\Z77\Module\Financial\Ui\OneLineEntryForm::TAX_CORRECTION_MAX) ?> (Rundung laut Beleg).</small>
                        <?php if ($form->vatHint() !== ''): ?><small class="be-form__hint"><?= e($form->vatHint()) ?></small><?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <p class="be-form__hint">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm"><?= $isNew ? 'Buchen' : 'Speichern (Änderung wird protokolliert)' ?></button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($compound) ?>"><?= $isNew ? 'Sammelbuchung erfassen …' : 'Als Sammelbuchung bearbeiten …' ?></a>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($isNew ? $actionBase . '/list?year=' . $yearParam : $actionBase . '/detail?id=' . (int) $entry->getId()) ?>"><?= $isNew ? 'Zum Journal' : 'Abbrechen' ?></a>
        </p>
        <?php if (!$isNew): ?>
        <p class="be-form__hint">Die Nummer <?= e($year->getCode() . '/' . $entry->getNumber()) ?> bleibt; das Datum muss im selben Geschäftsjahr liegen. Für ein anderes Jahr: löschen und dort neu erfassen.</p>
        <?php endif; ?>
    </form>

    <?php if ($isNew): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Letzte Buchungen <small class="be-list__cell--muted">· Geschäftsjahr <?= e($year->getCode()) ?></small></h2>
            <span class="be-list__section-badge"><?= count($recent) ?></span>
        </div>
        <?php if ($recent === []): ?>
        <p class="be-list__empty">Noch keine Buchung in diesem Geschäftsjahr.</p>
        <?php else: ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="--be-list-cols: 4rem 6rem minmax(10rem, 2fr) 7rem 7rem 8rem; --be-list-cols-sm: 4rem minmax(8rem, 2fr) 7rem 7rem 8rem; --be-list-cols-xs: 4rem minmax(8rem, 2fr) 7rem">
                <div class="be-list__head">
                    <span class="be-list__col be-list__col--num">Nr.</span>
                    <span class="be-list__col" data-priority="3">Datum</span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col" data-priority="2">Soll</span>
                    <span class="be-list__col" data-priority="2">Haben</span>
                    <span class="be-list__col be-list__col--num">Betrag</span>
                </div>
                <?php foreach ($recent as $item): ?>
                <div class="be-list__item" data-entry-id="<?= e((string) $item->getId()) ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell be-list__cell--num be-list__cell--mono"><a href="<?= e($actionBase) ?>/detail?id=<?= e((string) $item->getId()) ?>"><?= $item->getNumber() ?></a></span>
                        <span class="be-list__cell" data-priority="3"><?= e($item->getDate()->format('d.m.Y')) ?></span>
                        <span class="be-list__cell"><a href="<?= e($actionBase) ?>/detail?id=<?= e((string) $item->getId()) ?>"><?= e($item->getText()) ?></a></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= e($side($item, true)) ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= e($side($item, false)) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($item->total())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
