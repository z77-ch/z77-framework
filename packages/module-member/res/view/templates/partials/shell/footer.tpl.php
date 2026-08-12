<?php
/**
 * Shell footer — who is signed in, and whatever line the project wants beside
 * it. Both halves are optional; with neither the footer stays out of the
 * document entirely rather than drawing an empty rule.
 *
 * «Angemeldet als …» belongs on every screen of a tool where one browser can
 * hold two identities (backend user and member, ADR-029): the question «as whom
 * am I doing this?» must never need a click.
 *
 * @var array{name:string,email:string}|null $memberUser
 * @var string|null $shellNote  project line, e.g. an imprint reference
 */
$note = $shellNote ?? '';
if (empty($memberUser) && $note === '') {
    return;
}
?>
<footer class="me-shell__footer">
    <div class="me-shell__bar">
        <?php if (!empty($memberUser)): ?>
        <span class="me-shell__who">
            Angemeldet als <?= e($memberUser['email']) ?><?= $memberUser['name'] !== '' ? ' — ' . e($memberUser['name']) : '' ?>
        </span>
        <?php endif; ?>
        <?php if ($note !== ''): ?>
        <span class="me-shell__note"><?= e($note) ?></span>
        <?php endif; ?>
    </div>
</footer>
