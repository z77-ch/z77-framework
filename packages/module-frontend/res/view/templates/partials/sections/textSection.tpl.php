<?php
/**
 * Section «text» (block type `text-section`) — optional overline, a heading and
 * paragraphs. Data: PageSections::textSection(). `parasHtml` is already safe
 * HTML (escaped, then only the field's inline profile — ContentView), hence raw().
 *
 * @var array{eyebrow:string,title:string,parasHtml:list<string>,edit:string} $section
 * @var string $titleTag  'h1' when the section opens the page (no hero), default 'h2'
 * @var bool   $alt       alternate background
 */
$tag = ($titleTag ?? 'h2') === 'h1' ? 'h1' : 'h2';
?>
<section class="fe-section<?= !empty($alt) ? ' fe-section--alt' : '' ?>"<?= raw($section['edit']) ?>>
    <div class="fe-container">
        <?php if ($section['eyebrow'] !== ''): ?>
        <div class="fe-section__eyebrow"><?= e($section['eyebrow']) ?></div>
        <?php endif; ?>
        <?php if ($section['title'] !== ''): ?>
        <<?= $tag ?> class="fe-section__title"><?= e($section['title']) ?></<?= $tag ?>>
        <?php endif; ?>
        <?php if ($section['parasHtml'] !== []): ?>
        <div class="fe-prose fe-section__body">
            <?php foreach ($section['parasHtml'] as $paragraph): ?>
            <p><?= raw($paragraph) ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>
