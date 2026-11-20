<?php
/**
 * «Firmenlogo» — the logo set-up WINDOW (owner decisions 2026-11-20; ADR-047, action row first —
 * ADR-049). Opened from the setup step «Firmenlogo … hinterlegen» on the dashboard and from the tab
 * «Briefkopf» of the mandator; without the script the same address renders as a page.
 *
 *   - the switch «Ein Logo für alle» (on by default): ONE upload serves every use the installation
 *     shows; off: one optional upload per use. The switch is a CSS reveal (`.be-reveal__toggle` +
 *     `.be-switch--for`, the two panels `.be-reveal__panel` / `--off`) — no script (Rule 7); the
 *     posted `one_for_all` decides on the server which file field is read;
 *   - the list of the uses with what each shows TODAY: the stored logo (a preview through the DMS),
 *     «kein Logo», or «eigenes Design» (the project overrides the markup — no upload);
 *     «Entfernen» per use with a window logo (its own sibling form, `op` = remove);
 *   - «Später / kein Logo» in the action row while the setup step would be shown (sibling form,
 *     `op` = dismiss).
 *
 *   - one line above the list when the project renders some uses with its OWN design (owner
 *     2026-11-20: an upload with «Ein Logo für alle» was expected on the login and the website of a
 *     project that overrides them) — that the uploaded logo is not used there; the how-to (remove the
 *     override, or render the logo in the own template) is in the help (`own_design`).
 *
 * Labels only — every explanation is in the help (`logo.help`, a section per field; backend-screen.md
 * §5). The server judges every posted value again (`MandatorControllerTrait::logoAction()`).
 *
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 * @var string|null $unavailable  why there is nothing to set up (no mandator, table missing …)
 * @var bool $dmsMissing  module-dms not registered — no upload possible
 * @var list<array{use: string, label: string, state: string, preview: string|null}> $rows
 * @var list<string> $served  the uses an upload reaches (shown, not «eigenes Design»)
 * @var list<string> $own     the shown uses with «eigenes Design» — an upload does not reach them
 * @var bool $oneForAll
 * @var array<string, string> $errors  field → sentence; '' = the whole form
 * @var array<string, \Z77\Shared\Upload\UploadPolicy> $policies  file field → its policy
 * @var bool $canDismiss
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Mandator\Logo\LogoUses;

$actionBase = $actionBase ?? '/backend/finance/mandator';
$window     = !empty($window);
$action     = $actionBase . '/logo';
$winAttr    = $window ? ' data-window="mandator-logo" data-window-entity="mandator:1" data-window-title="Firmenlogo" data-window-width="44rem"' : '';
$hidden     = static fn(string $op): string => '<input type="hidden" name="csrf_token" value="' . e($csrfToken ?? '') . '"><input type="hidden" name="op" value="' . e($op) . '">'
    . ($window ? '<input type="hidden" name="_origin" value="' . e($origin ?? 'page') . '">' : '');
$errors     = $errors ?? [];
$fieldError = static fn(string $name): string => isset($errors[$name])
    ? '<small class="be-form__field-error" data-z77-field-error>' . e($errors[$name]) . '</small>'
    : '';
$invalidIds = array_values(array_map(static fn(string $f) => 'mandator-' . str_replace('_', '-', $f), array_filter(array_keys($errors), static fn(string $f) => $f !== '')));
$cancelHref = $window ? '' : $actionBase . '/edit';
?>
<div class="be-list"<?= $winAttr ?> data-mandator-logo>
<?php if (!empty($unavailable) || !empty($dmsMissing)): ?>
    <div class="be-list__section">
        <?= $this->partial('partials/modalActions', ['submit' => '', 'cancelHref' => $cancelHref], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title">Firmenlogo</h2></div>
        <?php endif; ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e(!empty($unavailable) ? $unavailable : 'Für das Firmenlogo braucht es die Ablage (z77/module-dms).') ?></div>
    </div>
</div>
<?php return; endif; ?>

    <?php if ($canDismiss): ?>
    <form method="post" action="<?= e($action) ?>" id="mandator-logo-dismiss"><?= raw($hidden('dismiss')) ?></form>
    <?php endif; ?>
    <?php foreach ($rows as $row): ?>
    <?php if ($row['state'] === 'document'): ?>
    <form method="post" action="<?= e($action) ?>" id="mandator-logo-remove-<?= e($row['use']) ?>"><?= raw($hidden('remove')) ?><input type="hidden" name="use" value="<?= e($row['use']) ?>"></form>
    <?php endif; ?>
    <?php endforeach; ?>

    <form method="post" action="<?= e($action) ?>" class="be-list__section" id="mandator-logo-form" enctype="multipart/form-data" autocomplete="off" novalidate>
        <?= raw($hidden('save')) ?>

        <?= $this->partial('partials/modalActions', [
            'submit'     => $served !== [] ? 'Speichern' : '',
            'extra'      => $canDismiss ? '<button type="submit" form="mandator-logo-dismiss" class="be-btn be-btn--ghost">Später / kein Logo</button>' : '',
            'cancelHref' => $cancelHref,
            'errors'     => ['count' => count($invalidIds), 'target' => $invalidIds[0] ?? ''],
        ], 'Z77\\Shared') ?>

        <?php if (!$window): ?>
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Firmenlogo</h2>
        </div>
        <?php endif; ?>

        <?php if (isset($errors[''])): ?>
        <div class="be-modal__alert be-modal__alert--error"><?= e($errors['']) ?></div>
        <?php endif; ?>

        <?php if ($served !== []): ?>
        <?php /* The toggle first — the switch and both panels are its later siblings (`:checked ~`). */ ?>
        <input class="be-reveal__toggle" type="checkbox" id="mandator-one-for-all" name="one_for_all" value="1"<?= $oneForAll ? ' checked' : '' ?>>
        <div class="be-form__field">
            <label class="be-switch be-switch--for" for="mandator-one-for-all" data-help-key="one_for_all">
                <span class="be-switch__label">Ein Logo für alle</span>
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
            </label>
        </div>

        <div class="be-reveal__panel">
            <div class="be-form__grid">
                <div class="be-form__field be-form__field--full" data-z77-field-wrapper>
                    <label for="mandator-logo-all">Logo</label>
                    <?= $this->partial('partials/uploadField', ['policy' => $policies['logo_all'], 'id' => 'mandator-logo-all', 'invalid' => isset($errors['logo_all'])], 'Z77\\Shared') ?>
                    <?= raw($fieldError('logo_all')) ?>
                </div>
            </div>
        </div>

        <div class="be-reveal__panel be-reveal__panel--off">
            <div class="be-form__grid">
                <?php foreach ($served as $use): $field = 'logo_' . $use; ?>
                <div class="be-form__field" data-z77-field-wrapper>
                    <label for="mandator-logo-<?= e($use) ?>"><?= e(LogoUses::LABELS[$use]) ?></label>
                    <?= $this->partial('partials/uploadField', ['policy' => $policies[$field], 'id' => 'mandator-logo-' . $use, 'invalid' => isset($errors[$field])], 'Z77\\Shared') ?>
                    <?= raw($fieldError($field)) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($own)): ?>
        <p class="be-form__hint be-form__hint--warn" data-mandator-logo-own><?= e(LogoUses::words($own)) ?>: eigenes Design dieses Projekts — das hochgeladene Logo erscheint dort nicht.</p>
        <?php endif; ?>

        <div class="be-list__table" style="--be-list-cols: 8rem minmax(0, 1fr) auto" data-mandator-logo-uses>
            <div class="be-list__head">
                <span class="be-list__col">Verwendung</span>
                <span class="be-list__col">Heute</span>
                <span class="be-list__col"></span>
            </div>
            <?php foreach ($rows as $row): ?>
            <div class="be-list__item"><div class="be-list__row" data-logo-use="<?= e($row['use']) ?>" data-logo-state="<?= e($row['state']) ?>">
                <span class="be-list__cell"><?= e($row['label']) ?></span>
                <span class="be-list__cell be-list__cell--wrap">
                    <?php if ($row['state'] === 'document' && $row['preview'] !== null): ?>
                    <img class="be-list__preview" src="<?= e($row['preview']) ?>" alt="Logo <?= e($row['label']) ?>">
                    <?php elseif ($row['state'] === 'document'): ?>
                    gespeichert
                    <?php elseif ($row['state'] === 'own'): ?>
                    <span class="be-list__cell--muted">eigenes Design</span>
                    <?php else: ?>
                    <span class="be-list__cell--muted">kein Logo</span>
                    <?php endif; ?>
                </span>
                <span class="be-list__cell">
                    <?php if ($row['state'] === 'document'): ?>
                    <button type="submit" form="mandator-logo-remove-<?= e($row['use']) ?>" class="be-btn be-btn--quiet be-btn--sm">Entfernen</button>
                    <?php endif; ?>
                </span>
            </div></div>
            <?php endforeach; ?>
        </div>
    </form>
</div>
