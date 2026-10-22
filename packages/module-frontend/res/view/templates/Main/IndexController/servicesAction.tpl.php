<?php
/**
 * Services — hero, cards. The text is the content document `services`
 * (IndexController::servicesAction); this template only places the section
 * partials.
 *
 * @var array $hero  @var array $cards
 */
?>
<?= $this->partial('partials/sections/hero', ['section' => $hero]) ?>
<?= $this->partial('partials/sections/cards', ['section' => $cards]) ?>
