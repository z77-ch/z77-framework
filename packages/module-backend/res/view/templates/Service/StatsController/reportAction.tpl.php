<?php
/**
 * The link page (`/stats/report/{token}`, StatsController::reportAction) inside
 * `html-report-skeleton`. Three states: the report, a link past its date (410),
 * and anything else (404 — an unknown token, no key configured, a month without
 * data). The last two say as little as possible: a stranger learns nothing.
 *
 * @var string  $state     ok | expired | missing
 * @var ?array  $report    StatsReport::build() when ok
 * @var ?int    $expiresAt the link's expiry when known
 * @var string  $siteName  host of canonicalBaseUrl
 */
$box = 'max-width:560px;margin:15vh auto 0;padding:0 20px;font:16px/1.5 ui-sans-serif,system-ui,"Segoe UI",Roboto,Arial,sans-serif;color:#1a1a1a';
?>
<?php if ($state === 'ok'): ?>
    <?= $this->partial('Service/StatsController/_report', [
        'report'     => $report,
        'siteName'   => $siteName,
        'validUntil' => 'Dieser Link ist bis ' . date('j.n.Y', (int) $expiresAt) . ' gültig',
    ], 'Z77\\Module\\Backend') ?>
<?php elseif ($state === 'expired'): ?>
    <div style="<?= $box ?>">
        <h1 style="font-size:22px;margin:0 0 8px">Dieser Link ist abgelaufen</h1>
        <p style="margin:0;color:#6b6b6b">
            Ein Link zur Besuchsstatistik gilt zehn Tage. Der nächste Monatsbericht bringt
            einen neuen; wer ihn früher braucht, fragt beim Betreiber der Website nach.
        </p>
    </div>
<?php else: ?>
    <div style="<?= $box ?>">
        <h1 style="font-size:22px;margin:0 0 8px">Nicht gefunden</h1>
        <p style="margin:0;color:#6b6b6b">Unter dieser Adresse gibt es nichts.</p>
    </div>
<?php endif; ?>
