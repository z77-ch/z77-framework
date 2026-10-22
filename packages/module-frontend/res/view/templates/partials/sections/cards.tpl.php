<?php
/**
 * Section «cards» (block type `cards`) — optional overline and heading over a
 * grid of cards. Data: PageSections::cards(). The number on each card is its
 * position, not content. Three or six cards sit in thirds on wide screens,
 * otherwise in quarters.
 *
 * @var array{eyebrow:string,title:string,items:list<array{title:string,text:string}>,edit:string} $section
 * @var bool $alt  alternate background
 */
$count = count($section['items']);
?>
<section class="fe-section<?= !empty($alt) ? ' fe-section--alt' : '' ?>"<?= raw($section['edit']) ?>>
    <div class="fe-container">
        <?php if ($section['eyebrow'] !== ''): ?>
        <div class="fe-section__eyebrow"><?= e($section['eyebrow']) ?></div>
        <?php endif; ?>
        <?php if ($section['title'] !== ''): ?>
        <h2 class="fe-section__title"><?= e($section['title']) ?></h2>
        <?php endif; ?>
        <div class="fe-grid<?= $count % 3 === 0 ? ' fe-grid--thirds' : '' ?>">
            <?php foreach ($section['items'] as $i => $item): ?>
            <article class="fe-item">
                <div class="fe-item__number"><?= e(sprintf('%02d', $i + 1)) ?></div>
                <h3 class="fe-item__title"><?= e($item['title']) ?></h3>
                <?php if ($item['text'] !== ''): ?>
                <p class="fe-item__text"><?= e($item['text']) ?></p>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
