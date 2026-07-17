<?php
use Z77\Module\Backend\App\Config\ModuleIcons;
use Z77\Module\Backend\App\Config\Palettes;

/** @var \Z77\Core\Services\NavigationService $navigationService */
/** @var array{initials:string,name:string,role:string}|null $headerUser */
/** @var \Z77\Shared\ValueObjects\UserPreferences|null $userPreferences */
/** @var string $navSlot */
// Data-presence check only — no chrome without an authenticated user (mirrors the old header).
if (empty($headerUser)) return;

$palettes = Palettes::all();
$initials = $headerUser['initials'];
$role     = $headerUser['role'];
$devMode  = DEBUG;
$noindex  = SEO_NOINDEX;
$partialLabels = \Z77\Core\Services\PartialLabels::flagSet();

// Resolve a section's first reachable URL (same logic as the legacy module tabs).
$sectionUrl = function (array $item) use ($navigationService): string {
    $first = $navigationService->resolveFirstNavigable($item['section']);
    if ($first === null) {
        return '';
    }
    if ($first->getRef() !== null) {
        $target = $navigationService->findById($first->getRef());
        return $target ? $navigationService->urlForVia($target, $first->getId()) : '';
    }
    return $navigationService->urlFor($first);
};

$sections    = iterator_to_array($navigationService->iterateSections($navSlot));
$activeLabel = 'Menü';
$activeUrl   = '';
foreach ($sections as $item) {
    if ($item['active']) {
        $activeLabel = $item['section']->getName();
        $activeUrl   = $sectionUrl($item);
        break;
    }
}
?>
<header class="be-shell-topbar">

    <!-- Module switcher (backend nav top-groups) — replaces __brand -->
    <div class="be-shell-topbar__mod">
        <div class="be-shell-mod" data-panel-root>
            <?php if ($activeUrl !== ''): ?>
            <a class="be-shell-mod__name" href="<?= e($activeUrl) ?>" title="Zur Standard-Seite des Bereichs"><?= e($activeLabel) ?></a>
            <?php else: ?>
            <span class="be-shell-mod__name" aria-disabled="true"><?= e($activeLabel) ?></span>
            <?php endif; ?>
            <button type="button" class="be-shell-mod__apps" data-panel-trigger aria-haspopup="true" aria-expanded="false" aria-label="Bereiche">
                <svg class="be-icon" width="16" height="16" aria-hidden="true"><use href="#icon-grid"/></svg>
            </button>
            <div class="be-shell-mod__panel" hidden data-panel role="menu" aria-label="Bereich wechseln">
                <div class="be-shell-mod__grid">
                    <?php foreach ($sections as $item):
                        $url  = $sectionUrl($item);
                        $name = $item['section']->getName();
                        $icon = ModuleIcons::forSection($name);
                        $cls  = 'be-shell-mod__tile' . ($item['active'] ? ' be-shell-mod__tile--active' : '');
                    ?>
                    <?php if ($url !== ''): ?>
                    <a class="<?= e($cls) ?>" href="<?= e($url) ?>" role="menuitem"<?= $item['active'] ? ' aria-current="true"' : '' ?>>
                        <span class="be-shell-mod__ico"><svg class="be-icon" width="18" height="18" aria-hidden="true"><use href="#<?= e($icon) ?>"/></svg></span>
                        <span class="be-shell-mod__label"><?= e($name) ?></span>
                    </a>
                    <?php else: ?>
                    <span class="<?= e($cls) ?> be-shell-mod__tile--inert" aria-disabled="true">
                        <span class="be-shell-mod__ico"><svg class="be-icon" width="18" height="18" aria-hidden="true"><use href="#<?= e($icon) ?>"/></svg></span>
                        <span class="be-shell-mod__label"><?= e($name) ?></span>
                    </span>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sandwich (mobile) — toggles the left column drawer -->
    <button type="button" class="be-shell-topbar__burger" data-shell-drawer="l" aria-label="Navigation öffnen" aria-expanded="false">
        <svg class="be-icon" width="18" height="18" aria-hidden="true"><use href="#icon-menu"/></svg>
    </button>

    <!-- Search / command (left-aligned at the content column) -->
    <div class="be-shell-topbar__mid">
        <button type="button" class="be-shell-topbar__search" role="search" aria-label="Suche / Befehle">
            <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-search"/></svg>
            <span class="be-shell-topbar__search-text">Suchen oder Befehl …</span>
            <span class="be-shell-topbar__search-key">⌘K</span>
        </button>
    </div>

    <!-- Right cluster — always visible -->
    <div class="be-shell-topbar__right">
        <?php
        $viewAreas    = $navigationService->getViewAreas();
        $currentArea  = $navigationService->getCurrentViewAreaName();
        $currentLabel = '';
        foreach ($viewAreas as $va) {
            if ($va['active']) { $currentLabel = $va['label']; break; }
        }
        ?>
        <div class="backend-topbar__env-wrap" data-panel-root>
            <button type="button" class="backend-topbar__env backend-topbar__env--<?= e($currentArea ?? '') ?>"
                    title="Umgebung wechseln" data-panel-trigger aria-haspopup="true" aria-expanded="false">
                <span class="backend-topbar__env-dot" aria-hidden="true"></span>
                <span class="backend-topbar__env-label"><?= e($currentLabel !== '' ? $currentLabel : 'Umgebung') ?></span>
                <svg class="be-icon backend-topbar__env-chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
            </button>
            <div class="backend-topbar__env-menu" hidden data-panel role="menu" aria-label="Umgebung wechseln">
                <?php foreach ($viewAreas as $va): ?>
                <a href="<?= e($va['url']) ?>" role="menuitem"
                   class="backend-topbar__env-item backend-topbar__env--<?= e($va['key']) ?><?= $va['active'] ? ' backend-topbar__env-item--active' : '' ?>">
                    <span class="backend-topbar__env-dot" aria-hidden="true"></span>
                    <span class="backend-topbar__env-item-label"><?= e($va['label']) ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <button class="backend-topbar__bell" aria-label="Benachrichtigungen">
            <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-bell"/></svg>
        </button>

        <div class="backend-topbar__avatar-wrap" data-panel-root>
            <button class="backend-topbar__avatar be-font-cap" data-panel-trigger
                    data-panel-open-class="backend-topbar__avatar--open"
                    aria-label="Benutzermenü öffnen" aria-expanded="false" aria-haspopup="true"><?= e($initials) ?></button>

            <div class="backend-service-panel" hidden data-panel aria-label="Benutzermenü">
                <div class="backend-service-panel__identity">
                    <div class="backend-service-panel__avatar"><?= e($initials) ?></div>
                    <div>
                        <div class="backend-service-panel__name"><?= e($headerUser['name']) ?></div>
                        <div class="backend-service-panel__meta"><?= e($role) ?> · z77 Backend</div>
                    </div>
                </div>

                <div class="backend-service-panel__divider"></div>

                <?php if (!empty($routeInfo)): ?>
                <div class="backend-service-panel__section">
                    <button type="button" class="backend-service-panel__section-toggle" data-collapse-trigger aria-expanded="false" aria-controls="js-info-body">
                        Info
                        <svg class="be-icon backend-service-panel__section-chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
                    </button>
                    <dl id="js-info-body" class="backend-service-panel__info be-font-cap" data-collapse hidden>
                        <div class="backend-service-panel__info-row"><dt>Modul</dt><dd><?= e($routeInfo['module']) ?></dd></div>
                        <div class="backend-service-panel__info-row"><dt>Controller</dt><dd><?= e($routeInfo['controller']) ?></dd></div>
                        <div class="backend-service-panel__info-row"><dt>Action</dt><dd><?= e($routeInfo['action']) ?></dd></div>
                        <div class="backend-service-panel__info-row"><dt>Template</dt><dd><?= e($routeInfo['template']) ?></dd></div>
                    </dl>
                </div>
                <div class="backend-service-panel__divider"></div>
                <?php endif; ?>

                <div class="backend-service-panel__section">
                    <div class="backend-service-panel__section-label">Schnell-Einstellungen</div>
                    <button type="button" id="js-debug-toggle" data-url="/backend/system/system/toggle-debug" class="backend-service-panel__row">
                        <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-edit"/></svg></span>
                        <span class="backend-service-panel__row-body">
                            <span class="backend-service-panel__row-label">Entwickler-Modus</span>
                            <span class="backend-service-panel__row-sub">Debug-Overlay, API-Logs</span>
                        </span>
                        <span id="js-debug-indicator" class="backend-service-panel__toggle<?= $devMode ? ' backend-service-panel__toggle--on' : '' ?>" aria-hidden="true"><span class="backend-service-panel__toggle-thumb"></span></span>
                    </button>
                    <button type="button" id="js-noindex-toggle" data-url="/backend/system/system/toggle-noindex" class="backend-service-panel__row">
                        <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-search"/></svg></span>
                        <span class="backend-service-panel__row-body">
                            <span class="backend-service-panel__row-label">Suchmaschinen-Sperre</span>
                            <span class="backend-service-panel__row-sub">noindex für die ganze Website</span>
                        </span>
                        <span id="js-noindex-indicator" class="backend-service-panel__toggle<?= $noindex ? ' backend-service-panel__toggle--on' : '' ?>" aria-hidden="true"><span class="backend-service-panel__toggle-thumb"></span></span>
                    </button>
                    <button type="button" id="js-partial-labels-toggle" data-url="/backend/system/system/toggle-partial-labels" class="backend-service-panel__row">
                        <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-eye"/></svg></span>
                        <span class="backend-service-panel__row-body">
                            <span class="backend-service-panel__row-label">Partial-Labels</span>
                            <span class="backend-service-panel__row-sub">Template-Namen einblenden (nur Entwickler-Modus)</span>
                        </span>
                        <span id="js-partial-labels-indicator" class="backend-service-panel__toggle<?= $partialLabels ? ' backend-service-panel__toggle--on' : '' ?>" aria-hidden="true"><span class="backend-service-panel__toggle-thumb"></span></span>
                    </button>
                    <button type="button" id="js-clear-cache" data-url="/backend/system/system/clear-cache" class="backend-service-panel__row">
                        <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-trash"/></svg></span>
                        <span class="backend-service-panel__row-body">
                            <span class="backend-service-panel__row-label">Cache leeren</span>
                            <span class="backend-service-panel__row-sub">APCu-Cache zurücksetzen</span>
                        </span>
                        <span class="backend-service-panel__row-badge">Jetzt</span>
                    </button>
                </div>

                <div class="backend-service-panel__divider"></div>

                <div class="backend-service-panel__section">
                    <button type="button" id="js-appearance-toggle" class="backend-service-panel__section-toggle" aria-expanded="false" aria-controls="js-appearance-body">
                        Aussehen
                        <svg class="be-icon backend-service-panel__section-chevron" width="10" height="10" aria-hidden="true"><use href="#icon-chevron-down"/></svg>
                    </button>
                    <div id="js-appearance-body" hidden>
                        <button type="button" id="js-dark-toggle" class="backend-service-panel__row">
                            <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-moon"/></svg></span>
                            <span class="backend-service-panel__row-body">
                                <span class="backend-service-panel__row-label">Dark Mode</span>
                                <span class="backend-service-panel__row-sub">Dunkles Farbschema</span>
                            </span>
                            <span id="js-dark-indicator" class="backend-service-panel__toggle<?= !empty($userPreferences) && $userPreferences->isDarkMode() ? ' backend-service-panel__toggle--on' : '' ?>" aria-hidden="true"><span class="backend-service-panel__toggle-thumb"></span></span>
                        </button>
                        <div class="backend-service-panel__palette-grid">
                            <?php foreach ($palettes as $pl): ?>
                            <button type="button" class="backend-service-panel__palette-btn<?= !empty($userPreferences) && $userPreferences->getPalette() === $pl['id'] ? ' backend-service-panel__palette-btn--active' : '' ?>"
                                    data-palette-btn="<?= e($pl['id']) ?>" title="<?= e($pl['name']) ?>">
                                <span class="backend-service-panel__palette-dot" style="background:<?= e($pl['accent']) ?>"></span>
                                <?= e($pl['name']) ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <div class="backend-service-panel__row backend-service-panel__font-row">
                            <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-type"/></svg></span>
                            <div class="backend-service-panel__row-body">
                                <span class="backend-service-panel__row-label">Schriftgrösse</span>
                                <div class="backend-service-panel__font-slider">
                                    <span class="backend-service-panel__font-slider-a">A</span>
                                    <input type="range" id="js-font-scale" class="backend-service-panel__font-range" min="100" max="140" step="5"
                                           value="<?= (int) round((!empty($userPreferences) ? $userPreferences->getFontScale() : 1.0) * 100) ?>">
                                    <span class="backend-service-panel__font-slider-a backend-service-panel__font-slider-a--lg">A</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="backend-service-panel__divider"></div>

                <div class="backend-service-panel__section">
                    <div class="backend-service-panel__section-label">Konto</div>
                    <a href="/backend/system/login/logout" class="backend-service-panel__row backend-service-panel__row--danger">
                        <span class="backend-service-panel__row-icon"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-logout"/></svg></span>
                        <span class="backend-service-panel__row-body">
                            <span class="backend-service-panel__row-label">Abmelden</span>
                            <span class="backend-service-panel__row-sub">Aktuelle Sitzung beenden</span>
                        </span>
                    </a>
                </div>

                <div class="backend-service-panel__footer"><span>z77 · Version 1.0.0</span></div>
            </div>
        </div>
    </div>

</header>
