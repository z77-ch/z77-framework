<?php
/**
 * One app-user row of the standard list — the single place that renders it (FETCH-ROW-001: the
 * node carries `data-entity="apiAppUser:<id>"`).
 *
 *   - the state icon opens the app-user WINDOW (ADR-047): a phone while active and the actor may
 *     change it, an eye when the person lies above the actor (the window is read-only), a lock
 *     once deactivated;
 *   - «Verknüpfte Person» is the person's name — an app user has none of its own (Rule 2);
 *   - «Funktionen» the scopes by their declared label, muted where the person's role no longer
 *     reaches them (the live filter drops them on every request);
 *   - «Geräte» the devices that can still sign in (not blocked, not expired);
 *   - «Status» a pill, not a switch: deactivating signs out every device for good, so it is a
 *     danger button with a question in the window (lead decision 2026-11-12).
 *
 * @var \Z77\Module\Api\Entities\ApiAppUser $appUser
 * @var string $personName
 * @var list<array{label: string, live: bool}> $functions
 * @var int $deviceCount
 * @var bool $canAct
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
use Z77\Module\Api\Ui\ApiAppUserListing;

$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiAppUserLayout::BASE;
$id         = (int) $appUser->getId();
$url        = $actionBase . '/edit?id=' . $id;
$active     = $appUser->isActive();
$region     = $definition->region();
[$icon, $stateClass, $stateText] = match (true) {
    !$active => ['icon-lock', 'closed', 'App-Benutzer öffnen — deaktiviert'],
    !$canAct => ['icon-eye', 'closed', 'App-Benutzer ansehen — die Person hat eine höhere Rolle als du'],
    default  => ['icon-smartphone', 'editable', 'App-Benutzer öffnen — aktiv'],
};
?>
                <div class="be-list__item" data-entity="apiAppUser:<?= $id ?>">
                    <div class="be-list__row<?= $active ? '' : ' be-list__row--inactive' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $stateClass ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($stateText) ?>" title="<?= e($stateText) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#<?= $icon ?>"/></svg></a>
                        <span class="be-list__cell"><button type="button" class="be-tree__menu" title="Aktionen" aria-label="Aktionen — <?= e($personName) ?>" aria-haspopup="menu" aria-expanded="false" data-menu-open="<?= e($actionBase) ?>/actions?id=<?= $id ?>">⋮</button></span>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_person')) ?>" data-field="person"><?= e($personName) ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_email')) ?>" data-field="email"><?= e($appUser->getEmail()) ?></label>
                        <span class="be-list__cell" data-priority="2" data-field="functions"><?php if ($functions === []): ?><span class="be-list__cell--muted">—</span><?php endif; ?><?php foreach ($functions as $function): ?><span class="badge <?= $active && $function['live'] ? 'badge--info' : 'badge--muted' ?>"<?= $function['live'] ? '' : ' title="gilt nicht — die Rolle der Person reicht nicht"' ?>><?= e($function['label']) ?></span> <?php endforeach; ?></span>
                        <span class="be-list__cell be-list__cell--num be-list__cell--mono" data-priority="3" data-field="devices"><?= (int) $deviceCount ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-field="login"><?= e(ApiAppUserListing::when($appUser->getLastLoginAt())) ?></span>
                        <span class="be-list__cell" data-field="state"><?php if ($active): ?><span class="badge badge--success">aktiv</span><?php else: ?><span class="badge badge--muted" title="deaktiviert <?= e(ApiAppUserListing::dateTime($appUser->getChangedAt())) ?>">deaktiviert</span><?php endif; ?></span>
                    </div>
                </div>
