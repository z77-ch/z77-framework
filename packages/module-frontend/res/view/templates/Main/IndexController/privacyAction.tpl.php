<?php
/**
 * Datenschutzerklärung — hero + the usual headings as one prose column. The text is the
 * content document `privacy`; a heading left empty is not shown.
 *
 * @var array $hero  @var list<array> $sections
 */
?>
<?= $this->partial('partials/sections/hero', ['section' => $hero]) ?>
<?= $this->partial('partials/sections/legalText', ['sections' => $sections]) ?>
