<?php
/**
 * One form-mail row of the list. Rendered by the list and by EmailSettingsController as the
 * in-place answer of a save, a reset and the active toggle (`replaceRow('email-setting', key)`,
 * ADR-047 addendum 2026-10-10), so both show the same row; core.js wires the replaced row's
 * switch, ⋮ and «Übersteuern» (FETCH-ROW-001).
 *
 * @var array{key: string, to: list<string>, subject: string, routes: int,
 *            origin: string, hasEntity: bool, hasConfig: bool, active: bool} $row
 */
?>
    <div class="be-tree__node<?= $row['hasEntity'] && !$row['active'] ? ' be-tree__node--inactive' : '' ?>"
         style="--node-depth:0" data-form-key="<?= e($row['key']) ?>"
         data-entity="email-setting:<?= e($row['key']) ?>">
        <div class="be-tree__row">
            <span class="be-tree__toggle" aria-hidden="true"></span>

            <?php if ($row['hasEntity']): ?>
            <label class="be-switch be-switch--sm be-tree__switch"
                   title="Übersteuerung aktiv — aus: Config-Werte gelten">
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
            <span class="be-tree__route" data-field="origin" style="display:inline-flex;gap:.5rem;align-items:center">
                <?php if ($row['hasEntity'] && $row['active']): ?>
                <span class="badge badge--success" title="Backend-Werte gelten">Backend</span>
                <?php elseif ($row['hasEntity']): ?>
                <span class="badge badge--warning" title="Übersteuerung schlummert — Config-Werte gelten">Backend inaktiv</span>
                <?php else: ?>
                <span class="badge badge--muted" title="Entwickler-Vorgabe — noch keine Übersteuerung erfasst">Config</span>
                <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                        title="Empfänger, CC und Betreff im Backend übersteuern"
                        data-fetch-get="/backend/service/email-settings/edit?key=<?= e(rawurlencode($row['key'])) ?>">Übersteuern</button>
                <?php endif; ?>
            </span>
        </div>
    </div>
