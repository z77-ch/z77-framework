<?php
/**
 * «Gerät koppeln» — the pairing WINDOW (board «Geräte», 2026-11-12; ADR-057 §4). Left the
 * form — owner (a choice only for whoever governs the users), device name, one switch per
 * scope the modules offer —, right what the phone reads: the QR of the app shell's pairing
 * page, the code as text, how long it lives, «Wartet auf das Handy …».
 *
 * Owner and scopes are IN the code: changing them means «Neuer Code» (the form's submit), which
 * withdraws the code shown before. The status box below the code is a LIVE LISTENER
 * (`_pairStatus`, FETCH-LIVE-001): the server answers every few seconds — the time left, or,
 * once the phone redeemed the code, the new device's window in place of this one. No script of
 * this module, nothing inserted as HTML by a script (Rule 7).
 *
 * The QR SVG is generated server-side from the URL alone (no user text) and printed raw.
 *
 * @var bool $window
 * @var string $origin
 * @var array<int, string> $owners   id => «Name (username)», the actor first
 * @var int $ownerId
 * @var string $label
 * @var array<string, array{label: string, hint: string}> $scopes   what the modules offer
 * @var list<string> $picked
 * @var array<string, string> $errors  field => message
 * @var array<string, mixed>|null $pairing  code, ticket, expiresAt, now, url, appUrl, qrSvg, statusUrl, every — null: refused
 * @var string $csrfToken
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Api\Ui\ApiDeviceLayout::BASE;
$window     = !empty($window);
$winAttr    = $window ? ' data-window="api-device-pair" data-window-title="Gerät koppeln" data-window-width="48rem"' : '';
$fieldError = static fn(string $name): string => isset($errors[$name])
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$name]) . '</small>'
    : '';
$newCode    = '<button type="submit" form="api-device-pair-form" class="be-btn be-btn--ghost">Neuer Code</button>';
?>
<div class="be-list"<?= $winAttr ?>>
    <?= $this->partial('partials/modalActions', [
        'submit'     => '',
        'extra'      => $newCode,
        'cancelHref' => $window ? '' : $actionBase . '/list',
    ], 'Z77\\Shared') ?>

    <?php if (!$window): ?>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Gerät koppeln</h2>
    </div>
    <?php endif; ?>

    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start">
        <form method="post" action="<?= e($actionBase) ?>/pair" class="be-list__section" id="api-device-pair-form" autocomplete="off" novalidate style="flex:1 1 17rem;min-width:0">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
            <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
            <input type="hidden" name="ticket" value="<?= e((string) ($pairing['ticket'] ?? '')) ?>">

            <div class="be-form__grid">
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="api-device-owner">Besitzer</label>
                    <?php if (count($owners) > 1): ?>
                    <select id="api-device-owner" name="owner_id" aria-invalid="<?= isset($errors['owner_id']) ? 'true' : 'false' ?>">
                        <?php foreach ($owners as $id => $name): ?>
                        <option value="<?= (int) $id ?>"<?= (int) $id === $ownerId ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="text" id="api-device-owner" value="<?= e((string) reset($owners)) ?>" readonly data-help-key="owner_id">
                    <input type="hidden" name="owner_id" value="<?= (int) array_key_first($owners) ?>">
                    <?php endif; ?>
                    <?= raw($fieldError('owner_id')) ?>
                </div>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="api-device-label">Gerätename</label>
                    <input type="text" id="api-device-label" name="label" value="<?= e($label) ?>" maxlength="<?= \Z77\Module\Api\Services\ApiDevices::LABEL_LENGTH ?>" placeholder="leer = vom Gerät, z. B. «Safari · iPhone»">
                </div>
                <div class="be-form__section">Berechtigungen</div>
                <?php if ($scopes === []): ?>
                <p class="be-form__hint">Kein Modul bietet Geräten etwas an — ein Gerät ohne Berechtigung kann nur sich selbst abfragen.</p>
                <?php endif; ?>
                <?php foreach ($scopes as $scope => $words): ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label class="be-switch">
                        <input type="checkbox" class="be-switch__input" name="scopes[]" value="<?= e($scope) ?>"<?= in_array($scope, $picked, true) ? ' checked' : '' ?> data-help-key="scopes">
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                        <span class="be-switch__label"><?= e($words['label']) ?><?php if ($words['hint'] !== ''): ?> <small class="be-list__cell--muted"><?= e($words['hint']) ?></small><?php endif; ?></span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="be-form__hint">Besitzer und Berechtigungen stecken im Code. Ändern = «Neuer Code»; der alte wird sofort ungültig.</p>
        </form>

        <?php if ($pairing !== null): ?>
        <div class="be-list__section" style="flex:0 1 16rem;min-width:0;text-align:center" data-help-key="code">
            <div role="img" aria-label="QR-Code zum Koppeln — öffnet <?= e($pairing['url']) ?>"><?= raw($pairing['qrSvg']) ?></div>
            <p class="be-list__cell--mono" style="font-size:1.5rem;font-weight:600;letter-spacing:.12em;margin:.25rem 0"><?= e($pairing['code']) ?></p>
            <?= $this->partial('Backend/ApiDeviceController/_pairStatus', [
                'ticket'    => $pairing['ticket'],
                'waiting'   => true,
                'expiresAt' => $pairing['expiresAt'],
                'now'       => $pairing['now'],
                'statusUrl' => $pairing['statusUrl'],
                'every'     => $pairing['every'],
            ], 'Z77\\Module\\Api') ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($pairing !== null): ?>
    <div class="be-list__section">
        <ol class="be-list__facts">
            <li><strong>Kamera</strong> — mit der Handy-Kamera auf den Code richten: das Handy öffnet <span class="be-list__cell--mono"><?= e($pairing['appUrl']) ?>/pair</span> und ist gekoppelt.</li>
            <li><strong>Von Hand</strong> — auf dem Handy <span class="be-list__cell--mono"><?= e($pairing['appUrl']) ?></span> öffnen und den Code eintippen (Gross/Klein und Bindestrich egal).</li>
        </ol>
    </div>
    <?php endif; ?>
</div>
