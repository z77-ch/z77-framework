<?php
/**
 * Section «call to action» (block type `cta`) — heading, one sentence, a
 * button. Data: PageSections::cta() (`href` already localised; '' = no button).
 *
 * @var array{title:string,text:string,label:string,href:string,edit:string} $section
 */
?>
<section class="fe-section fe-section--dark fe-cta"<?= raw($section['edit']) ?>>
    <div class="fe-container">
        <?php if ($section['title'] !== ''): ?>
        <h2 class="fe-section__title"><?= e($section['title']) ?></h2>
        <?php endif; ?>
        <?php if ($section['text'] !== ''): ?>
        <p class="fe-section__lead"><?= e($section['text']) ?></p>
        <?php endif; ?>
        <?php if ($section['href'] !== '' && $section['label'] !== ''): ?>
        <a class="fe-button fe-button--inverse" href="<?= e($section['href']) ?>"><?= e($section['label']) ?></a>
        <?php endif; ?>
    </div>
</section>
