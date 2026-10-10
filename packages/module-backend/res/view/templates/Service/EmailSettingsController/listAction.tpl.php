<?php
/**
 * Form-mail settings list — one row per form key (union of emailConfig `forms`
 * and backend EmailFormSetting records). Layout mirrors the navigation list:
 * a `be-tree be-tree--hub` with an inline active switch (only where an override
 * record exists) + the ⋮ action hub (edit / reset).
 *
 * The two-layer model must be readable WITHOUT the intro text (owner call,
 * 2026-08-06): origin renders as a colored badge (Config = muted seed,
 * Backend = green active override, Backend inaktiv = amber dormant), and a
 * config-only row carries an explicit «Übersteuern» button right where its
 * state is shown — one click into the prefilled edit, which creates the
 * record and thereby the switch.
 *
 * One row = `_row.tpl.php` — a save, a reset and the toggle answer with the same partial
 * (`replaceRow`).
 *
 * @var list<array{key: string, to: list<string>, subject: string, routes: int,
 *                 origin: string, hasEntity: bool, hasConfig: bool, active: bool}> $rows
 */
?>
<div class="be-list">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Formular-Mails</h2>
            <span class="be-list__section-badge"><?= count($rows) ?></span>
        </div>
        <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:0 .5rem .5rem">
            Empfänger, CC und Betreff pro Formular — hier gepflegte Werte übersteuern die
            Entwickler-Vorgabe (Config), solange die Übersteuerung aktiv ist. Templates und
            neue Formular-Keys bleiben Code.
        </p>
        <div class="be-tree be-tree--hub be-tree--lead-switch">
            <?php if (empty($rows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Formular-Mails definiert (emailConfig `forms` ist leer).</p>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <?= $this->partial('Service/EmailSettingsController/_row', ['row' => $row], 'Z77\\Module\\Backend') ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
