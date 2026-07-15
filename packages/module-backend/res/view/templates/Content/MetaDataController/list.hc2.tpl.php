<?php
/**
 * Metadata list — hc2 (middle slot): the editing-language switcher (frameless in the shell). The
 * environment filter (tabs) stays in the body. Auto-loaded into the shell header band. There is no
 * hc1 (metadata has no top-level add — it is edited per page).
 *
 * @var string              $editLanguage
 * @var array<int,string>   $editLanguages
 * @var string              $envFilter
 */
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
           href="<?= e('/backend/content/meta-data/list?language=' . rawurlencode($code) . $langExtra) ?>"
           <?= $code === $editLanguage ? 'aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
