<?php
/**
 * Dunning levels (plan §6.5, master data), in `level` order — the ladder a
 * dunning run walks (P4). The inline switch is `active` (deactivate, never
 * delete — notices reference the code, ADR-043 decision 19). A fee carries
 * NO VAT; the account it is posted to is shown once above the list, with
 * the refusal it would raise.
 *
 * Styling: the shared backend list/tree classes only.
 *
 * @var list<\Z77\Module\Debtor\Entities\DunningLevel> $levels  ascending by level
 * @var string $currency
 * @var list<string> $languages
 * @var array{number: string, error: string|null} $feeAccount
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/finance/dunning-level';
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Mahnstufen</h2>
            <span class="be-list__section-badge"><?= count($levels) ?></span>
        </div>
        <?php if ($feeAccount['error'] !== null): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($feeAccount['error']) ?></div>
        <?php else: ?>
        <p class="be-form__hint">Mahngebühren werden auf Konto <?= e($feeAccount['number']) ?> gebucht — ohne MWST (Plan §6.5).</p>
        <?php endif; ?>
        <div class="be-tree be-tree--hub">
            <?php if ($levels === []): ?>
            <p class="be-list__empty">Keine Mahnstufen vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($levels as $level): ?>
            <div class="be-tree__node<?= $level->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0" data-dunning-level-id="<?= e((string) $level->getId()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $level->isActive() ? 'Aktiv — wird im Mahnlauf verwendet' : 'Inaktiv — nur noch an bestehenden Mahnungen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e((string) $level->getId()) ?>"<?= $level->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e((string) $level->getId()) ?>">⋮</button>

                    <span class="be-tree__name" data-field="code">
                        <code><?= e($level->getCode()) ?></code>
                        <?= e($level->getLabel()) ?>
                    </span>

                    <span class="be-tree__url" data-field="timing">
                        Stufe <?= e((string) $level->getLevel()) ?> · <?= e((string) $level->getDaysAfterDue()) ?> Tage nach Verfall
                        <small class="be-list__cell--muted">· Gebühr <?= e($level->fee($currency)->toDecimal()) ?> <?= e($currency) ?></small>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php foreach ($languages as $language): ?>
                        <span class="badge <?= isset($level->getDocumentText()[$language]) ? 'badge--success' : 'badge--muted' ?>"
                              title="<?= isset($level->getDocumentText()[$language]) ? 'Mahntext vorhanden' : 'Kein Mahntext' ?>"><?= e(mb_strtoupper($language)) ?></span>
                        <?php endforeach; ?>
                        <?php if (!$level->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
