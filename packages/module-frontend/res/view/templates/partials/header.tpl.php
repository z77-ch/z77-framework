<?php
/** @var \Z77\Core\Services\NavigationService $navigationService */
/** @var \Z77\Shared\Entities\Navigation $entry */
$navEntries = $navigationService->getBySlot('frontend-main');
?>
<input type="checkbox" id="fe-nav-toggle" class="fe-nav-toggle" aria-label="<?= e(t('nav.aria.mobile')) ?>">
<header class="fe-topbar">
    <div class="fe-container fe-topbar__inner">
        <a class="fe-topbar__brand" href="/">z77</a>

        <nav class="fe-topbar__nav" aria-label="<?= e(t('nav.aria.primary')) ?>">
            <?php foreach ($navEntries as $entry): ?>
                <a href="<?= e(localizedUrl($navigationService->urlFor($entry))) ?>"
                   class="fe-topbar__link<?= $navigationService->isActive($entry) ? ' fe-topbar__link--active' : '' ?>">
                    <?= e(t('nav.' . $entry->getAction())) ?>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if (!empty($languageSwitch)): ?>
        <nav class="fe-topbar__nav fe-topbar__nav--lang" aria-label="<?= e(t('nav.aria.language')) ?>">
            <?php foreach ($languageSwitch as $lang): ?>
                <a href="<?= e($lang['url']) ?>"
                   class="fe-topbar__link fe-topbar__link--lang<?= $lang['active'] ? ' fe-topbar__link--active' : '' ?>"
                   <?= $lang['active'] ? 'aria-current="true"' : '' ?>>
                    <?= e(t('lang.' . $lang['code'], [], $lang['code'])) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php endif; ?>

        <label class="fe-topbar__hamburger" for="fe-nav-toggle" aria-hidden="true">
            <span class="fe-topbar__hamburger-icon"></span>
        </label>
    </div>
</header>

<div class="fe-nav-overlay" id="fe-nav-overlay" aria-label="<?= e(t('nav.aria.mobile')) ?>">
    <ul class="fe-nav-overlay__list">
        <?php foreach ($navEntries as $entry): ?>
        <li>
            <a href="<?= e(localizedUrl($navigationService->urlFor($entry))) ?>"
               class="fe-nav-overlay__link<?= $navigationService->isActive($entry) ? ' fe-nav-overlay__link--active' : '' ?>">
                <?= e(t('nav.' . $entry->getAction())) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (!empty($languageSwitch)): ?>
    <ul class="fe-nav-overlay__list fe-nav-overlay__list--lang">
        <?php foreach ($languageSwitch as $lang): ?>
        <li>
            <a href="<?= e($lang['url']) ?>"
               class="fe-nav-overlay__link fe-nav-overlay__link--lang<?= $lang['active'] ? ' fe-nav-overlay__link--active' : '' ?>">
                <?= e(t('lang.' . $lang['code'], [], $lang['code'])) ?>
            </a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>
