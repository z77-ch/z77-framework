<?php
/**
 * Section «hero» (block type `hero`) — the page title with an optional overline
 * and subline. Data: PageSections::hero(). Markup only; the text is the page's
 * content document. `edit` = the page-editor marker of the slot (ADR-045 §4),
 * '' for visitors — escaped by ContentView, hence raw().
 *
 * @var array{eyebrow:string,title:string,subline:string,edit:string} $section
 */
?>
<section class="fe-hero"<?= raw($section['edit']) ?>>
    <div class="fe-container">
        <?php if ($section['eyebrow'] !== ''): ?>
        <div class="fe-hero__eyebrow"><?= e($section['eyebrow']) ?></div>
        <?php endif; ?>
        <h1 class="fe-hero__title"><?= e($section['title']) ?></h1>
        <?php if ($section['subline'] !== ''): ?>
        <p class="fe-hero__subline"><?= e($section['subline']) ?></p>
        <?php endif; ?>
    </div>
</section>
