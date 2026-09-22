<?php
/**
 * Post or edit a MANUAL entry (owner, 2026-09-22) — a PAGE with a plain
 * form, no JavaScript (Rule 7): a fixed number of rows, «Weitere Zeilen»
 * submits to the server and comes back with more rows, the balance (Soll,
 * Haben, Differenz) and the computed VAT per line are shown after every
 * submit. `csrf_token` is the page-mode CSRF field (`#[Csrf]` on the
 * action); an edit also carries the entity token.
 *
 * Accounts are typed by NUMBER with a shared `<datalist>` of the postable,
 * active accounts (one list for every row — a `<select>` per row with 150
 * accounts would be the heavier page). A tax code goes on the NET line
 * only; the tax-account line is entered like any other, and the hint under
 * the row says which amount it has to be.
 *
 * @var \Z77\Module\Financial\Ui\ManualEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = new
 * @var int $version  edit only — the entry's version this form was rendered from (optimistic lock)
 * @var string $entityCsrf  edit only
 * @var string $csrfToken  provided by html()
 * @var callable $fmt
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/journal';
$isNew      = $entry === null;
$action     = $isNew
    ? $actionBase . '/add?year=' . rawurlencode($year->getCode())
    : $actionBase . '/edit?id=' . (int) $entry->getId();
[$debit, $credit] = $form->sums();
$difference = $debit->subtract($credit);
$accounts   = $form->postableAccounts();
$codes      = $form->selectableCodes();

$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
?>
<div class="be-list">
    <form method="post" action="<?= e($action) ?>" class="be-list__section" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
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

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Datum <small>(im Geschäftsjahr — die Periode entscheidet, ob gebucht werden darf)</small></label>
                <input type="date" name="date" value="<?= e($form->date()) ?>" required
                       min="<?= e($year->getStartDate()->format('Y-m-d')) ?>" max="<?= e($year->getEndDate()->format('Y-m-d')) ?>"
                       aria-invalid="<?= $form->error('date') !== '' ? 'true' : 'false' ?>">
                <?= raw($fieldError($form->error('date'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label>Buchungstext</label>
                <input type="text" name="text" value="<?= e($form->text()) ?>" maxlength="255" required placeholder="z.B. Büromaterial Papeterie Muster"
                       aria-invalid="<?= $form->error('text') !== '' ? 'true' : 'false' ?>">
                <?= raw($fieldError($form->error('text'))) ?>
            </div>
        </div>

        <div class="be-form__section">Zeilen <small>(Kontonummer, Soll ODER Haben; MWST-Code nur auf der Netto-Zeile — die Steuerzeile selbst erfassen)</small></div>
        <datalist id="journal-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>
        <div class="be-list__frame">
            <div class="be-list__table" style="--be-list-cols: 7rem minmax(10rem, 2fr) 8rem 8rem 8rem">
                <div class="be-list__head">
                    <span class="be-list__col">Konto</span>
                    <span class="be-list__col">Text</span>
                    <span class="be-list__col be-list__col--num">Soll</span>
                    <span class="be-list__col be-list__col--num">Haben</span>
                    <span class="be-list__col">MWST</span>
                </div>
                <?php foreach ($form->rows() as $i => $row): ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" name="account[]" list="journal-accounts" value="<?= e($row['account']) ?>" inputmode="numeric" placeholder="1020" aria-label="Konto Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'account') !== '' ? 'true' : 'false' ?>"></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" name="line_text[]" value="<?= e($row['text']) ?>" maxlength="255" aria-label="Text Zeile <?= $i + 1 ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" name="debit[]" value="<?= e($row['debit']) ?>" inputmode="decimal" placeholder="0.00" aria-label="Soll Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'debit') !== '' ? 'true' : 'false' ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" name="credit[]" value="<?= e($row['credit']) ?>" inputmode="decimal" placeholder="0.00" aria-label="Haben Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'credit') !== '' ? 'true' : 'false' ?>"></span>
                        <span class="be-list__cell">
                            <select class="be-input be-input--sm" name="tax_code[]" aria-label="MWST-Code Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'tax_code') !== '' ? 'true' : 'false' ?>">
                                <option value=""<?= $row['tax_code'] === '' ? ' selected' : '' ?>>–</option>
                                <?php foreach ($codes as $code): ?>
                                <option value="<?= e($code->getCode()) ?>"<?= $row['tax_code'] === $code->getCode() ? ' selected' : '' ?>><?= e($code->getCode()) ?><?= $code->isActive() ? '' : ' (inaktiv)' ?></option>
                                <?php endforeach; ?>
                                <?php if ($row['tax_code'] !== '' && array_filter($codes, fn($c) => $c->getCode() === $row['tax_code']) === []): ?>
                                <option value="<?= e($row['tax_code']) ?>" selected><?= e($row['tax_code']) ?> (?)</option>
                                <?php endif; ?>
                            </select>
                        </span>
                    </div>
                    <?php
                    $rowMessages = array_filter([$form->rowError($i, 'account'), $form->rowError($i, 'text'), $form->rowError($i, 'debit'), $form->rowError($i, 'credit'), $form->rowError($i, 'tax_code')]);
                    if ($rowMessages !== [] || $form->taxHint($i) !== ''): ?>
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--wrap" style="grid-column: 2 / -1">
                            <?php foreach ($rowMessages as $message): ?>
                            <small class="be-form__field-error"><?= e($message) ?></small>
                            <?php endforeach; ?>
                            <?php if ($form->taxHint($i) !== ''): ?>
                            <small class="be-form__hint"><?= e($form->taxHint($i)) ?> — die Steuerzeile (Vorsteuer / geschuldete MWST) separat erfassen.</small>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong>Total</strong> <small class="be-list__cell--muted"><?= $difference->isZero() ? '· ausgeglichen' : '· Differenz ' . e($fmt($difference)) ?></small></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($debit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($credit)) ?></strong></span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
            </div>
        </div>

        <p class="be-form__hint">
            <button type="submit" class="be-btn be-btn--ghost be-btn--sm" name="op" value="more">Weitere Zeilen</button>
            <button type="submit" class="be-btn be-btn--primary be-btn--sm" name="op" value="save"><?= $isNew ? 'Buchen' : 'Speichern (Änderung wird protokolliert)' ?></button>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($isNew ? $actionBase . '/list?year=' . rawurlencode($year->getCode()) : $actionBase . '/detail?id=' . (int) $entry->getId()) ?>">Abbrechen</a>
        </p>
        <?php if (!$isNew): ?>
        <p class="be-form__hint">Die Nummer <?= e($year->getCode() . '/' . $entry->getNumber()) ?> bleibt; das Datum muss im selben Geschäftsjahr liegen. Für ein anderes Jahr: löschen und dort neu erfassen.</p>
        <?php endif; ?>
    </form>
</div>
