<?php
/**
 * Frontend footer. Brand = the installation's brand mark (`Z77\Shared`
 * partials/brandMark, unlinked here); link columns = the navigation slots, their
 * headings from the UI dictionary; tagline and copyright holder = the shared
 * content document `footer` (Ui\Services\FooterContent, read here because the
 * footer is a layout element on every page — like the navigation below). An
 * empty value leaves its line out.
 *
 * @var \Z77\Core\Services\NavigationService $navigationService
 * @var \Z77\Shared\Entities\Navigation $entry
 */
$navMain = $navigationService->getBySlot('frontend-main');
$navMeta = $navigationService->getBySlot('frontend-meta');
$texts   = \Z77\Module\Frontend\Ui\Services\FooterContent::data();
?>
<footer class="fe-footer"<?= raw($texts['edit']) ?>>
    <div class="fe-container fe-footer__grid">
        <div>
            <?= $this->partial('partials/brandMark', ['class' => 'fe-footer__brand', 'href' => ''], 'Z77\\Shared') ?>
            <?php if ($texts['tagline'] !== ''): ?>
            <p class="fe-footer__tag"><?= e($texts['tagline']) ?></p>
            <?php endif; ?>
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

    <?php if ($texts['copyright'] !== ''): ?>
    <div class="fe-container fe-footer__bottom">
        <small class="fe-footer__copy">&copy; <?= e(date('Y')) ?> <?= e($texts['copyright']) ?></small>
    </div>
    <?php endif; ?>
</footer>
