<?php
/** @var \Z77\Shared\Entities\Content[] $contents   sorted: slug, live first, variants by key, versions newest first */
/** @var string $editLanguage   the active content-editing language (session-sticky) */
/** @var array<int,string> $editLanguages   all servable languages (config/i18n) */

// «22.09.2026 15:30» from a changedAt (ISO-8601); '' when unknown or unreadable.
$when = function (string $iso): string {
    try {
        return $iso !== '' ? (new DateTimeImmutable($iso))->format('d.m.Y H:i') : '';
    } catch (Exception) {
        return '';
    }
};
// What a row is: live (with its last save), variant, or version (whose state, from when).
$rowLabel = function (\Z77\Shared\Entities\Content $c) use ($when): string {
    $at = $when($c->getChangedAt());
    $by = $c->getChangedBy();
    if ($c->isVersion()) {
        if ($at !== '') {
            return 'Version ' . $at . ($by !== '' ? ' · ' . $by : '') . ' · ';
        }
        // Archived before ADR-045 stamped documents: only the key knows when.
        $archived = DateTimeImmutable::createFromFormat('Ymd-His', substr($c->getVariant(), 2, 15));
        return 'Version (älterer Stand' . ($archived ? ', gesichert ' . $archived->format('d.m.Y H:i') : '') . ') · ';
    }
    return $c->isLive() ? '' : 'Variante ' . $c->getVariant() . ' · ';
};
$lastSaved = function (\Z77\Shared\Entities\Content $c) use ($when): string {
    $at = $when($c->getChangedAt());
    if (!$c->isLive() || $at === '') {
        return '';
    }
    return ' · zuletzt gespeichert ' . $at . ($c->getChangedBy() !== '' ? ' von ' . $c->getChangedBy() : '');
};
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
                $meta = $rowLabel($c)
                      . $c->getLanguage() . ' · ' . $blockCount . ' Block' . ($blockCount === 1 ? '' : 'e') . ($c->isActive() ? '' : ' · inaktiv')
                      . $lastSaved($c);
                $qs   = 'slug=' . rawurlencode($c->getSlug()) . '&language=' . rawurlencode($c->getLanguage()) . '&variant=' . rawurlencode($c->getVariant());
            ?>
            <div class="be-tree__node<?= $c->isActive() ? '' : ' be-tree__node--inactive' ?>" style="--node-depth:<?= $c->isLive() ? 0 : 1 ?>"
                 data-content-slug="<?= e($c->getSlug()) ?>" data-content-lang="<?= e($c->getLanguage()) ?>" data-content-variant="<?= e($c->getVariant()) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <?php if ($c->isVersion()): /* history: shown, not switched — restore it first */ ?>
                    <label class="be-switch be-switch--sm be-tree__switch" title="Version — nicht schaltbar">
                        <input type="checkbox" class="be-switch__input" disabled<?= $c->isActive() ? ' checked' : '' ?>>
                    <?php else: ?>
                    <label class="be-switch be-switch--sm be-tree__switch" title="Aktiv schalten">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="/backend/content/content/toggle-active?<?= e($qs) ?>"<?= $c->isActive() ? ' checked' : '' ?>>
                    <?php endif; ?>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/content/actions?<?= e($qs) ?>">⋮</button>
                    <span class="be-tree__name"><?= e($c->getTitle() !== '' ? $c->getTitle() : $c->getSlug()) ?></span>
                    <span class="be-tree__url"><?= e($c->getSlug()) ?></span>
                    <span class="be-tree__route"><?= e($meta) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </section>
</div>
