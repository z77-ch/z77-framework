<?php
/**
 * The statistics report itself — one template for both doors (the backend page and
 * the link page, StatsController), built from StatsReport::build(). The layout
 * follows the mock the client approved on 2026-09-23 (zihlundsee
 * work/sandbox/stats-mock/bericht.html), with the labels the data allows:
 *
 * - «Besucher (Tagessumme)», never «Besucher» (STATS-002 — the footer says why);
 * - sources, devices, languages and countries count PAGE VIEWS, because that is
 *   what the aggregate tallies — the column says «Aufrufe», not «Besuche»;
 * - no comparison with a month that was not counted whole.
 *
 * Styles are scoped under `.z77-report` and carried here, not in base.css: the link
 * page has no backend stylesheet, and the report must look the same in both. It is
 * «paper» — white in the backend's dark mode too, and it prints as it stands.
 *
 * @var array   $report    StatsReport::build()
 * @var ?string $validUntil a sentence about the link's validity (link page only)
 */
use Z77\Shared\Stats\StatsReport;

$num  = static fn (int|float $n, int $d = 0): string => StatsReport::formatNumber($n, $d);
$date = static fn (?string $ymd): string => $ymd === null ? '' : date('j.n.Y', (int) strtotime($ymd));
$validUntil ??= null;

$days      = $report['days'];
$daysCount = count($days);
?>
<style>
    .z77-report { --r-ink:#1a1a1a; --r-muted:#6b6b6b; --r-line:#e2e2e2; --r-panel:#f6f7f8; --r-accent:#28aa6a; --r-soft:#d8ffff;
                  background:#ffffff; color:var(--r-ink); font:15px/1.5 ui-sans-serif, system-ui, "Segoe UI", Roboto, Arial, sans-serif;
                  max-width:900px; margin:0 auto; padding:28px 20px 48px; box-sizing:border-box; }
    .z77-report * { box-sizing:border-box; }
    .z77-report__head { border-bottom:2px solid var(--r-ink); padding-bottom:10px; }
    .z77-report__head h1 { font-size:22px; margin:0 0 2px; color:var(--r-ink); }
    .z77-report__head p { margin:0; color:var(--r-muted); font-size:14px; }
    .z77-report__meta { display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px; font-size:13px; color:var(--r-muted); margin:10px 0 24px; }
    .z77-report h2 { font-size:14px; text-transform:uppercase; letter-spacing:.06em; margin:30px 0 10px; padding-bottom:6px; border-bottom:1px solid var(--r-line); color:var(--r-ink); }
    .z77-report__kpis { display:grid; grid-template-columns:repeat(4, 1fr); gap:12px; }
    .z77-report__kpi { background:var(--r-panel); border-radius:8px; padding:14px 16px; }
    .z77-report__kpi .l { font-size:12px; color:var(--r-muted); }
    .z77-report__kpi .v { font-size:26px; font-weight:600; line-height:1.2; }
    .z77-report__kpi .d { font-size:12px; color:var(--r-muted); }
    .z77-report__kpi .d.up { color:var(--r-accent); }
    .z77-report__kpi .d.down { color:#c0392b; }
    .z77-report table { width:100%; border-collapse:collapse; font-size:14px; }
    .z77-report th, .z77-report td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--r-line); vertical-align:baseline; color:var(--r-ink); background:none; }
    .z77-report th { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:var(--r-muted); font-weight:600; }
    .z77-report thead { display:table-header-group; }
    .z77-report .num { text-align:right; white-space:nowrap; }
    .z77-report .bar { width:35%; padding-right:0; }
    .z77-report .bar span { display:block; height:10px; border-radius:3px; background:var(--r-accent); opacity:.85; }
    .z77-report .sub { display:block; font-size:12px; color:var(--r-muted); }
    .z77-report__cols { display:grid; grid-template-columns:1fr 1fr; gap:28px; }
    .z77-report__chart { display:flex; align-items:flex-end; gap:3px; height:130px; border-bottom:1px solid var(--r-line); padding-top:8px; }
    .z77-report__chart div { flex:1; background:var(--r-soft); border:1px solid #a9d8d8; border-bottom:0; border-radius:2px 2px 0 0; min-height:1px; }
    .z77-report__chart div.none { background:none; border:0; }
    .z77-report__chart-x { display:flex; justify-content:space-between; font-size:11px; color:var(--r-muted); margin-top:4px; }
    .z77-report__note { font-size:12.5px; color:var(--r-muted); margin:8px 0 0; }
    .z77-report__empty { color:var(--r-muted); font-size:14px; margin:0; }
    .z77-report__foot { margin-top:36px; padding-top:12px; border-top:1px solid var(--r-line); font-size:12px; color:var(--r-muted); }
    .z77-report__print { margin:20px 0 0; }
    .z77-report__print button { font:inherit; padding:8px 14px; border-radius:999px; border:1px solid var(--r-ink); background:#ffffff; color:var(--r-ink); cursor:pointer; }
    @media (max-width:700px) {
        .z77-report__kpis { grid-template-columns:1fr 1fr; }
        .z77-report__cols { grid-template-columns:1fr; }
    }
    @media print {
        .z77-report { max-width:none; padding:0; font-size:11pt; }
        .z77-report__print { display:none; }
        .z77-report h2 { margin-top:16px; break-after:avoid; }
        .z77-report__kpi { background:none; border:1px solid var(--r-line); }
        .z77-report table, .z77-report__chart { break-inside:avoid; }
        @page { margin:14mm; }
    }
</style>

<article class="z77-report">

    <header class="z77-report__head">
        <h1>Besuchsstatistik — <?= e($report['monthLabel']) ?></h1>
        <?php if (!empty($siteName)): ?><p><?= e($siteName) ?></p><?php endif; ?>
    </header>
    <div class="z77-report__meta">
        <span>
            <?php if ($report['countedFrom'] === null): ?>
                Noch kein Tag verdichtet.
            <?php else: ?>
                Gezählt <?= e($date($report['countedFrom'])) ?> – <?= e($date($report['countedUntil'])) ?><?= $report['closed'] ? '' : ' (laufender Monat)' ?><?php if ($report['previous']['compared']): ?>, Vergleich mit <?= e($report['previous']['monthLabel']) ?><?php endif; ?>
            <?php endif; ?>
        </span>
        <?php if ($validUntil !== null): ?><span><?= e($validUntil) ?></span><?php endif; ?>
    </div>

    <?php if ($report['startsLate']): ?>
        <p class="z77-report__note" style="margin:-12px 0 20px">
            Die Zählung begann in diesem Monat — die Zahlen decken nicht den ganzen Monat ab.
        </p>
    <?php elseif (!$report['previous']['compared'] && $report['closed']): ?>
        <p class="z77-report__note" style="margin:-12px 0 20px">
            Kein Vergleich mit <?= e($report['previous']['monthLabel']) ?>: dieser Monat wurde nicht vollständig gezählt.
        </p>
    <?php endif; ?>

    <div class="z77-report__kpis">
        <?php foreach ($report['kpis'] as $kpi): ?>
            <div class="z77-report__kpi">
                <div class="l"><?= e($kpi['label']) ?></div>
                <div class="v"><?= e($num($kpi['value'], $kpi['decimals'])) ?></div>
                <?php if ($kpi['delta'] !== null): ?>
                    <div class="d <?= e((string) $kpi['trend']) ?>"><?= e($kpi['delta']) ?> ggü. Vormonat</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <h2>Besuche pro Tag</h2>
    <div class="z77-report__chart" role="img" aria-label="Besuche pro Tag im <?= e($report['monthLabel']) ?>">
        <?php foreach ($days as $day): ?>
            <?php if ($day['visits'] === null): ?>
                <div class="none" title="<?= e($date($day['date'])) ?>: nicht gezählt"></div>
            <?php else: ?>
                <div style="height:<?= (int) $day['percent'] ?>%" title="<?= e($date($day['date'])) ?>: <?= e($num($day['visits'])) ?> Besuche, <?= e($num((int) $day['views'])) ?> Seitenaufrufe"></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <div class="z77-report__chart-x">
        <span>1.</span><span><?= (int) ceil($daysCount / 2) ?>.</span><span><?= (int) $daysCount ?>.</span>
    </div>

    <div class="z77-report__cols">
        <div>
            <h2>Meistbesuchte Seiten</h2>
            <?php if ($report['pages'] === []): ?>
                <p class="z77-report__empty">Keine Seitenaufrufe.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Seite</th><th class="num">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['pages'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="z77-report__note">Deutsche und französische Fassung einer Seite zählen zusammen.</p>
            <?php endif; ?>
        </div>
        <div>
            <h2>Einstiegsseiten</h2>
            <?php if ($report['entries'] === []): ?>
                <p class="z77-report__empty">Keine Besuche.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Seite</th><th class="num">Besuche</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['entries'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="z77-report__note">Wo ein Besuch beginnt. Ein Besuch endet nach 30 Minuten ohne Seitenaufruf; ein Besuch über Mitternacht zählt als zwei.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($report['events'] !== []): ?>
        <h2>Was die Besucher getan haben</h2>
        <table>
            <thead><tr><th>Aktion</th><th>meist auf</th><th class="num">Anzahl</th><th class="num">je 100 Besuche</th></tr></thead>
            <tbody>
            <?php foreach ($report['events'] as $event): ?>
                <tr>
                    <td><?= e($event['label']) ?></td>
                    <td>
                        <?php foreach ($event['pages'] as $i => $page): ?>
                            <?= $i > 0 ? ' · ' : '' ?><?= e($page['label']) ?> (<?= e($num($page['count'])) ?>)
                        <?php endforeach; ?>
                    </td>
                    <td class="num"><?= e($num($event['count'])) ?></td>
                    <td class="num"><?= e($num($event['per100'], 1)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="z77-report__cols">
        <div>
            <h2>Woher die Besucher kamen</h2>
            <?php if ($report['sources'] === []): ?>
                <p class="z77-report__empty">Keine Zahlen.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Art</th><th class="num">Aufrufe</th><th class="bar"></th></tr></thead>
                    <tbody>
                    <?php foreach ($report['sources'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td><td class="bar"><span style="width:<?= (int) $row['percent'] ?>%"></span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="z77-report__note">Seitenaufrufe, die von aussen kamen — das Weiterklicken innerhalb der Website zählt hier nicht. «Direkt» heisst: Adresse eingetippt, Lesezeichen oder ein Link aus einer E-Mail.</p>
            <?php endif; ?>
        </div>
        <div>
            <h2>Verweisende Websites</h2>
            <?php if ($report['referrers'] === []): ?>
                <p class="z77-report__empty">Keine.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Website</th><th class="num">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['referrers'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="z77-report__note">Nur der Name der Website, nie die einzelne Seite dort.</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="z77-report__cols">
        <div>
            <h2>Geräte</h2>
            <?php if ($report['devices'] === []): ?>
                <p class="z77-report__empty">Keine Zahlen.</p>
            <?php else: ?>
                <table>
                    <thead><tr><th>Art</th><th class="num">Aufrufe</th><th class="bar"></th></tr></thead>
                    <tbody>
                    <?php foreach ($report['devices'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td><td class="bar"><span style="width:<?= (int) $row['percent'] ?>%"></span></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <div>
            <h2>Sprache und Land</h2>
            <?php if ($report['languages'] !== []): ?>
                <table>
                    <thead><tr><th>Sprache</th><th class="num">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['languages'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <?php if ($report['countries'] !== []): ?>
                <table style="margin-top:14px">
                    <thead><tr><th>Land</th><th class="num">Aufrufe</th></tr></thead>
                    <tbody>
                    <?php foreach ($report['countries'] as $row): ?>
                        <tr><td><?= e($row['label']) ?></td><td class="num"><?= e($num($row['count'])) ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="z77-report__print">
        <button type="button" onclick="window.print()">Bericht drucken</button>
    </div>

    <footer class="z77-report__foot">
        Gezählt auf dem eigenen Server, ohne Cookie und ohne Dienst Dritter. Die Zahlen sind
        Summen und sagen nichts über eine einzelne Person aus. Einzelne Zeilen werden nach
        sieben Tagen gelöscht, die Monatszahlen bis 24 Monate aufbewahrt.
        <br><br>
        <strong>«Besucher (Tagessumme)»</strong> heisst wörtlich das: die Besucher jedes Tages,
        zusammengezählt. Wer an drei Tagen kommt, steht dreimal darin. Eine Zahl «so viele
        verschiedene Menschen im Monat» gibt es nicht und kann es hier nicht geben — die Kennung,
        mit der ein Gerät wiedererkannt wird, wechselt jede Nacht. Das ist der Preis dafür, dass
        niemand über Tage hinweg verfolgt wird, und der Grund, warum diese Website ohne
        Cookie-Banner auskommt.
        <?php if ($report['capped'] > 0): ?>
            <br><br>
            <?= e($num($report['capped'])) ?> Zeilen blieben unberücksichtigt, weil ein einzelnes
            Gerät an einem Tag ungewöhnlich viele Aufrufe auslöste (Schutz gegen Aufblähen der Zahlen).
        <?php endif; ?>
    </footer>

</article>
