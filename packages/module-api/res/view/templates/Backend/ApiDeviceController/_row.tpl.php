<?php
/**
 * One device row of the standard list — the single place that renders it (FETCH-ROW-001: the
 * node carries `data-entity="apiDevice:<id>"`, the address `FetchResponse::rowTarget()` builds).
 *
 *   - the state icon opens the device WINDOW (ADR-047): a phone while the device is live, a
 *     lock once it is blocked (board «Geräte»);
 *   - «Berechtigungen» the scopes by their declared label; «Gerät» what the browser said at
 *     pairing; «Zuletzt benutzt» to the hour (ApiDevices::TOUCH_SECONDS);
 *   - «Status» a pill, not a switch: blocking is final (E13), the way back is a new pairing.
 *
 * @var \Z77\Module\Api\Entities\ApiDevice $device
 * @var string $ownerName
 * @var list<string> $scopeLabels
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var string $actionBase
 */
use Z77\Module\Api\Ui\ApiDeviceListing;

$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE;
$id         = (int) $device->getId();
$url        = $actionBase . '/edit?id=' . $id;
$revoked    = $device->isRevoked();
$region     = $definition->region();
$stateText  = $revoked ? 'Gerät öffnen — gesperrt seit ' . ApiDeviceListing::date($device->getRevokedAt()) : 'Gerät öffnen — aktiv';
?>
                <div class="be-list__item" data-entity="apiDevice:<?= $id ?>">
                    <div class="be-list__row<?= $revoked ? ' be-list__row--inactive' : '' ?>">
                        <a class="be-list__cell be-list__state be-list__state--<?= $revoked ? 'closed' : 'editable' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($stateText) ?>" title="<?= e($stateText) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#<?= $revoked ? 'icon-lock' : 'icon-smartphone' ?>"/></svg></a>
                        <span class="be-list__cell"><button type="button" class="be-tree__menu" title="Aktionen" aria-label="Aktionen — <?= e($device->getLabel()) ?>" aria-haspopup="menu" aria-expanded="false" data-menu-open="<?= e($actionBase) ?>/actions?id=<?= $id ?>">⋮</button></span>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_label')) ?>" data-field="label"><?= e($device->getLabel()) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_owner')) ?>" data-field="owner"><?= e($ownerName) ?></label>
                        <span class="be-list__cell" data-priority="2" data-field="scopes"><?php if ($scopeLabels === []): ?><span class="be-list__cell--muted">—</span><?php endif; ?><?php foreach ($scopeLabels as $label): ?><span class="badge <?= $revoked ? 'badge--muted' : 'badge--info' ?>"><?= e($label) ?></span> <?php endforeach; ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3" data-field="agent"><?= e($device->getAgent() !== '' ? $device->getAgent() : '—') ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3" data-field="created"><?= e(ApiDeviceListing::date($device->getCreatedAt())) ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-field="used"><?= e(ApiDeviceListing::when($device->getLastUsedAt())) ?></span>
                        <span class="be-list__cell" data-field="state"><?php if ($revoked): ?><span class="badge badge--muted" title="gesperrt seit <?= e(ApiDeviceListing::dateTime($device->getRevokedAt())) ?>">gesperrt</span><?php else: ?><span class="badge badge--success">aktiv</span><?php endif; ?></span>
                    </div>
                </div>
