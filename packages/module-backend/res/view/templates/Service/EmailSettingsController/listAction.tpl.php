<?php
/**
 * Form-mail settings list — one row per form key (union of emailConfig `forms`
 * and backend EmailFormSetting records). Layout mirrors the navigation list:
 * a `be-tree be-tree--hub` with an inline active switch (only where an override
 * record exists) + the ⋮ action hub (edit / reset). Shows the EFFECTIVE values +
 * origin: «Backend» (active override), «Backend (inaktiv)» (dormant → config
 * applies), «Config» (no override).
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
        <div class="be-tree be-tree--hub">
            <?php if (empty($rows)): ?>
            <p style="font-size:.8rem;color:var(--be-muted,#94a3b8);padding:.5rem">Keine Formular-Mails definiert (emailConfig `forms` ist leer).</p>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
            <div class="be-tree__node<?= $row['hasEntity'] && !$row['active'] ? ' be-tree__node--inactive' : '' ?>"
                 style="--node-depth:0" data-form-key="<?= e($row['key']) ?>">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>

                    <?php if ($row['hasEntity']): ?>
                    <label class="be-switch be-switch--sm be-tree__switch" title="Übersteuerung aktiv">
                        <input type="checkbox" class="be-switch__input"
                               data-fetch-toggle="/backend/service/email-settings/toggle-active?key=<?= e(rawurlencode($row['key'])) ?>"<?= $row['active'] ? ' checked' : '' ?>>
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </label>
                    <?php else: ?>
                    <?php /* Keeps the ⋮ column aligned with switched rows. */ ?>
                    <span class="be-switch be-switch--sm be-tree__switch" style="visibility:hidden" aria-hidden="true">
                        <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
                    </span>
                    <?php endif; ?>

                    <button type="button" class="be-tree__menu" title="Aktionen"
                            data-fetch-get="/backend/service/email-settings/actions?key=<?= e(rawurlencode($row['key'])) ?>">⋮</button>

                    <span class="be-tree__name" data-field="name"><code><?= e($row['key']) ?></code>
                        <?php if (!$row['hasConfig']): ?>
                        <span style="font-size:.7rem;color:var(--be-muted,#94a3b8)">&nbsp;(nicht mehr in der Config)</span>
                        <?php endif; ?>
                    </span>
                    <span class="be-tree__url" data-field="recipients">
                        <?= e(implode(', ', $row['to'])) ?>
                        <?php if ($row['subject'] !== ''): ?>
                        &nbsp;·&nbsp; «<?= e($row['subject']) ?>»
                        <?php endif; ?>
                        <?php if ($row['routes'] > 0): ?>
                        &nbsp;·&nbsp; <?= e((string) $row['routes']) ?> Route<?= $row['routes'] > 1 ? 'n' : '' ?>
                        <?php endif; ?>
                    </span>
                    <span class="be-tree__route" data-field="origin"><?= e($row['origin']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
