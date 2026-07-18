<?php
/** @var array{initials:string,name:string,role:string}|null $overlayUser */
/** @var array<int, array{key: string, label: string, url: string, active: bool}> $viewAreas */
/** @var array{module:string,controller:string,action:string,template:string}|null $routeInfo */
/** @var array{partialLabels:bool,returnPath:string,csrfToken:string}|null $overlayDev */

// Data-presence guard only (NOT a security decision — the controller owns the auth
// gate and only injects overlayUser for admins on full-page loads; see
// AbstractFrontendController). No AuthUser object reaches the template.
if (empty($overlayUser)) {
    return;
}

$areas = $viewAreas ?? [];
$ri    = $routeInfo ?? null;
$dev   = $overlayDev ?? null;   // set only under DEBUG (controller-gated)
?>
<aside class="z77-admin-overlay" aria-label="Admin">
    <div class="z77-admin-overlay__rail" aria-hidden="true">
        <span class="z77-admin-overlay__rail-dot"></span>admin
    </div>
    <div class="z77-admin-overlay__panel" role="region" aria-label="Admin-Werkzeuge">

        <div class="z77-admin-overlay__identity">
            <span class="z77-admin-overlay__avatar"><?= e($overlayUser['initials']) ?></span>
            <span class="z77-admin-overlay__id-text">
                <span class="z77-admin-overlay__name"><?= e($overlayUser['name']) ?></span>
                <span class="z77-admin-overlay__role"><?= e($overlayUser['role']) ?></span>
            </span>
        </div>

        <?php if ($areas): ?>
        <div class="z77-admin-overlay__section">
            <div class="z77-admin-overlay__label">Umgebung</div>
            <?php foreach ($areas as $va): ?>
            <a href="<?= e($va['url']) ?>" class="z77-admin-overlay__env<?= $va['active'] ? ' is-active' : '' ?>">
                <span class="z77-admin-overlay__dot"></span>
                <?= e($va['label']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($ri): ?>
        <div class="z77-admin-overlay__section">
            <div class="z77-admin-overlay__label">Info</div>
            <dl class="z77-admin-overlay__info">
                <div><dt>Modul</dt><dd><?= e($ri['module']) ?></dd></div>
                <div><dt>Controller</dt><dd><?= e($ri['controller']) ?></dd></div>
                <div><dt>Action</dt><dd><?= e($ri['action']) ?></dd></div>
                <div><dt>Template</dt><dd><?= e($ri['template']) ?></dd></div>
            </dl>
        </div>
        <?php endif; ?>

        <?php if ($dev): ?>
        <div class="z77-admin-overlay__section">
            <div class="z77-admin-overlay__label">Entwicklung</div>
            <form method="post" action="/frontend/main/admin-panel/toggle-partial-labels" class="z77-admin-overlay__dev-form">
                <input type="hidden" name="csrf_token" value="<?= e($dev['csrfToken']) ?>">
                <input type="hidden" name="return" value="<?= e($dev['returnPath']) ?>">
                <button type="submit" class="z77-admin-overlay__dev-toggle<?= $dev['partialLabels'] ? ' is-on' : '' ?>"
                        role="switch" aria-checked="<?= $dev['partialLabels'] ? 'true' : 'false' ?>">
                    Partial-Labels
                    <span class="z77-admin-overlay__dev-switch" aria-hidden="true"><span class="z77-admin-overlay__dev-thumb"></span></span>
                </button>
            </form>
        </div>
        <?php endif; ?>

        <a href="/backend/system/login/logout" class="z77-admin-overlay__logout">Abmelden</a>
    </div>
</aside>
