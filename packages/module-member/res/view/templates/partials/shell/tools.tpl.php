<?php
/**
 * The page's tools — the toolbar row's content when the page has no tabs
 * (ADR-033: the action cell decides, the toolbar operates).
 *
 * Tools act on the thing the crumb names (the chosen entry): Kopieren,
 * Vorschau, Bearbeiten, Löschen. Labelled buttons, not icons — the eye reads
 * words faster than it guesses glyphs. What ends or destroys something is not
 * emphasised here either; a destructive tool carries `confirm` and stays a
 * POST, because it changes the world and deserves the reload with a message.
 *
 * Kinds, decided by the keys a tool carries:
 * - `post` (an action URL) renders a small form: hidden `fields`, CSRF,
 *   `confirm` as the data-confirm question.
 * - `href` renders a link; `target` (e.g. `_blank`) travels along with
 *   rel="noopener".
 * - otherwise a plain button whose `attrs` say what it does (e.g.
 *   `data-copy` for the shared core.js copy channel).
 *
 * @var array<int,array{label:string, href?:string, target?:string,
 *      post?:string, fields?:array<string,string>, confirm?:string,
 *      attrs?:array<string,string>}> $tools
 * @var string $csrfToken
 */
$attrString = static function (array $attrs): string {
    $out = '';
    foreach ($attrs as $name => $value) {
        $out .= ' ' . $name . '="' . e($value) . '"';
    }
    return $out;
};
?>
<div class="me-tools">
    <?php foreach ($tools as $tool): ?>
    <?php if (!empty($tool['post'])): ?>
    <form method="post" action="<?= e($tool['post']) ?>" class="me-tools__form"
          <?= !empty($tool['confirm']) ? 'data-confirm="' . e($tool['confirm']) . '"' : '' ?>>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <?php foreach ($tool['fields'] ?? [] as $name => $value): ?>
        <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
        <?php endforeach; ?>
        <button type="submit" class="me-tools__btn"><?= e($tool['label']) ?></button>
    </form>
    <?php elseif (!empty($tool['href'])): ?>
    <a class="me-tools__btn" href="<?= e($tool['href']) ?>"<?= !empty($tool['target']) ? ' target="' . e($tool['target']) . '" rel="noopener"' : '' ?>><?= e($tool['label']) ?></a>
    <?php else: ?>
    <button type="button" class="me-tools__btn"<?= $attrString($tool['attrs'] ?? []) ?>><?= e($tool['label']) ?></button>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
