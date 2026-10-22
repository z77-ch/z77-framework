<?php
/**
 * Section «image and text» (block type `image-text`) — text next to one image.
 * Data: PageSections::imageText(). Without an image the text stands alone.
 * `parasHtml` is already safe HTML (ContentView), hence raw().
 *
 * @var array{eyebrow:string,title:string,parasHtml:list<string>,image:string,alt:string,imageLeft:bool,edit:string} $section
 * @var bool $alt  alternate background
 */
$layout = 'fe-image-text'
    . ($section['image'] === '' ? ' fe-image-text--text-only' : '')
    . ($section['imageLeft'] ? ' fe-image-text--left' : '');
?>
<section class="fe-section<?= !empty($alt) ? ' fe-section--alt' : '' ?>"<?= raw($section['edit']) ?>>
    <div class="fe-container <?= e($layout) ?>">
        <div class="fe-image-text__text">
            <?php if ($section['eyebrow'] !== ''): ?>
            <div class="fe-section__eyebrow"><?= e($section['eyebrow']) ?></div>
            <?php endif; ?>
            <?php if ($section['title'] !== ''): ?>
            <h2 class="fe-section__title"><?= e($section['title']) ?></h2>
            <?php endif; ?>
            <?php if ($section['parasHtml'] !== []): ?>
            <div class="fe-prose fe-section__body">
                <?php foreach ($section['parasHtml'] as $paragraph): ?>
                <p><?= raw($paragraph) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($section['image'] !== ''): ?>
        <figure class="fe-image-text__media">
            <img src="<?= e($section['image']) ?>" alt="<?= e($section['alt']) ?>" loading="lazy">
        </figure>
        <?php endif; ?>
    </div>
</section>
