<?php
/**
 * Content-header middle slot (hc2) for `content/content/list`, loaded as a partial into the
 * shell's `contentHead` body section (shell rebuild Phase 2 — the aligned header band is
 * filled per controller/action). The `.be-shell-band__slot` wrapper (flex row) is provided by
 * the skeleton; this partial supplies its inner content. Rendered with the action context
 * (same `$this->context` as `main`), so `$editLanguage` / `$editLanguages` are available.
 *
 * Layout: the editing-language switcher — tabs, the underline look of `.be-viewtabs`. The add
 * action moved to the action cell (`list.act.tpl.php`, ADR-033 rev. 2026-10-08).
 *
 * @var string            $editLanguage
 * @var array<int,string> $editLanguages
 */
?>
<?php if (count($editLanguages) > 1): ?>
<div class="be-lang-switch" role="group" aria-label="Bearbeitungssprache">
    <span class="be-lang-switch__label">Bearbeitungssprache:
        <span class="be-lang-switch__current"><?= e(strtoupper($editLanguage)) ?></span>
    </span>
    <div class="be-lang-switch__options">
        <?php foreach ($editLanguages as $lang): ?>
        <a class="be-lang-switch__option<?= $lang === $editLanguage ? ' be-lang-switch__option--active' : '' ?>"
           href="/backend/content/content/list?language=<?= e(rawurlencode($lang)) ?>"
           <?= $lang === $editLanguage ? 'aria-current="true"' : '' ?>><?= e(strtoupper($lang)) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
