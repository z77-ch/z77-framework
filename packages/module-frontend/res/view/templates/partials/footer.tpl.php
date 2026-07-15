<?php
/** @var \Z77\Core\Services\NavigationService $navigationService */
/** @var \Z77\Shared\Entities\Navigation $entry */
$navMain = $navigationService->getBySlot('frontend-main');
$navMeta = $navigationService->getBySlot('frontend-meta');
?>
<footer class="fe-footer">
    <div class="fe-container fe-footer__grid">
        <div>
            <div class="fe-footer__brand">z77</div>
            <p class="fe-footer__tag"><?= e(t('footer.tagline')) ?></p>
        </div>

        <div>
            <div class="fe-footer__col-title"><?= e(t('footer.pages')) ?></div>
            <div class="fe-footer__list">
                <?php foreach ($navMain as $entry): ?>
                    <a class="fe-footer__link" href="<?= e(localizedUrl($navigationService->urlFor($entry))) ?>"><?= e(t('nav.' . $entry->getAction())) ?></a>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <div class="fe-footer__col-title"><?= e(t('footer.legal')) ?></div>
            <div class="fe-footer__list">
                <?php foreach ($navMeta as $entry): ?>
                    <a class="fe-footer__link" href="<?= e(localizedUrl($navigationService->urlFor($entry))) ?>"><?= e(t('nav.' . $entry->getAction())) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="fe-container fe-footer__bottom">
        <small class="fe-footer__copy">&copy; <?= date('Y') ?> z77. <?= e(t('footer.rights')) ?></small>
        <small class="fe-footer__copy">v1.0.0</small>
    </div>
</footer>
