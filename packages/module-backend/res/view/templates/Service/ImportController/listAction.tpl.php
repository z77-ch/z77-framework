<?php
/**
 * Data import (ADR-032) — pick a source, review the plan, decide, apply.
 *
 * Pure renderer: every row is a controller-built array (no service calls in
 * the template). The plan is grouped by outcome, important first: unclear
 * (needs a human), changed (per-record opt-in, default No), new (bulk-
 * acceptable), blocked, invalid, skipped.
 *
 * @var array|null $planView   {sourceLabel, createdAt, summary, groups, acceptedCount}
 * @var array|null $state      raw plan-store state (null = no current plan)
 * @var string|null $staleError source/target moved — plan must be discarded
 * @var array|null $lastResult {at, via, applied, failed, lines}
 * @var list<string> $vendorClasses entity short names the vendor defaults cover
 * @var list<array{name:string,size:int,mtime:int}> $inbox
 * @var array<class-string, string> $entityOptions
 * @var int $jobThreshold
 */

$muted = 'color:var(--be-muted,#94a3b8)';

$fmt = static function (?string $iso): string {
    if ($iso === null || $iso === '') {
        return '—';
    }
    $ts = strtotime($iso);

    return $ts === false ? $iso : date('d.m.Y H:i', $ts);
};
?>
<div class="be-list">

    <?php if ($staleError !== null): ?>
    <div class="be-modal__alert be-modal__alert--error" style="margin-bottom:1.25rem">
        <strong>Der Plan ist nicht mehr gültig.</strong> <?= e($staleError) ?>
        <form data-fetch-post="/backend/service/import/discard" style="margin-top:.5rem">
            <button type="submit" class="be-btn">Plan verwerfen</button>
        </form>
    </div>
    <?php endif; ?>

    <?php if ($lastResult !== null): ?>
    <div class="be-list__section" style="margin-bottom:1.5rem">
        <div class="be-list__section__head" style="margin-bottom:.5rem">
            <h2 style="font-size:.95rem;margin:0">Letzte Übernahme</h2>
            <p style="font-size:.75rem;<?= $muted ?>;margin:.15rem 0 0">
                <?= e($fmt($lastResult['at'])) ?> ·
                <?= (int) $lastResult['applied'] ?> übernommen,
                <?= (int) $lastResult['failed'] ?> fehlgeschlagen
                (<?= $lastResult['via'] === 'job' ? 'als Job' : 'direkt' ?>)
            </p>
        </div>
        <?php if ((int) $lastResult['failed'] > 0): ?>
        <div class="be-tree be-tree--hub">
            <?php foreach ($lastResult['lines'] as $line): if ($line['status'] !== 'failed') continue; ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name"><?= e($line['entry']) ?></span>
                    <span class="be-tree__url"><?= e($line['message']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($planView === null && $staleError === null): ?>
    <div class="be-list__section">
        <div class="be-list__section__head" style="margin-bottom:.5rem">
            <h2 style="font-size:.95rem;margin:0">Quelle wählen</h2>
            <p style="font-size:.75rem;<?= $muted ?>;margin:.15rem 0 0">
                Der Import ÜBERNIMMT Datensätze — er ersetzt nie, löscht nie, und schreibt
                erst nach deiner Bestätigung pro Eintrag.
            </p>
        </div>

        <div class="be-tree be-tree--hub">
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name">Framework-Standarddaten</span>
                    <span class="be-tree__url" style="font-family:inherit">
                        <?= e(implode(', ', $vendorClasses)) ?> — was das installierte Framework
                        mitliefert, verglichen mit deiner Installation
                    </span>
                    <?php // grid-column:6 — the hub row is an explicit grid; without it the
                          // form auto-places into the 2.4rem icon column. ?>
                    <form data-fetch-post="/backend/service/import/start-vendor" style="margin:0;grid-column:6">
                        <button type="submit" class="be-btn be-btn--primary">Plan berechnen</button>
                    </form>
                </div>
            </div>

            <?php foreach ($inbox as $file): ?>
            <div class="be-tree__node" style="--node-depth:0">
                <div class="be-tree__row">
                    <span class="be-tree__toggle" aria-hidden="true"></span>
                    <span class="be-tree__name"><?= e($file['name']) ?></span>
                    <span class="be-tree__url" style="font-family:inherit">
                        Inbox · <?= e(number_format($file['size'] / 1024, 1, '.', "'")) ?> KB ·
                        <?= e(date('d.m.Y H:i', $file['mtime'])) ?>
                    </span>
                    <form data-fetch-post="/backend/service/import/start-inbox" style="margin:0;grid-column:6;display:flex;gap:.35rem">
                        <input type="hidden" name="file" value="<?= e($file['name']) ?>">
                        <select name="entity" class="be-form__field" style="margin:0">
                            <?php foreach ($entityOptions as $class => $label): ?>
                            <option value="<?= e($class) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="be-btn">Plan berechnen</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <p style="font-size:.75rem;<?= $muted ?>;margin:.75rem 0 0">
            Eigene Datei importieren: als JSON per FTP/Explorer nach
            <code>data/framework/import/inbox/</code> legen — sie erscheint dann hier.
        </p>
    </div>
    <?php endif; ?>

    <?php if ($planView !== null): ?>
    <div class="be-list__section">
        <div class="be-list__section__head" style="margin-bottom:.75rem">
            <h2 style="font-size:.95rem;margin:0">Plan: <?= e($planView['sourceLabel']) ?></h2>
            <p style="font-size:.75rem;<?= $muted ?>;margin:.15rem 0 0">
                berechnet <?= e($fmt($planView['createdAt'])) ?> ·
                <?php foreach ($planView['summary'] as $outcome => $count): ?>
                <?= e($outcome) ?>: <?= (int) $count ?>&nbsp;&nbsp;
                <?php endforeach; ?>
            </p>
        </div>

        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem">
            <form data-fetch-post="/backend/service/import/apply" style="margin:0">
                <button type="submit" class="be-btn be-btn--primary"
                        <?= $planView['acceptedCount'] === 0 ? 'disabled' : '' ?>>
                    <?= (int) $planView['acceptedCount'] ?> markierte übernehmen<?=
                        $planView['acceptedCount'] > $jobThreshold ? ' (als Job)' : '' ?>
                </button>
            </form>
            <form data-fetch-post="/backend/service/import/discard" style="margin:0">
                <button type="submit" class="be-btn be-btn--ghost">Plan verwerfen</button>
            </form>
        </div>

        <?php foreach ($planView['groups'] as $group): ?>
        <div class="be-list__section" style="margin-top:1.5rem">
            <div class="be-list__section__head" style="margin-bottom:.5rem">
                <h3 style="font-size:.85rem;margin:0 0 .2rem"><?= e($group['label']) ?>
                    <small style="<?= $muted ?>">(<?= count($group['rows']) ?>)</small></h3>
                <p style="font-size:.75rem;<?= $muted ?>;margin:0;max-width:70ch"><?= e($group['hint']) ?></p>
                <?php if ($group['bulkable']): ?>
                <form data-fetch-post="/backend/service/import/bulk" style="margin:.5rem 0 0">
                    <input type="hidden" name="group" value="<?= e($group['key']) ?>">
                    <input type="hidden" name="decision" value="accept">
                    <button type="submit" class="be-btn">Alle <?= count($group['rows']) ?> markieren</button>
                </form>
                <?php endif; ?>
            </div>

            <?php if ($group['key'] === 'skipped'): ?>
            <?php else: ?>
            <div class="be-tree be-tree--hub">
                <?php foreach ($group['rows'] as $row): ?>
                <div class="be-tree__node" style="--node-depth:0">
                    <div class="be-tree__row" title="<?= e($row['reasonRaw']) ?>">
                        <span class="be-tree__toggle" aria-hidden="true"></span>
                        <span class="be-tree__name">
                            <?= e($row['label']) ?>
                            <?php if ($row['decision'] === 'accept'): ?>
                            <span style="font-size:.7rem;color:var(--be-accent,#38bdf8)">✓ markiert</span>
                            <?php elseif ($row['decision'] === 'reject'): ?>
                            <span style="font-size:.7rem;<?= $muted ?>">abgelehnt</span>
                            <?php endif; ?>
                        </span>
                        <span class="be-tree__url" style="font-family:inherit"><?= e($row['consequence']) ?></span>
                        <span class="be-tree__route"><?= e($row['entity']) ?></span>
                    </div>

                    <?php // Detail + action rows are NOT `be-tree__row`: the hub row is an
                          // explicit 6-column grid (1rem/2.4rem/1.6rem/…), so free-form
                          // content would be squeezed into the icon columns and overlap. ?>
                    <?php if ($row['diff'] !== []): ?>
                    <div style="padding:0 0 .25rem 2.1rem">
                        <table style="font-size:.72rem;border-collapse:collapse">
                            <tr style="<?= $muted ?>">
                                <th style="text-align:left;font-weight:normal;padding:0 .75rem .15rem 0">Feld</th>
                                <th style="text-align:left;font-weight:normal;padding:0 .75rem .15rem 0">bei dir</th>
                                <th style="text-align:left;font-weight:normal;padding:0 0 .15rem 0">wird zu</th>
                            </tr>
                            <?php foreach ($row['diff'] as $d): ?>
                            <tr>
                                <td style="padding:.1rem .75rem .1rem 0"><?= e($d['field']) ?></td>
                                <td style="padding:.1rem .75rem .1rem 0;<?= $muted ?>"><?= e($d['target']) ?></td>
                                <td style="padding:.1rem 0"><?= e($d['source']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                    <?php endif; ?>

                    <div style="padding:0 0 .6rem 2.1rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                        <?php if ($row['outcome'] === 'unclear'): ?>
                            <?php if ($row['suggestionId'] !== null): ?>
                            <form data-fetch-post="/backend/service/import/decide" style="margin:0">
                                <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                                <input type="hidden" name="decision" value="accept">
                                <input type="hidden" name="target_id" value="<?= e((string) $row['suggestionId']) ?>">
                                <button type="submit" class="be-btn be-btn--primary">
                                    Ist mein <?= e($row['suggestion']) ?>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($row['targets'] !== []): ?>
                            <form data-fetch-post="/backend/service/import/decide" style="margin:0;display:flex;gap:.35rem;align-items:center">
                                <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                                <input type="hidden" name="decision" value="accept">
                                <span style="font-size:.72rem;<?= $muted ?>">oder:</span>
                                <select name="target_id" class="be-form__field" style="margin:0">
                                    <?php foreach ($row['targets'] as $target): ?>
                                    <option value="<?= e((string) $target['id']) ?>"><?= e($target['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="be-btn">Zuordnen</button>
                            </form>
                            <?php endif; ?>
                            <form data-fetch-post="/backend/service/import/decide" style="margin:0">
                                <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                                <input type="hidden" name="decision" value="accept">
                                <input type="hidden" name="force_new" value="1">
                                <button type="submit" class="be-btn">Habe ich nicht — neu anlegen</button>
                            </form>
                        <?php elseif (in_array($row['group'], ['new', 'changed-content', 'changed-key'], true)): ?>
                            <?php if ($row['decision'] !== 'accept'): ?>
                            <form data-fetch-post="/backend/service/import/decide" style="margin:0">
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
                            <form data-fetch-post="/backend/service/import/decide" style="margin:0">
                                <input type="hidden" name="key" value="<?= e($row['key']) ?>">
                                <input type="hidden" name="decision" value="reject">
                                <button type="submit" class="be-btn be-btn--ghost">Ablehnen</button>
                            </form>
                            <?php endif; ?>
                            <?php if ($row['decision'] !== 'pending'): ?>
                            <form data-fetch-post="/backend/service/import/decide" style="margin:0">
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
    <?php endif; ?>
</div>
