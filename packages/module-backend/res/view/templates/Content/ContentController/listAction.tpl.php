<?php
/** @var \Z77\Shared\Entities\Content[] $contents */
/** @var string $editLanguage   the active content-editing language (session-sticky) */
/** @var array<int,string> $editLanguages   all servable languages (config/i18n) */
?>
<?php /* Content-header (language switcher + title + add) moved to the shell hc2 slot:
         Content/ContentController/hc2.tpl.php (added as the `contentHead` section in listAction). */ ?>
<div class="be-list">
    <section class="be-list__section">
        <div class="be-tree be-tree--hub">
            <?php if (empty($contents)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Noch keine Inhalte vorhanden.</p>
            <?php endif; ?>

            <?php foreach ($contents as $c):
                $blockCount = count($c->getBlocks());
                $meta = $c->getLanguage() . ' · ' . $blockCount . ' Block' . ($blockCount === 1 ? '' : 'e') . ($c->isActive() ? '' : ' · inaktiv');
            ?>
            <div class="be-tree__node<?= $c->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:0"
                 data-content-slug="<?= e($c->getSlug()) ?>" data-content-lang="<?= e($c->getLanguage()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <label class="be-switch be-switch--sm be-tree__switch" title="Aktiv schalten">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="/backend/content/content/toggle-active?slug=<?= e(rawurlencode($c->getSlug())) ?>&language=<?= e(rawurlencode($c->getLanguage())) ?>"<?= $c->isActive() ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/content/actions?slug=<?= e(rawurlencode($c->getSlug())) ?>&language=<?= e(rawurlencode($c->getLanguage())) ?>">⋮</button>
                    <span class="be-tree__name"><?= e($c->getTitle() !== '' ? $c->getTitle() : $c->getSlug()) ?></span>
                    <span class="be-tree__url"><?= e($c->getSlug()) ?></span>
                    <span class="be-tree__route"><?= e($meta) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
