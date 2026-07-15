<?php
/** @var list<string> $uiLanguages */
/** @var list<array{key: string, values: array<string, string>}> $uiRows */
/** @var list<string> $slugLanguages */
/** @var list<array{canonical: string, values: array<string, string>}> $slugRows */
/** @var string $defaultLang */

/** Compact per-language value summary; empty non-default = muted "fehlt" marker. */
$summary = function (array $values, array $languages, string $defaultLang, string $missingLabel): string {
    $parts = [];
    foreach ($languages as $lang) {
        $value = (string)($values[$lang] ?? '');
        $shown = $value === ''
            ? '<span style="color:var(--be-muted,#94a3b8)">' . e($missingLabel) . '</span>'
            : e($value);
        $parts[] = '<strong style="font-weight:600">' . e($lang) . ':</strong> ' . $shown;
    }
    return implode(' &nbsp;·&nbsp; ', $parts);
};
?>
<?php /* Header band: both add actions (Text / Slug) live in the shell header slot `list.hc2`
         (translation has two co-equal sections → no single dark hc1 primary). The section heads
         below carry only their titles. */ ?>
<div class="be-list">
    <!-- ── UI strings ─────────────────────────────────────────────────────── -->
    <div class="be-list__section">
        <div class="be-list__section__head" style="margin-bottom:.5rem">
            <h2 style="font-size:.95rem;margin:0">UI-Texte</h2>
        </div>
        <div class="be-tree be-tree--hub">
            <?php if (empty($uiRows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine UI-Texte vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($uiRows as $row): ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/translation/actions?kind=ui&key=<?= e(rawurlencode($row['key'])) ?>">⋮</button>
                    <span class="be-tree__name"><code><?= e($row['key']) ?></code></span>
                    <span class="be-tree__url"><?= raw($summary($row['values'], $uiLanguages, $defaultLang, 'fehlt')) ?></span>
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
        <div class="be-tree be-tree--hub">
            <?php if (empty($slugLanguages)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Nur die Standardsprache ist konfiguriert — keine Slug-Übersetzungen nötig.</p>
            <?php elseif (empty($slugRows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Routen-Slugs vorhanden.</p>
            <?php endif; ?>
            <?php foreach ($slugRows as $row): ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/content/translation/actions?kind=slug&key=<?= e(rawurlencode($row['canonical'])) ?>">⋮</button>
                    <span class="be-tree__name"><code><?= e($row['canonical']) ?></code></span>
                    <span class="be-tree__url"><?= raw($summary($row['values'], $slugLanguages, $defaultLang, 'nicht lokalisiert')) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
