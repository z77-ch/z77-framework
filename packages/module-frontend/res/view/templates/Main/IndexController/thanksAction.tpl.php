<?php
/**
 * Thank-you page — the PRG target of contactAction (see
 * docs/topics/forms.md). A page of its own on purpose: whether a form was sent
 * is visible in the URL, not hidden in a session flag that makes the contact
 * page render a different body. Directly reachable, which is intended — it
 * states nothing the visitor did not just do.
 *
 * @var string $pageTitle
 */
?>
<section class="fe-hero">
    <div class="fe-container">
        <h1 class="fe-hero__title"><?= e(t('form.sent.title')) ?></h1>
        <p class="fe-hero__subline"><?= e(t('form.sent.copy')) ?></p>
    </div>
</section>
