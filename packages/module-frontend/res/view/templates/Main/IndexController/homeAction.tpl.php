<?php
/**
 * Home — hero, intro text, cards, call to action. The text is the content
 * document `home` (IndexController::homeAction); this template only places
 * the section partials.
 *
 * @var array $hero  @var array $intro  @var array $cards  @var array $cta
 */
?>
<?= $this->partial('partials/sections/hero', ['section' => $hero]) ?>
<?= $this->partial('partials/sections/textSection', ['section' => $intro]) ?>
<?= $this->partial('partials/sections/cards', ['section' => $cards, 'alt' => true]) ?>
<?= $this->partial('partials/sections/cta', ['section' => $cta]) ?>
