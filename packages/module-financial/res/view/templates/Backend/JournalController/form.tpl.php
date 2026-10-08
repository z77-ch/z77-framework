<?php
/**
 * «Sammelbuchung» — post or edit a MANUAL entry of n lines (owner,
 * 2026-09-22; the default capture form is the one-line entry, `oneLine`,
 * and this one is for real splits) — a PAGE with a plain form, no
 * JavaScript (Rule 7): a fixed number of rows, «Weitere Zeilen»
 * submits to the server and comes back with more rows, the balance (Soll,
 * Haben, Differenz) and the computed VAT per line are shown after every
 * submit. `csrf_token` is the page-mode CSRF field (`#[Csrf]` on the
 * action); an edit also carries the entity token.
 *
 * Accounts are typed by NUMBER with a shared `<datalist>` of the postable,
 * active accounts (one list for every row — a `<select>` per row with 150
 * accounts would be the heavier page). A tax code goes on the NET line
 * only; the tax-account line is entered like any other — which amount it has
 * to be is in the help (`form.help`, ADR-048; no text in the form, owner
 * 2026-09-29). No placeholders in the fields
 * (P2 exit check 3a: a grey «1020» read as a prefilled value); the line
 * principle stands in one sentence above the rows; «ausgeglichen» is shown
 * only for a balance that has amounts.
 *
 * @var \Z77\Module\Financial\Ui\ManualEntryForm $form
 * @var \Z77\Module\Financial\Entities\FiscalYear $year
 * @var \Z77\Module\Financial\Entities\JournalEntry|null $entry  null = new
 * @var int $version  edit only — the entry's version this form was rendered from (optimistic lock)
 * @var string $entityCsrf  edit only
 * @var bool $oneLineFits  edit only — the entry has the one-line shape (a link back to that form)
 * @var string $csrfToken  provided by html()
 * @var callable $fmt
 * @var string|null $configNotice  the red band while no VAT account can be resolved (leftover config key, mandator unavailable) — null normally
 * @var string $actionBase
 * @var bool   $window  edit only — fetched as a window (ADR-047): the root declares mask + entity, the form carries `_origin` back, the links stay in the window
 * @var string $origin  edit only — where the window came from (WindowOrigin)
 * @var string $windowWidth  edit only — the window's width, the controller's call
 */
$window  = !empty($window) && $entry !== null;
$winAttr = $window
    ? ' data-window="journal-entry-edit" data-window-entity="journal-entry:' . (int) $entry->getId() . '" data-window-title="' . e('Buchung ' . $year->getCode() . '/' . $entry->getNumber() . ' bearbeiten') . '"'
        . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '')
    : '';
$winLink = $window ? ' data-window-link' : '';
$actionBase = $actionBase ?? '/backend/finance/journal';
$isNew      = $entry === null;
$action     = $isNew ? $actionBase . '/add-compound' : $actionBase . '/edit?id=' . (int) $entry->getId();
$oneLine    = !$isNew && ($oneLineFits ?? false) ? $actionBase . '/edit?id=' . (int) $entry->getId() : null;
[$debit, $credit] = $form->sums();
$difference = $debit->subtract($credit);
$accounts   = $form->postableAccounts();
$codes      = $form->selectableCodes();

$fieldError = static fn(string $message): string => $message === ''
    ? ''
    : '<small class="be-form__field-error" data-z77-field-error>' . e($message) . '</small>';
?>
<div class="be-list"<?= $winAttr ?>>
    <?php if (!empty($configNotice)): ?>
    <div class="be-modal__alert be-modal__alert--error"><?= e($configNotice) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= e($action) ?>" class="be-list__section"<?= $isNew ? ' id="journal-capture"' : '' ?> autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if (!$isNew): ?>
        <input type="hidden" name="entity_csrf" value="<?= e($entityCsrf ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <input type="hidden" name="version" value="<?= (int) ($version ?? $entry->getVersion()) ?>">
        <?php endif; ?>

        <?php if (!$isNew): ?>
        <?php /* The action bar (ADR-049): sticky at the top of the form — change a field, save,
                 without scrolling; first in the document, so Enter saves. «N Fehler» leads to the
                 first invalid field. A new entry has none: «Buchen» is in the action cell. */ ?>
        <?php
        $invalidIds = [];
        foreach (['date' => 'journal-c-date', 'text' => 'journal-c-text'] as $field => $id) {
            if ($form->error($field) !== '') { $invalidIds[] = $id; }
        }
        foreach (array_keys($form->rows()) as $i) {
            foreach (['account', 'text', 'debit', 'credit', 'tax_code'] as $field) {
                if ($form->rowError($i, $field) !== '') { $invalidIds[] = 'journal-row-' . $i . '-' . $field; }
            }
        }
        ?>
        <div class="z77-form-actions">
            <button type="submit" class="be-btn be-btn--primary be-btn--sm" name="op" value="save">Speichern (Änderung wird protokolliert)</button>
            <?php if ($oneLine !== null): ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($oneLine) ?>"<?= $winLink ?>>Einzeilig bearbeiten …</a>
            <?php endif; ?>
            <a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase . '/detail?id=' . (int) $entry->getId()) ?>"<?= $winLink ?>>Abbrechen</a>
            <?= $this->partial('partials/formErrorsLink', ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''], 'Z77\\Shared') ?>
        </div>
        <?php endif; ?>

        <?php if (!$isNew && !$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">
                Buchung <code><?= e($year->getCode() . '/' . $entry->getNumber()) ?></code> bearbeiten
                <small class="be-list__cell--muted">· Geschäftsjahr <?= e($year->getCode()) ?> (<?= e($year->getStartDate()->format('d.m.Y')) ?> – <?= e($year->getEndDate()->format('d.m.Y')) ?>)</small>
            </h2>
        </div>
        <?php endif; ?>

        <?php if ($form->generalErrors() !== []): ?>
        <div class="be-modal__alert be-modal__alert--error">
            <?php foreach ($form->generalErrors() as $error): ?>
            <div><?= e($error) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="journal-c-date">Datum</label>
                <?php /* New: any date — the fiscal year follows it. Edit: the date stays in the entry's year. */ ?>
                <input type="date" id="journal-c-date" name="date" value="<?= e($form->date()) ?>" required<?= $isNew ? ' autofocus' : '' ?>
                       <?php if (!$isNew): ?>min="<?= e($year->getStartDate()->format('Y-m-d')) ?>" max="<?= e($year->getEndDate()->format('Y-m-d')) ?>"<?php endif; ?>
                       aria-invalid="<?= $form->error('date') !== '' ? 'true' : 'false' ?>">
                <?= raw($fieldError($form->error('date'))) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="journal-c-text">Buchungstext</label>
                <input type="text" id="journal-c-text" name="text" value="<?= e($form->text()) ?>" maxlength="255" required placeholder="z.B. Büromaterial Papeterie Muster"
                       aria-invalid="<?= $form->error('text') !== '' ? 'true' : 'false' ?>">
                <?= raw($fieldError($form->error('text'))) ?>
            </div>
        </div>

        <div class="be-form__section">Zeilen</div>
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
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="journal-row-<?= $i ?>-account" name="account[]" list="journal-accounts" value="<?= e($row['account']) ?>" inputmode="numeric" aria-label="Konto Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'account') !== '' ? 'true' : 'false' ?>"></span>
                        <span class="be-list__cell"><input class="be-input be-input--sm" type="text" id="journal-row-<?= $i ?>-text" name="line_text[]" value="<?= e($row['text']) ?>" maxlength="255" aria-label="Text Zeile <?= $i + 1 ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="journal-row-<?= $i ?>-debit" name="debit[]" value="<?= e($row['debit']) ?>" inputmode="decimal" aria-label="Soll Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'debit') !== '' ? 'true' : 'false' ?>"></span>
                        <span class="be-list__cell be-list__cell--num"><input class="be-input be-input--sm" type="text" id="journal-row-<?= $i ?>-credit" name="credit[]" value="<?= e($row['credit']) ?>" inputmode="decimal" aria-label="Haben Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'credit') !== '' ? 'true' : 'false' ?>"></span>
                        <span class="be-list__cell">
                            <select class="be-input be-input--sm" id="journal-row-<?= $i ?>-tax_code" name="tax_code[]" aria-label="MWST-Code Zeile <?= $i + 1 ?>" aria-invalid="<?= $form->rowError($i, 'tax_code') !== '' ? 'true' : 'false' ?>">
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
                    if ($rowMessages !== []): ?>
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell be-list__cell--wrap" style="grid-column: 2 / -1">
                            <?php foreach ($rowMessages as $message): ?>
                            <small class="be-form__field-error"><?= e($message) ?></small>
                            <?php endforeach; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="be-list__item">
                    <div class="be-list__row">
                        <span class="be-list__cell"></span>
                        <span class="be-list__cell"><strong>Total</strong> <small class="be-list__cell--muted"><?= $debit->isZero() && $credit->isZero() ? '' : ($difference->isZero() ? '· ausgeglichen' : '· Differenz ' . e($fmt($difference))) ?></small></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($debit)) ?></strong></span>
                        <span class="be-list__cell be-list__cell--num"><strong><?= e($fmt($credit)) ?></strong></span>
                        <span class="be-list__cell"></span>
                    </div>
                </div>
            </div>
        </div>

        <?php /* «Weitere Zeilen» acts on the rows — it stands under them (ADR-033). «Buchen» (new:
                 the action cell, form="journal-capture") and «Speichern» (edit: the action bar) come
                 first in the document, so Enter posts instead of adding rows. */ ?>
        <p class="be-form__hint">
            <button type="submit" class="be-btn be-btn--ghost be-btn--sm" name="op" value="more">Weitere Zeilen</button>
        </p>
    </form>
</div>
