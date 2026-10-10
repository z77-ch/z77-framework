<?php
/** @var list<array{key: string, values: array<string, string>, summary: string}> $uiRows */
/** @var list<string> $slugLanguages */
/** @var list<array{canonical: string, values: array<string, string>, summary: string}> $slugRows */
/** @var string $defaultLang */

// `summary` is pre-escaped HTML from TranslationController::valueSummary() — the same
// source fills the `update-fields` answer of a save. Rows carry
// `data-entity="translationUi:<key>"` / `"translationSlug:<canonical>"` (the entry-CSRF
// scopes) so a save / delete answers in place (ADR-047 addendum 2026-10-10).
?>
<?php /* Header band: both add kinds (Text / Slug) live in the action cell as ONE picker
         «+ Eintrag ▾» (`list.act`, ADR-033 rev. 2026-10-08). The section heads below carry only
         their titles. */ ?>
<div class="be-list">
    <!-- ── UI strings ─────────────────────────────────────────────────────── -->
    <div class="be-list__section">
        <div class="be-list__section__head" style="margin-bottom:.5rem">
            <h2 style="font-size:.95rem;margin:0">UI-Texte</h2>
        </div>
        <div class="be-tree be-tree--hub be-tree--lead-menu">
            <?php if (empty($uiRows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine UI-Texte vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($uiRows as $row): ?>
            <div class="be-tree__node" style="--node-depth:0" data-entity="translationUi:<?= e($row['key']) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/translation/actions?kind=ui&key=<?= e(rawurlencode($row['key'])) ?>">⋮</button>
                    <span class="be-tree__name"><code><?= e($row['key']) ?></code></span>
                    <span class="be-tree__url" data-field="summary"><?= raw($row['summary']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── Route slugs ────────────────────────────────────────────────────── -->
    <div class="be-list__section" style="margin-top:1.5rem">
        <div class="be-list__section__head" style="margin-bottom:.5rem">
            <h2 style="font-size:.95rem;margin:0">Routen-Slugs</h2>
            <p style="font-size:.75rem;color:var(--be-muted,#94a3b8);margin:.15rem 0 0">Kanonisch (<code><?= e($defaultLang) ?></code>) → lokalisiert. Pro Sprache 1:1, kein Slug darf einen anderen kanonischen verdecken.</p>
        </div>
        <div class="be-tree be-tree--hub be-tree--lead-menu">
            <?php if (empty($slugLanguages)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Nur die Standardsprache ist konfiguriert — keine Slug-Übersetzungen nötig.</p>
            <?php elseif (empty($slugRows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Routen-Slugs vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($slugRows as $row): ?>
            <div class="be-tree__node" style="--node-depth:0" data-entity="translationSlug:<?= e($row['canonical']) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/translation/actions?kind=slug&key=<?= e(rawurlencode($row['canonical'])) ?>">⋮</button>
                    <span class="be-tree__name"><code><?= e($row['canonical']) ?></code></span>
                    <span class="be-tree__url" data-field="summary"><?= raw($row['summary']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
