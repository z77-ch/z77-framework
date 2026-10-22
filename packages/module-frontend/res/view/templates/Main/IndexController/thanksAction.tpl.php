<?php
/**
 * Thank-you page — the PRG target of contactAction (see docs/topics/forms.md).
 * A page of its own on purpose: whether a form was sent is visible in the URL,
 * not hidden in a session flag that makes the contact page render a different
 * body. Directly reachable, which is intended — it states nothing the visitor
 * did not just do. The text is the content document `thanks`.
 *
 * @var array $hero
 */
?>
<?= $this->partial('partials/sections/hero', ['section' => $hero]) ?>
