<?php
/**
 * About — hero, text, image and text. The text is the content document
 * `about` (IndexController::aboutAction); this template only places the
 * section partials.
 *
 * @var array $hero  @var array $text  @var array $image
 */
?>
<?= $this->partial('partials/sections/hero', ['section' => $hero]) ?>
<?= $this->partial('partials/sections/textSection', ['section' => $text]) ?>
<?= $this->partial('partials/sections/imageText', ['section' => $image, 'alt' => true]) ?>
