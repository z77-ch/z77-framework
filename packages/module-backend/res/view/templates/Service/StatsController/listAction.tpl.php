<?php
/**
 * «Statistik» — the monthly report in the backend (StatsController::listAction,
 * docs/topics/stats.md). Above the report: the month switch, the link of the month
 * (the one the client would get, minted fresh, valid ten days) and where the monthly mail
 * stands; «Bericht … senden» is the toolbar (`list.hc2.tpl.php`). The report below is the same template the link
 * page renders (`_report`).
 *
 * @var string       $title
 * @var list<string> $months     months with an aggregate, newest first
 * @var ?string      $month      the month on screen
 * @var ?array       $report     StatsReport::build(), null without data
 * @var ?string      $error      why the report could not be built
 * @var ?array       $link       ['url' => …, 'expiresAt' => …] or null
 * @var ?string      $linkError  why there is no link
 * @var ?\Z77\Shared\Entities\JobSchedule $schedule the mail job's schedule, if any
 * @var string       $mailJob    job key of the monthly mail
 * @var string       $formKey    mail settings key (Service → E-Mail)
 * @var ?array       $recipients ['to' => list, 'cc' => list, 'error'?] as a send resolves them
 */
use Z77\Shared\Stats\StatsReport;

$muted = 'font-size:.8rem;color:var(--be-muted,#94a3b8)';
?>
<div class="be-list">

    <section class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($title) ?></h2>
        </div>

        <?php if ($months === []): ?>
            <p style="<?= $muted ?>;padding:.25rem .5rem">
                Noch keine Monatszahlen. Sie entstehen, wenn der Job <code>stats-rollup</code>
                (täglich 04:40) den ersten abgeschlossenen Tag verdichtet hat.
            </p>
        <?php else: ?>
            <div style="display:flex;gap:.4rem;flex-wrap:wrap;align-items:center;padding:.25rem .5rem .5rem">
                <?php foreach ($months as $m): ?>
                    <a class="be-btn be-btn--sm<?= $m === $month ? ' be-btn--primary' : '' ?>"
                       href="/backend/service/stats/list?month=<?= e($m) ?>"><?= e(StatsReport::monthLabel($m)) ?></a>
                <?php endforeach; ?>
            </div>

            <div style="padding:.25rem .5rem .75rem;display:grid;gap:.6rem">
                <div>
                    <strong>Link für die Kundschaft</strong>
                    <?php if ($link !== null): ?>
                        <div style="display:flex;gap:.4rem;align-items:center;flex-wrap:wrap;margin-top:.25rem">
                            <input type="text" readonly value="<?= e($link['url']) ?>" style="flex:1;min-width:18rem;font-family:monospace;font-size:.8rem" onclick="this.select()">
                            <a class="be-btn be-btn--sm" href="<?= e($link['url']) ?>" target="_blank" rel="noopener">Öffnen</a>
                        </div>
                        <div style="<?= $muted ?>">
                            Öffnet <?= e(StatsReport::monthLabel((string) $month)) ?> ohne Anmeldung, gültig bis
                            <?= e(date('j.n.Y', (int) $link['expiresAt'])) ?>. Jeder Aufruf dieser Seite erzeugt einen neuen Link;
                            alle bleiben bis zu ihrem Datum gültig.
                        </div>
                    <?php else: ?>
                        <div style="<?= $muted ?>"><?= e((string) $linkError) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <strong>Monatsmail</strong>
                    <div style="<?= $muted ?>">
                        <?php if (!empty($recipients['error'])): ?>
                            ⚠️ Empfänger nicht lesbar: <?= e((string) $recipients['error']) ?><br>
                        <?php elseif (($recipients['to'] ?? []) === []): ?>
                            ⚠️ Kein Empfänger eingetragen — eine Mail würde nicht versendet.<br>
                        <?php else: ?>
                            Geht an <strong><?= e(implode(', ', $recipients['to'])) ?></strong><?php if ($recipients['cc'] !== []): ?>, Kopie <?= e(implode(', ', $recipients['cc'])) ?><?php endif; ?>
                            (E-Mail-Schlüssel <code><?= e($formKey) ?></code>, ändern unter <a href="/backend/service/email-settings/list">E-Mail</a>).<br>
                        <?php endif; ?>
                        Automatisch: Job <code><?= e($mailJob) ?></code>
                        <?php if ($schedule === null): ?>
                            ohne Zeitplan — unter <a href="/backend/service/job/list">Jobs</a> setzen, empfohlen <code>monthly@1,06:00</code> (nach der Verdichtung um 04:40).
                        <?php else: ?>
                            <code><?= e($schedule->getExpression()) ?></code>, <?= $schedule->isEnabled() ? 'eingeschaltet' : 'ausgeschaltet' ?>
                            (<a href="/backend/service/job/list">Jobs</a>) — sendet den Vormonat.
                        <?php endif; ?>
                        Jede Mail trägt einen eigenen, frischen Link.
                    </div>
                    <?php /* «Bericht … senden» stands in the toolbar (`list.hc2.tpl.php`) — a screen
                             action in the fixed row, not in the page body (ADR-049 rev. 2026-10-10). */ ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($error !== null): ?>
        <p style="color:#c00;padding:.5rem">⚠️ <?= e($error) ?></p>
    <?php elseif ($report !== null): ?>
        <div style="background:#ffffff;border-radius:8px;margin:.5rem 0 1.5rem">
            <?= $this->partial('Service/StatsController/_report', ['report' => $report], 'Z77\\Module\\Backend') ?>
        </div>
    <?php endif; ?>

</div>
