<?php
/**
 * Shell header of a page WITHOUT a work area — the `--plain` shape of the
 * skeleton: mark on the left, the account cluster on the right.
 *
 * A page with a rail does not use this partial at all. There the header is two
 * cells of the one grid (`partials/shell/headLeft` + `partials/shell/userMenu`),
 * because it has to split at exactly the column edge — see the skeleton.
 *
 * The areas used to stand here as a tab row and then, briefly, inside the
 * account panel. Both are gone (B10 spec v1.4.1): they live at the four-square
 * switcher beside the area name, and the controller supplies them — nothing is
 * queried here that the controller could have decided.
 *
 * @var array{name:string,email:string,initials:string}|null $memberUser
 * @var string $memberTheme
 * @var string $areaName
 */
?>
<header class="me-shell__header">
    <div class="me-shell__bar">
        <?= $this->partial('partials/brandMark', ['class' => 'me-brand'], 'Z77\\Shared') ?>

        <?php if (trim((string)($areaName ?? '')) !== ''): ?>
        <span class="me-shell__area"><?= e($areaName) ?></span>
        <?php endif; ?>

        <?php if (!empty($memberUser)): ?>
        <?= $this->partial('partials/shell/userMenu', [
            'memberUser'  => $memberUser,
            'memberTheme' => $memberTheme ?? '',
        ]) ?>
        <?php endif; ?>
    </div>
</header>
