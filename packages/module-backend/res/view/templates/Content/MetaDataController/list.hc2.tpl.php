<?php
/**
 * Metadata list — hc2 (toolbar): the editing-language tabs, then the environment filter as tabs
 * (`.be-viewtabs`, ADR-033 rev. 2026-10-08 — moved from the work area into the toolbar). No action
 * cell: metadata has no top-level add — it is edited per page. Auto-loaded into the shell header band.
 *
 * Both tab groups carry the other's state: a language link keeps `?env=`, the environment links
 * rely on the session-sticky editing language.
 *
 * @var string                                  $editLanguage
 * @var array<int,string>                       $editLanguages
 * @var array<int,array{key:string,label:string}> $environments
 * @var string                                  $envFilter
 */
$base      = '/backend/content/meta-data/list';
$langExtra = $envFilter !== '' ? '&env=' . rawurlencode($envFilter) : '';
?>
<?php if (count($editLanguages) > 1): ?>
<div class="be-lang-switch" role="group" aria-label="Bearbeitungssprache">
    <span class="be-lang-switch__label">Bearbeitungssprache:
        <span class="be-lang-switch__current"><?= e(strtoupper($editLanguage)) ?></span>
    </span>
    <div class="be-lang-switch__options">
        <?php foreach ($editLanguages as $code): ?>
        <a class="be-lang-switch__option<?= $code === $editLanguage ? ' be-lang-switch__option--active' : '' ?>"
           href="<?= e($base . '?language=' . rawurlencode($code) . $langExtra) ?>"
           <?= $code === $editLanguage ? 'aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?php if (count($environments) > 1): ?>
<nav class="be-viewtabs" aria-label="Umgebung">
    <a class="be-viewtabs__tab<?= $envFilter === '' ? ' is-active' : '' ?>" href="<?= e($base) ?>"<?= $envFilter === '' ? ' aria-current="page"' : '' ?>>Alle</a>
    <?php foreach ($environments as $env): ?>
    <a class="be-viewtabs__tab<?= $envFilter === $env['key'] ? ' is-active' : '' ?>"
       href="<?= e($base . '?env=' . rawurlencode($env['key'])) ?>"<?= $envFilter === $env['key'] ? ' aria-current="page"' : '' ?>><?= e($env['label']) ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
