<?php
/**
 * The plan section of the import screen — header, groups, per-row decide forms. Rendered by the
 * list and by ImportController's decide / bulk answer (`replace-html` of `[data-import-plan]`,
 * together with the toolbar `list.hc2`), ADR-047 addendum 2026-10-10: a decision replans, so the
 * section is the unit that changes — not the row (a record decided «neu anlegen» changes its
 * group). core.js wires the decide forms it brings (FETCH-ROW-001).
 *
 * @var array $planView {sourceLabel, createdAt, summary, groups, acceptedCount}
 */

$fmt = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);

    return $ts === false ? $iso : date('d.m.Y H:i', $ts);
};
?>
<div class="be-list__section" data-import-plan>
    <div class="be-list__section-header">
        <h2 class="be-list__section-title">Plan: <?= e($planView['sourceLabel']) ?></h2>
    </div>
    <p class="be-list__section-hint">
        berechnet <?= e($fmt($planView['createdAt'])) ?> ·
        <?php foreach ($planView['summary'] as $outcome => $count): ?>
        <?= e($outcome) ?>: <?= (int) $count ?>&nbsp;&nbsp;
        <?php endforeach; ?>
    </p>

<?php /* The two GLOBAL plan actions (übernehmen / verwerfen) moved to the shell header band:
     `list.hc2.tpl.php` (auto-loaded). Per-record decisions stay below — those are per
     row, not per screen. */ ?>

    <?php foreach ($planView['groups'] as $group): ?>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h3 class="be-list__section-title"><?= e($group['label']) ?></h3>
            <span class="be-list__section-badge"><?= count($group['rows']) ?></span>
        </div>
        <p class="be-list__section-hint"><?= e($group['hint']) ?></p>

        <?php if ($group['bulkable']): ?>
        <?php // A group-wide action is selection-bound: it stands in a bar ABOVE the group's rows
              // (backend-screen.md §4, ADR-033 exception 2), green, never under the heading text. ?>
        <form data-fetch-post="/backend/service/import/bulk" class="z77-form-actions">
            <input type="hidden" name="group" value="<?= e($group['key']) ?>">
            <input type="hidden" name="decision" value="accept">
            <button type="submit" class="be-btn be-btn--confirm be-btn--sm">Alle <?= count($group['rows']) ?> markieren</button>
            <small class="be-list__cell--muted">geschrieben wird erst mit «Übernehmen» oben</small>
        </form>
        <?php endif; ?>

        <?php if ($group['key'] === 'skipped'): ?>
        <?php else: ?>
        <div class="be-tree be-tree--hub be-tree--lead-none">
            <?php foreach ($group['rows'] as $row): ?>
            <div class="be-tree__node">
                <div class="be-tree__row" title="<?= e($row['reasonRaw']) ?>">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name">
                        <?= e($row['label']) ?>
                        <?php if ($row['decision'] === 'accept'): ?>
                        <span class="be-tree__meta be-tree__meta--accent">✓ markiert</span>
                        <?php elseif ($row['decision'] === 'reject'): ?>
                        <span class="be-tree__meta">abgelehnt</span>
                        <?php endif; ?>
                    </span>
                    <span class="be-tree__url be-tree__url--text"><?= e($row['consequence']) ?></span>
                    <span class="be-tree__route"><?= e($row['entity']) ?></span>
                </div>

                <?php // Detail + action rows are NOT `be-tree__row`: the hub row is an
                      // explicit 6-column grid (1rem/2.4rem/1.6rem/…), so free-form
                      // content would be squeezed into the icon columns and overlap. ?>
                <?php if ($row['diff'] !== []): ?>
                <div class="be-tree__detail">
                    <table class="be-diff">
                        <tr>
                            <th class="be-diff__head">Feld</th>
                            <th class="be-diff__head">bei dir</th>
                            <th class="be-diff__head">wird zu</th>
                        </tr>
                        <?php foreach ($row['diff'] as $d): ?>
                        <tr>
                            <td class="be-diff__cell"><?= e($d['field']) ?></td>
                            <td class="be-diff__cell be-diff__cell--old"><?= e($d['target']) ?></td>
                            <td class="be-diff__cell"><?= e($d['source']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <?php endif; ?>

                <div class="be-tree__detail be-tree__detail--actions">
                    <?php if ($row['outcome'] === 'unclear'): ?>
                        <?php if ($row['suggestionId'] !== null): ?>
                        <form data-fetch-post="/backend/service/import/decide">
                            <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                            <input type="hidden" name="decision" value="accept">
                            <input type="hidden" name="target_id" value="<?= e((string) $row['suggestionId']) ?>">
                            <button type="submit" class="be-btn be-btn--primary">
                                Ist mein <?= e($row['suggestion']) ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($row['targets'] !== []): ?>
                        <form class="be-tree__detail-form" data-fetch-post="/backend/service/import/decide">
                            <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                            <input type="hidden" name="decision" value="accept">
                            <span class="be-tree__meta">oder:</span>
                            <select name="target_id" class="be-input be-input--sm" aria-label="Zuordnen zu">
                                <?php foreach ($row['targets'] as $target): ?>
                                <option value="<?= e((string) $target['id']) ?>"><?= e($target['label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="be-btn">Zuordnen</button>
                        </form>
                        <?php endif; ?>
                        <form data-fetch-post="/backend/service/import/decide">
                            <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                            <input type="hidden" name="decision" value="accept">
                            <input type="hidden" name="force_new" value="1">
                            <button type="submit" class="be-btn">Habe ich nicht — neu anlegen</button>
                        </form>
                    <?php elseif (in_array($row['group'], ['new', 'changed-content', 'changed-key'], true)): ?>
                        <?php if ($row['decision'] !== 'accept'): ?>
                        <form data-fetch-post="/backend/service/import/decide">
                            <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                            <input type="hidden" name="decision" value="accept">
                            <?php if ($row['targetId'] !== null): ?>
                            <input type="hidden" name="target_id" value="<?= e((string) $row['targetId']) ?>">
                            <?php endif; ?>
                            <button type="submit" class="be-btn be-btn--primary">
                                <?= match ($row['group']) {
                                    'new'         => 'Anlegen',
                                    'changed-key' => 'Kennung setzen',
                                    default       => 'Änderung übernehmen',
                                } ?>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php if ($row['decision'] !== 'reject'): ?>
                        <form data-fetch-post="/backend/service/import/decide">
                            <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                            <input type="hidden" name="decision" value="reject">
                            <button type="submit" class="be-btn be-btn--ghost">Ablehnen</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($row['decision'] !== 'pending'): ?>
                        <form data-fetch-post="/backend/service/import/decide">
                            <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                            <input type="hidden" name="decision" value="pending">
                            <button type="submit" class="be-btn be-btn--ghost">Zurücksetzen</button>
                        </form>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
