<?php
/**
 * The WINDOW of one bank movement (ADR-061 stage C2; ADR-047, action row first — ADR-049). What it
 * offers follows the movement's state:
 *
 *   - «zu prüfen» / «privat»: the form «Geschäftlich buchen» — «Buchen als» (Konto · Privat ·
 *     Übertrag eigenes Konto), Gegenkonto (the chart as a `<datalist>`), MWST-Code, Text (from the
 *     movement), Beleg (a file). Where a PRIVATE pattern takes the movement the question of
 *     decision 8 stands in the form (««X» ist als privat gemerkt … Gibt es bei «X» auch Geschäftliches?» — «Ja — jedes Mal prüfen» / «Nein — nur diese Bewegung»,
 *     required). Beside «Buchen» in the action row: «Privat» with its three options («zu prüfen»),
 *     or «Zu prüfen» and «Muster aufheben» («privat»);
 *   - «geschäftlich» by a direct posting: the entry, its contra account, tax code and file —
 *     and «Buchung zurücknehmen»;
 *   - «geschäftlich» by a link of another module: what it is linked to.
 *   Where a private pattern takes a «geschäftlich» movement and decision 8 is not answered yet,
 *   the question stands in the window with «Ja» / «Nein» (their own sibling form, `op` = answer).
 *
 * Several forms, ONE action row: the secondary forms are empty siblings, their buttons and fields
 * belong to them by `form=`. Each posts to `edit?id=` with the hidden `op`. Labels only — every
 * explanation is in the help (`edit.help`, a section per field; backend-screen.md §5). Bank text
 * is foreign input: every value goes through `e()`. No JavaScript of its own (Rule 7): the server
 * judges every posted value again.
 *
 * @var \Z77\Module\Financial\Entities\BankMovement $movement
 * @var string $state  BankMovementRepository::STATES
 * @var \Z77\Module\Financial\Entities\BankLink|null $link
 * @var \Z77\Module\Financial\Entities\BankPosting|null $posting  the direct posting that books it now
 * @var \Z77\Module\Financial\Entities\BankPattern|null $pattern  the PRIVATE pattern that takes it
 * @var \Z77\Module\Financial\Entities\BankPattern|null $keyPattern  the pattern of its key, whatever it says
 * @var string|null $question  the sentence of decision 8 (with $pattern)
 * @var bool $questionOpen  linked, private pattern, not answered yet
 * @var array{kind: string, contra: string, tax_code: string, text: string, flip: string} $form
 * @var array<string, string> $errors  field → sentence; '' = the whole form
 * @var list<\Z77\Module\Financial\Entities\Account> $accounts
 * @var list<\Z77\Module\Vat\Entities\TaxCode> $taxCodes
 * @var array<string, string> $named  kind → the label of its configured account ('' = none)
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Financial\Bank\BankPostingService;
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Ui\BankMovementListing;
use Z77\Shared\Money\AmountFormat;

$actionBase = $actionBase ?? \Z77\Module\Financial\Ui\BankLayout::MOVEMENT_BASE;
$window     = !empty($window);
$id         = (int) $movement->getId();
$action     = $actionBase . '/edit?id=' . $id;
$flat       = static fn(string $text): string => trim((string) preg_replace('/\s+/u', ' ', $text));
// Information only (ADR-061 decision 1, stage C4): the account is not bank-driven on the movement's day — nothing to
// decide; the window shows the facts like a «geschäftlich» one (a posting made earlier can still be taken back).
$info       = $state === BankMovementRepository::STATE_NONE;
$business   = $info || $state === BankMovementRepository::STATE_BUSINESS;
$private    = $state === BankMovementRepository::STATE_PRIVATE;
$title      = ($business ? 'Bewegung' : 'Geschäftlich buchen') . ' · ' . $movement->getBookingDate()->format('d.m.Y') . ' · ' . AmountFormat::of($movement->signedAmount());
$fields     = ['kind', 'contra', 'tax_code', 'text', 'voucher', 'flip'];
$invalidIds = array_values(array_map(static fn(string $f) => 'bank-movement-' . $f, array_filter($fields, static fn(string $f) => isset($errors[$f]))));
$fieldError = static fn(string $name): string => isset($errors[$name])
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$name]) . '</small>'
    : '';
$invalid    = static fn(string $name): string => isset($errors[$name]) ? 'true' : 'false';
$hidden     = static fn(string $op): string => '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '"><input type="hidden" name="op" value="' . e($op) . '">'
    . ($window ? '<input type="hidden" name="_origin" value="' . e($origin ?? 'page') . '">' : '');
$kinds      = [
    BankPostingService::KIND_ACCOUNT  => 'Konto',
    BankPostingService::KIND_PRIVATE  => 'Privat' . ($named[BankPostingService::KIND_PRIVATE] !== '' ? ' · ' . $named[BankPostingService::KIND_PRIVATE] : ''),
    BankPostingService::KIND_TRANSFER => 'Übertrag eigenes Konto' . ($named[BankPostingService::KIND_TRANSFER] !== '' ? ' · ' . $named[BankPostingService::KIND_TRANSFER] : ''),
];
$contraName = '';
foreach ($accounts as $account) {
    if ($account->getNumber() === $form['contra']) {
        $contraName = $account->getName();
    }
}
$entryRef = $posting?->entryRef();
$winAttr  = $window ? ' data-window="bank-movement-edit" data-window-entity="bankMovement:' . $id . '" data-window-title="' . e($title) . '" data-window-width="44rem"' : '';
$button   = static fn(string $label, string $formId, string $class = 'be-btn be-btn--ghost'): string => '<button type="submit" form="' . e($formId) . '" class="' . e($class) . '">' . e($label) . '</button>';

if ($business) {
    // The answer to decision 8 belongs to its own (sibling) form — beside «Buchung zurücknehmen» where the movement was booked here.
    $main  = ['op' => 'take-back', 'submit' => $posting !== null ? 'Buchung zurücknehmen' : '', 'kind' => 'danger', 'extra' => $questionOpen
        ? '<button type="submit" form="bank-movement-answer" name="flip" value="yes" class="be-btn be-btn--primary">' . e(\Z77\Module\Financial\Bank\BankMovementStates::ANSWER_YES) . '</button>'
            . '<button type="submit" form="bank-movement-answer" name="flip" value="no" class="be-btn be-btn--ghost">' . e(\Z77\Module\Financial\Bank\BankMovementStates::ANSWER_NO) . '</button>' : ''];
} else {
    $main  = ['op' => 'book', 'submit' => 'Buchen', 'kind' => 'confirm', 'extra' => $private
        ? $button('Zu prüfen', 'bank-movement-review') . ($pattern !== null ? $button('Muster aufheben', 'bank-movement-lift') : '')
        : $button('Privat', 'bank-movement-private')];
}
?>
<div class="be-list"<?= $winAttr ?> data-movement-window="<?= e($state) ?>">
    <?php if (!$business): ?>
    <form method="post" action="<?= e($action) ?>" id="bank-movement-<?= $private ? 'review' : 'private' ?>"><?= raw($hidden($private ? 'review' : 'private')) ?></form>
    <?php if ($private && $pattern !== null): ?>
    <form method="post" action="<?= e($action) ?>" id="bank-movement-lift"><?= raw($hidden('lift')) ?></form>
    <?php endif; ?>
    <?php elseif ($questionOpen): ?>
    <form method="post" action="<?= e($action) ?>" id="bank-movement-answer"><?= raw($hidden('answer')) ?></form>
    <?php endif; ?>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="bank-movement-form" enctype="multipart/form-data" autocomplete="off" novalidate>
        <?= raw($hidden($main['op'])) ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => $main['submit'],
            'kind'       => $main['kind'],
            'extra'      => $main['extra'],
            'cancelHref' => $window ? '' : $actionBase . '/list',
            'errors'     => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>
        <?php endif; ?>

        <?php if (isset($errors[''])): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($errors['']) ?></div>
        <?php endif; ?>

        <div class="be-list__table" style="--be-list-cols: 9rem minmax(12rem, 1fr)" data-movement-facts>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Datum</span><span class="be-list__cell"><?= e($movement->getBookingDate()->format('d.m.Y')) ?> · <?= e($movement->getBankAccount()->getLabel()) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Betrag</span><span class="be-list__cell"><?= e(AmountFormat::of($movement->signedAmount())) ?> <?= e($movement->getCurrency()) ?><?php if ($movement->getOriginalAmount() !== null): ?> <small class="be-list__cell--muted">· <?= e($movement->getOriginalAmount() . ' ' . $movement->getOriginalCurrency()) ?></small><?php endif; ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Text</span><span class="be-list__cell be-list__cell--wrap"><?php if ($movement->getCounterpartyName() !== ''): ?><?= e($movement->getCounterpartyName()) ?> · <?php endif; ?><?= e($flat($movement->getText())) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Stand</span><span class="be-list__cell" data-movement-stand><?= e($info ? 'nur Auskunft' : (BankMovementListing::STATE_LABELS[$state] ?? $state)) ?><?php if ($private): ?> <small class="be-list__cell--muted">· <?php if ($pattern !== null): ?>Muster «<?= e($pattern->getLabel()) ?>»<?php else: ?>von Hand<?php endif; ?></small><?php elseif (!$business && $keyPattern !== null && $keyPattern->isMixed()): ?> <small class="be-list__cell--muted">· «<?= e($keyPattern->getLabel()) ?>» ist gemischt</small><?php endif; ?></span></div></div>
            <?php if ($link !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Zuordnung</span><span class="be-list__cell be-list__cell--wrap"><?= e($link->getOwnerLabel()) ?></span></div></div>
            <?php endif; ?>
            <?php if ($business && $posting !== null): ?>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Gegenkonto</span><span class="be-list__cell"><?= e($posting->getContraAccount()) ?><?php if ($posting->getTaxCode() !== null): ?> · MWST <?= e($posting->getTaxCode()) ?><?php endif; ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Gebucht</span><span class="be-list__cell"><?= e($posting->getPostedAt()->format('d.m.Y H:i')) ?> von <?= e($posting->getPostedBy()) ?></span></div></div>
            <div class="be-list__item"><div class="be-list__row"><span class="be-list__cell be-list__cell--muted">Beleg</span><span class="be-list__cell"><?php if ($posting->hasVoucher()): ?><a href="<?= e($actionBase . '/voucher?id=' . (int) $posting->getId()) ?>" target="_blank" rel="noopener" data-movement-voucher><?= e((string) $posting->getVoucherName()) ?></a><?php else: ?><a href="<?= e($actionBase . '/statement?id=' . $id) ?>" data-movement-statement>Kontoauszug <?= e($movement->getStatement()->getFileName()) ?></a><?php endif; ?></span></div></div>
            <?php endif; ?>
        </div>

        <?php if ($business && $questionOpen && $question !== null): ?>
        <p class="be-form__missing" data-movement-question><?= e($question) ?></p>
        <?php if (isset($errors['flip'])): ?><div class="be-modal__alert be-modal__alert--error"><?= e($errors['flip']) ?></div><?php endif; ?>
        <?php endif; ?>

        <?php if (!$business): ?>
        <datalist id="bank-movement-accounts">
            <?php foreach ($accounts as $account): ?>
            <option value="<?= e($account->getNumber()) ?>"><?= e($account->label()) ?></option>
            <?php endforeach; ?>
        </datalist>

        <div class="be-form__grid">
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-movement-kind">Buchen als</label>
                <select id="bank-movement-kind" name="kind" aria-invalid="<?= $invalid('kind') ?>">
                    <?php foreach ($kinds as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $form['kind'] === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('kind')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-movement-contra">Gegenkonto</label>
                <input type="text" id="bank-movement-contra" name="contra" list="bank-movement-accounts" value="<?= e($form['contra']) ?>" inputmode="numeric" aria-invalid="<?= $invalid('contra') ?>">
                <?php if ($contraName !== ''): ?><small class="be-form__resolved"><?= e($contraName) ?></small><?php endif; ?>
                <?= raw($fieldError('contra')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-movement-tax_code">MWST-Code</label>
                <select id="bank-movement-tax_code" name="tax_code" aria-invalid="<?= $invalid('tax_code') ?>">
                    <option value=""<?= $form['tax_code'] === '' ? ' selected' : '' ?>>ohne MWST</option>
                    <?php foreach ($taxCodes as $code): ?>
                    <option value="<?= e($code->getCode()) ?>"<?= $form['tax_code'] === $code->getCode() ? ' selected' : '' ?>><?= e($code->getCode()) ?></option>
                    <?php endforeach; ?>
                </select>
                <?= raw($fieldError('tax_code')) ?>
            </div>
            <div class="be-form__field" data-z77-field-wrapper>
                <label for="bank-movement-voucher">Beleg</label>
                <?= $this->partial('partials/uploadField', ['policy' => $voucherPolicy, 'id' => 'bank-movement-voucher', 'invalid' => $invalid('voucher') === 'true'], 'Z77\\Shared') ?>
                <?= raw($fieldError('voucher')) ?>
            </div>
            <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                <label for="bank-movement-text">Text</label>
                <input type="text" id="bank-movement-text" name="text" value="<?= e($form['text']) ?>" maxlength="255" aria-invalid="<?= $invalid('text') ?>">
                <?= raw($fieldError('text')) ?>
            </div>
            <?php if ($pattern !== null && $question !== null): ?>
            <div class="be-form__field be-form__field--full" data-z77-field-wrapper data-movement-question>
                <span id="bank-movement-flip-label" data-help-key="flip"><?= e($question) ?></span>
                <span role="radiogroup" aria-labelledby="bank-movement-flip-label">
                    <label class="be-choice"><input type="radio" class="be-choice__input" id="bank-movement-flip" name="flip" value="yes"<?= $form['flip'] === 'yes' ? ' checked' : '' ?> aria-invalid="<?= $invalid('flip') ?>"> <span class="be-choice__label"><?= e(\Z77\Module\Financial\Bank\BankMovementStates::ANSWER_YES) ?></span></label>
                    <label class="be-choice"><input type="radio" class="be-choice__input" name="flip" value="no"<?= $form['flip'] === 'no' ? ' checked' : '' ?>> <span class="be-choice__label"><?= e(\Z77\Module\Financial\Bank\BankMovementStates::ANSWER_NO) ?></span></label>
                </span>
                <?= raw($fieldError('flip')) ?>
            </div>
            <?php endif; ?>
            <?php if (!$private): ?>
            <div class="be-form__field be-form__field--full" data-movement-private-options>
                <span id="bank-movement-private-label">Privat</span>
                <span role="group" aria-labelledby="bank-movement-private-label">
                    <label class="be-choice"><input type="checkbox" class="be-choice__input" name="only_this" value="1" form="bank-movement-private"> <span class="be-choice__label">nur diese Bewegung</span></label>
                    <label class="be-choice"><input type="checkbox" class="be-choice__input" name="near_amount" value="1" form="bank-movement-private"> <span class="be-choice__label">nur bei ähnlichem Betrag</span></label>
                    <label class="be-choice"><input type="checkbox" class="be-choice__input" name="near_day" value="1" form="bank-movement-private"> <span class="be-choice__label">nur um den <?= (int) $movement->getBookingDate()->format('j') ?>. des Monats</span></label>
                </span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </form>
</div>
