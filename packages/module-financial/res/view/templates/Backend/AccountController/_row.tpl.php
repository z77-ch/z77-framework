<?php
/**
 * One account row of the chart (`.be-tree__node`) — the single place that renders it: the list
 * loops over it, and a save answers with it (`FetchResponse::replaceRow()` / `insertRow()`,
 * ADR-047 addendum 2026-10-10), so list and answer cannot drift. The node carries
 * `data-entity="account:<id>"`, the address `FetchResponse::rowTarget()` builds.
 *
 * @var \Z77\Module\Financial\Entities\Account $account
 * @var int $depth  how deep the account sits in its group chain (0 = top)
 * @var array<string,string> $typeLabels
 * @var string $actionBase  URL root of THIS mount
 */
$actionBase = $actionBase ?? '/backend/finance/account';
$id         = (string) $account->getId();
?>
            <div class="be-tree__node<?= $account->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:<?= (int) ($depth ?? 0) ?>" data-account-id="<?= e($id) ?>" data-entity="account:<?= e($id) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <label class="be-switch be-switch--sm be-tree__switch"
                           title="<?= $account->isActive() ? 'Aktiv — wird für neue Buchungen angeboten' : 'Inaktiv — nur noch für bestehende Buchungen' ?>">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="<?= e($actionBase) ?>/toggle-active?id=<?= e($id) ?>"<?= $account->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>

                    <button type="button" class="be-tree__menu" title="Bearbeiten"
                            data-fetch-get="<?= e($actionBase) ?>/edit?id=<?= e($id) ?>">⋮</button>

                    <span class="be-tree__name" data-field="name">
                        <?php if ($account->isPostable()): ?>
                        <code><?= e($account->getNumber()) ?></code> <?= e($account->getName()) ?>
                        <?php else: ?>
                        <strong><code><?= e($account->getNumber()) ?></code> <?= e($account->getName()) ?></strong>
                        <?php endif; ?>
                    </span>

                    <span class="be-tree__url" data-field="type">
                        <small class="be-list__cell--muted"><?= e($typeLabels[$account->getType()] ?? $account->getType()) ?></small>
                    </span>

                    <span class="be-tree__route" data-field="state">
                        <?php if (!$account->isPostable()): ?>
                        <span class="badge badge--muted">Gruppe</span>
                        <?php endif; ?>
                        <?php if (!$account->isActive()): ?>
                        <span class="badge badge--muted">inaktiv</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
