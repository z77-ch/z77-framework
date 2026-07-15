<?php
/** @var ?\Z77\Shared\Entities\Content $content */
/** @var string $pageTitle */

// Bespoke mode: the designer owns every tag/class and reads block data via
// BlockView (Content::block/blocks/has). Plain text → text(), inline-formatted
// → html(), raw → get() + e(). A missing block is a null-object, so has() only
// gates the wrapper. The stream renderer is not involved here.
?>
<?php if ($content?->has('hero')): $hero = $content->block('hero'); ?>
<section class="fe-hero">
    <div class="fe-container">
        <div class="fe-hero__eyebrow"><?= $hero->html('eyebrow') ?></div>
        <h1 class="fe-hero__title"><?= $hero->html('title') ?></h1>
        <p class="fe-hero__subline"><?= $hero->html('subline') ?></p>
    </div>
</section>
<?php endif; ?>

<?php foreach ($content?->blocks('prose-section') ?? [] as $i => $sec): ?>
<section class="fe-section<?= $i % 2 === 1 ? ' fe-section--alt' : '' ?>">
    <div class="fe-container">
        <div class="fe-section__eyebrow"><?= $sec->html('eyebrow') ?></div>
        <h2 class="fe-section__title"><?= $sec->html('title') ?></h2>
        <p class="fe-section__lead"><?= $sec->html('lead') ?></p>
    </div>
</section>
<?php endforeach; ?>

<?php if ($content?->has('features')): $f = $content->block('features'); ?>
<section class="fe-section">
    <div class="fe-container">
        <div class="fe-section__eyebrow"><?= $f->html('eyebrow') ?></div>
        <h2 class="fe-section__title"><?= $f->html('title') ?></h2>
        <div class="fe-grid">
        <?php foreach ($f->list('items') as $item): ?>
            <article class="fe-item fe-item--half">
                <div class="fe-item__number"><?= $item->text('number') ?></div>
                <h3 class="fe-item__title"><?= $item->html('title') ?></h3>
                <p class="fe-item__text"><?= $item->html('text') ?></p>
            </article>
        <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
