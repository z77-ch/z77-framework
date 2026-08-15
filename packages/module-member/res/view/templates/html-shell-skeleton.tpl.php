<!DOCTYPE html>
<?php
/**
 * Member SHELL skeleton — the signed-in surface.
 *
 * Two skeletons, two situations. `html-default-skeleton` is the door: one
 * centred card, no navigation — login, register, confirm, the waiting page.
 * This one is the room behind it.
 *
 * ── The shell is ONE grid (B10 spec v1.4.1, decision 5; v1.7.0 adds a row) ──
 * Three columns (rail · seam · content) and FOUR rows: header, the page's
 * tabs beside the area action, the breadcrumb line, work. That is why the
 * grid lives HERE and not in a partial: only the skeleton has `$main`, and
 * the content cell IS `$main`. Everything else falls out of the single grid —
 * the seam runs from the header to the foot, the header splits at the same
 * edge as the panes, and the horizontal edges meet because cells of one grid
 * row are the same height by construction.
 *
 * The breadcrumb got its own, slimmer row in v1.7.0: it carries smaller type
 * and shared its line with nothing that belonged to it. The toolbar row now
 * carries the PAGE's tabs (`$shellTabs`, sections of one surface switched
 * client-side — shell.js); crumb-target actions sit on the crumb's row.
 *
 * ── Two shapes, one skeleton ──
 * A page with a rail (`$railItems` present) gets the work area; a page without
 * keeps the old single-column shape (`--plain`). A second skeleton for that
 * would mean two places to change when the chrome changes.
 *
 * @var string $memberTheme  'light' | 'dark' | '' (no decision — follow the system)
 * @var ?array $railItems    rows of the left column; absent = plain page
 * @var ?array $shellAction  ['label','href','method','quiet'] — the area's one action
 * @var ?array $shellTabs    the page's tabs [{id,label,active?}]; absent = none
 * @var ?array $crumbs       breadcrumb rows for the crumb line
 * @var ?string $crumbActions raw HTML acting on the crumb target, right of it
 */
$theme = in_array($memberTheme ?? '', ['light', 'dark'], true) ? $memberTheme : '';
$work  = !empty($railItems) || !empty($shellAction);
?>
<html lang="<?= e($language ?? 'de') ?>" class="me"<?= $theme !== '' ? ' data-theme="' . e($theme) . '"' : '' ?>>
<head>
    <?= $head ?? '' ?>
    <title><?= e($pageTitle ?? 'Member') ?></title>
    <?= $css ?? '' ?>
    <?= $jsHead ?? '' ?>
</head>
<?php if ($work): ?>
<?php /* `data-z77-split-root` sits on the BODY, not on the split below: the
         handle writes `--rail-w`, and the header cells of rows 1 and 2 read the
         same variable through the grid. Dragging the column therefore moves the
         whole left side, not just the pane (spec v1.4.1). The primitive allows
         the split — see its markup contract. */ ?>
<body class="me-body me-body--shell" data-z77-split-root<?= !empty($detailOpen) ? ' data-z77-split-overlay="detail"' : '' ?>>
    <?= $flash ?? '' ?>

    <?= $this->partial('partials/shell/headLeft', [
        'areaName' => $areaName ?? '',
        'areas'    => $areas ?? [],
    ]) ?>

    <div class="me-shell__seam"></div>

    <?= $this->partial('partials/shell/userMenu', [
        'memberUser'   => $memberUser ?? null,
        'memberTheme'  => $memberTheme ?? '',
        'memberTenant' => $memberTenant ?? '',
    ]) ?>

    <div class="me-shell__act">
        <?php if (!empty($shellAction)): ?>
        <?= $this->partial('partials/shell/action', [
            'action'    => $shellAction,
            'csrfToken' => $csrfToken ?? '',
        ]) ?>
        <?php endif; ?>
    </div>

    <div class="me-shell__toolbar">
        <?php if (!empty($shellTabs)): ?>
        <?= $this->partial('partials/shell/tabs', ['tabs' => $shellTabs]) ?>
        <?php endif; ?>
    </div>

    <?php /* Row 3 — the crumb line. Column 1 is a bare cell so the dark island
             runs through; the actions on the right belong to the crumb TARGET
             (the Drive pattern), never to the area — that one sits above. */ ?>
    <div class="me-shell__crumbgap"></div>
    <div class="me-shell__crumbs">
        <?= $this->partial('partials/shell/crumbs', [
            'crumbs'    => $crumbs ?? [],
            'csrfToken' => $csrfToken ?? '',
        ]) ?>
        <?php if (!empty($crumbActions)): ?>
        <div class="me-shell__crumb-actions"><?= $crumbActions ?></div>
        <?php endif; ?>
    </div>

    <?php /* Row 3 — the shared primitive. The rail is pane 1 (fixed width,
             drag-resizable), the detail takes the rest AND becomes an overlay
             on a narrow container. The overlay opens server-side: when
             something is selected, the body carries the attribute; the
             backdrop and the `‹ Liste` button close it client-side. */ ?>
    <div class="me-shell__work">
        <div class="z77-split" style="--z77-split-1: var(--rail-w, 17rem)">
            <div class="z77-split__pane me-rail">
                <?= $this->partial('partials/shell/rail', [
                    'items'     => $railItems ?? [],
                    'emptyNote' => $railEmpty ?? '',
                ]) ?>
            </div>
            <?php /* The bounds are px, the token is rem — 200…560px leaves room
                     on both sides of the project's own width, which is what a
                     drag handle is for. Bounds that hug the default make the
                     handle look broken. */ ?>
            <span class="z77-split__handle" data-z77-split="--rail-w"
                  data-z77-split-min="200" data-z77-split-max="560"></span>
            <div class="z77-split__pane z77-split__pane--grow z77-split__pane--detail">
                <?= $main ?? '' ?>
            </div>
            <div class="z77-split__backdrop" data-z77-split-close></div>
        </div>
    </div>

    <?= $jsFooter ?? '' ?>
</body>
<?php else: ?>
<body class="me-body me-body--shell me-body--plain">
    <?= $flash ?? '' ?>
    <?= $shellHeader ?? '' ?>
    <main class="me-shell__main">
        <?= $main ?? '' ?>
    </main>
    <?= $shellFooter ?? '' ?>
    <?= $jsFooter ?? '' ?>
</body>
<?php endif; ?>
</html>
