<?php
/**
 * Long text with headings — Impressum, Datenschutzerklärung: one prose column,
 * one block per `text-section` slot (empty slots are already left out by
 * PageSections::textSections()). Each block carries its own page-editor marker.
 * `parasHtml` is already safe HTML (ContentView), hence raw().
 *
 * @var list<array{eyebrow:string,title:string,parasHtml:list<string>,edit:string}> $sections
 */
?>
<?php if ($sections !== []): ?>
<section class="fe-section">
    <div class="fe-container">
        <div class="fe-prose">
            <?php foreach ($sections as $section): ?>
            <div class="fe-prose__block"<?= raw($section['edit']) ?>>
                <?php if ($section['title'] !== ''): ?>
                <h2><?= e($section['title']) ?></h2>
                <?php endif; ?>
                <?php foreach ($section['parasHtml'] as $paragraph): ?>
                <p><?= raw($paragraph) ?></p>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>
