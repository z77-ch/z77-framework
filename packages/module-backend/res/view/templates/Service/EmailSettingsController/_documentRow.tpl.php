<?php
/**
 * One document-mail row («Beleg-Mails», step 3 of the mail plan, owner 2026-10-22) of the
 * Service › E-Mail list. Rendered by the list and by EmailSettingsController as the in-place
 * answer of a save, a reset and the switch (`replaceRow('email-document', kind)`), so both
 * show the same row — the twin of `_row.tpl.php` for the form mails. Origin «Modul» = the
 * module's declaration applies (the seed), «Backend» = the operator's text, «Backend inaktiv»
 * = kept but dormant.
 *
 * @var array{kind: string, label: string, subject: string, languages: list<string>,
 *            origin: string, hasEntity: bool, declared: bool, active: bool} $row
 */
$k = rawurlencode($row['kind']);
?>
    <div class="be-tree__node<?= $row['hasEntity'] && !$row['active'] ? ' be-tree__node--inactive' : '' ?>"
         style="--node-depth:0" data-document-kind="<?= e($row['kind']) ?>"
         data-entity="email-document:<?= e($row['kind']) ?>">
        <div class="be-tree__row">
            <span class="be-tree__toggle" aria-hidden="true"></span>

            <?php if ($row['hasEntity']): ?>
            <label class="be-switch be-switch--sm be-tree__switch"
                   title="Eigener Text aktiv — aus: der Text des Moduls gilt">
                <input type="checkbox" class="be-switch__input"
                       data-fetch-toggle="/backend/service/email-settings/document-toggle-active?kind=<?= e($k) ?>"<?= $row['active'] ? ' checked' : '' ?>>
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
            </label>
            <?php else: ?>
            <span class="be-switch be-switch--sm be-tree__switch" style="visibility:hidden" aria-hidden="true">
                <span class="be-switch__track"><span class="be-switch__thumb"></span></span>
            </span>
            <?php endif; ?>

            <button type="button" class="be-tree__menu" title="Aktionen" aria-haspopup="menu" aria-expanded="false"
                    data-menu-open="/backend/service/email-settings/document-actions?kind=<?= e($k) ?>">⋮</button>

            <span class="be-tree__name" data-field="name"><?= e($row['label']) ?> <code class="be-tree__key"><?= e($row['kind']) ?></code>
                <?php if (!$row['declared']): ?>
                <span style="font-size:.7rem;color:var(--be-muted,#94a3b8)">&nbsp;(kein Modul sendet diese Art mehr)</span>
                <?php endif; ?>
            </span>
            <span class="be-tree__url" data-field="subject">
                <?= $row['subject'] !== '' ? '«' . e($row['subject']) . '»' : '' ?>
                <?php if ($row['languages'] !== []): ?>
                &nbsp;·&nbsp; <?= e(implode(' / ', $row['languages'])) ?>
                <?php endif; ?>
            </span>
            <span class="be-tree__route" data-field="origin" style="display:inline-flex;gap:.5rem;align-items:center">
                <?php if ($row['hasEntity'] && $row['active']): ?>
                <span class="badge badge--success" title="Der hier erfasste Text gilt">Backend</span>
                <?php elseif ($row['hasEntity']): ?>
                <span class="badge badge--warning" title="Eigener Text schlummert — der Text des Moduls gilt">Backend inaktiv</span>
                <?php else: ?>
                <span class="badge badge--muted" title="Vorgabe des Moduls — noch kein eigener Text erfasst">Modul</span>
                <?php if ($row['declared']): ?>
                <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                        title="Betreff und Text anpassen"
                        data-fetch-get="/backend/service/email-settings/document-edit?kind=<?= e($k) ?>">Anpassen</button>
                <?php endif; ?>
                <?php endif; ?>
            </span>
        </div>
    </div>
