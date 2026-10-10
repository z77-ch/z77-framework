<?php
/**
 * One payment-terms row of the list (`.be-tree__node`) — the single place that renders it,
 * for the list and for the in-place answer of a save or a switch (ADR-047 addendum
 * 2026-10-10). The node carries `data-entity="paymentTerms:<id>"`, the address
 * `FetchResponse::rowTarget()` builds.
 *
 * The node is the unit an answer replaces (`replaceRow`) or adds (`insertRow`) — core.js
 * wires the switch and the ⋮ of the HTML it inserts.
 *
 * @var \Z77\Module\Debtor\Entities\PaymentTerms $row
 * @var int $count               debtor profiles referencing the code
 * @var list<string> $languages  the installation's languages, default first
 * @var string $actionBase       URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/finance/payment-terms';
$id         = (string) $row->getId();

$slots = [
    'code' => function () use ($row): void { ?>
                        <code><?= e($row->getCode()) ?></code>
                        <?= e($row->getLabel()) ?>
<?php },
    'terms' => function () use ($row): void {
        $parts = [];
        foreach ($row->getDiscounts() as $tier) {
            $parts[] = $tier['days'] . ' Tage ' . \Z77\Module\Debtor\Entities\PaymentTerms::formatPercent($tier['percent']) . ' %';
        } ?>
                        <?= $row->getDueDays() === 0 ? 'zahlbar sofort' : e((string) $row->getDueDays()) . ' Tage netto' ?>
                        <?php if ($parts !== []): ?>
                        <small class="be-list__cell--muted">· Skonto <?= e(implode(', ', $parts)) ?></small>
                        <?php endif; ?>
<?php },
    'state' => function () use ($row, $count, $languages): void { ?>
                        <?php foreach ($languages as $language): ?>
                        <span class="badge <?= isset($row->getDocumentText()[$language]) ? 'badge--success' : 'badge--muted' ?>"
                              title="<?= isset($row->getDocumentText()[$language]) ? 'Belegtext vorhanden' : 'Kein Belegtext' ?>"><?= e(mb_strtoupper($language)) ?></span>
                        <?php endforeach; ?>
                        <span class="badge <?= $count > 0 ? 'badge--success' : 'badge--muted' ?>" title="Debitoren mit diesen Konditionen"><?= $count ?> <?= $count === 1 ? 'Debitor' : 'Debitoren' ?></span>
                        <?php if (!$row->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
<?php },
];

?>
            <div class="be-tree__node<?= $row->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-payment-terms-id="<?= e($id) ?>" data-entity="paymentTerms:<?= e($id) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $row->isActive() ? 'Aktiv — wird für neue Debitoren angeboten' : 'Inaktiv — nur noch an bestehenden Debitoren' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e($id) ?>"<?= $row->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e($id) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code"><?php ($slots['code'])(); ?></span>

                    <span class="be-tree__url" data-field="terms"><?php ($slots['terms'])(); ?></span>

                    <span class="be-tree__route" data-field="state"><?php ($slots['state'])(); ?></span>
                </div>
            </div>
