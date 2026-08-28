<?php
/**
 * «Formular-Protokoll» — the form log, newest first, and the country
 * blocklist decided from it.
 *
 * Order on the page follows the decision: first WHERE the attempts come from
 * (with the button that acts on it), then WHAT the gates made of them, then
 * the countries already blocked, then the raw lines. The evidence stands
 * above the switch it justifies.
 *
 * ⚠️ EVERY value below came from outside — address, user agent, identity,
 * even the country is derived from a header-free but visitor-supplied
 * connection. All of it goes through `e()`.
 *
 * ⚠️ The MaxMind line at the foot is a LICENCE TERM (GeoLite EULA), not a
 * credit we may drop for tidiness.
 *
 * @var list<array<string,mixed>> $rows
 * @var array<string,int> $byCountry
 * @var array<string,int> $byOutcome
 * @var array<string,\Z77\Shared\Entities\BlockedCountry> $blocked
 * @var bool $blockedBroken
 * @var list<string> $forms
 * @var string $filter
 * @var int $total
 * @var int $limit
 * @var int $retention
 * @var string $actionBase
 * @var bool $geoReady
 * @var int|null $geoBuilt
 * @var string $geoNotice
 */
$muted = 'font-size:.75rem;color:var(--be-muted,#94a3b8)';
$when  = static function (?string $iso): string {
    $time = $iso === null ? false : strtotime($iso);
    return $time === false ? (string)$iso : date('d.m.Y, H:i:s', $time);
};
// What each outcome MEANS, in the operator's words. The raw keys are the
// handler's vocabulary; nobody should have to learn it to read this page.
// ⚠️ The key is `effective`, not `outcome` — the controller has already
// resolved the gates that EXPLAIN a «failed» into their own value (the reason
// is documented there). Both sit side by side here so that the tally and the
// row use the same table and cannot drift apart.
$says = [
    'sent'         => ['Angenommen',        'success'],
    'bot'          => ['Bot abgewiesen',    'warning'],
    // A gate that refuses is not a defect: intent colour, not failure colour.
    'geo'          => ['Land gesperrt',     'warning'],
    'limited'      => ['Limit erreicht',    'warning'],
    'invalid'      => ['Eingabe abgelehnt', 'warning'],
    'csrf'         => ['Token ungültig',    'warning'],
    'failed'       => ['Versand scheiterte','danger'],
    'throttled'    => ['Adress-Drossel',    'warning'],
    'throttled-ip' => ['Herkunfts-Drossel', 'warning'],
];
$detailSays = [
    'new'          => 'neues Konto',
    'known'        => 'Adresse bekannt',
    'throttled'    => 'Adress-Drossel',
    'throttled-ip' => 'Herkunfts-Drossel',
];
?>
<div class="be-list">

    <section class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Übermittlungen an öffentliche Formulare</h2>
            <span class="be-list__section-badge"><?= (int)$total ?></span>
        </div>

        <p style="<?= $muted ?>;padding:.25rem .5rem 0">
            Die <?= (int)$limit ?> jüngsten Versuche, neueste zuerst — aus jedem
            Formular, dessen Geo-Guard eingeschaltet ist. Aufbewahrt werden sie
            <?= (int)$retention ?> Tage, danach räumt der Job sie weg.
            <?php if (!$geoReady): ?>
                <br><strong>Kein Länder-Datenbestand installiert</strong> — die Spalte «Land»
                bleibt leer und die Sperrliste kann nie greifen, alles andere wird
                trotzdem geführt (Einrichtung: docs/topics/geoip.md).
            <?php elseif ($geoBuilt !== null): ?>
                <br>Länder-Datenbestand vom <?= e(date('d.m.Y', $geoBuilt)) ?>.
            <?php endif; ?>
        </p>

        <?php if (count($forms) > 1 || $filter !== ''): ?>
        <p style="<?= $muted ?>;padding:.25rem .5rem .75rem">
            Formular:
            <?php if ($filter === ''): ?><strong>alle</strong><?php else: ?><a href="<?= e($actionBase) ?>/list">alle</a><?php endif; ?>
            <?php foreach ($forms as $key): ?>
                · <?php if ($filter === $key): ?><strong><?= e($key) ?></strong><?php else: ?><a href="<?= e($actionBase) ?>/list?form=<?= e(rawurlencode($key)) ?>"><?= e($key) ?></a><?php endif; ?>
            <?php endforeach; ?>
        </p>
        <?php else: ?>
        <p style="padding:0 0 .5rem"></p>
        <?php endif; ?>

        <?php if ($total === 0): ?>
            <p style="<?= $muted ?>;padding:.5rem">
                Noch keine Übermittlung aufgezeichnet.
            </p>
        <?php else: ?>

        <div style="display:flex;gap:2rem;flex-wrap:wrap;padding:0 .5rem 1rem">
            <div>
                <div style="<?= $muted ?>;margin-bottom:.35rem">Woher</div>
                <?php foreach ($byCountry as $code => $count):
                    // «??» is not a country and cannot be blocked — an unknown
                    // origin is localhost, a private range or a missing
                    // database, and blocking it would bar everyone the lookup
                    // cannot place. Same reason the gate itself fails open.
                    $known     = $code !== '??';
                    $isBlocked = $known && isset($blocked[$code]);
                ?>
                    <div style="display:flex;gap:.75rem;align-items:center;justify-content:space-between;min-width:14rem">
                        <span><?= $known ? e($code) : '<span style="' . $muted . '">unbekannt</span>' ?></span>
                        <span style="display:flex;gap:.5rem;align-items:center">
                            <strong><?= (int)$count ?></strong>
                            <?php if ($isBlocked): ?>
                                <span class="badge badge--warning">gesperrt</span>
                            <?php elseif ($known && !$blockedBroken): ?>
                                <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                                        data-fetch-get="<?= e($actionBase) ?>/confirm-block?code=<?= e(rawurlencode($code)) ?>">Land sperren</button>
                            <?php endif; ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div>
                <div style="<?= $muted ?>;margin-bottom:.35rem">Was daraus wurde</div>
                <?php foreach ($byOutcome as $key => $count): ?>
                    <div style="display:flex;gap:.75rem;justify-content:space-between;min-width:12rem">
                        <span><?= e($says[$key][0] ?? $key) ?></span>
                        <strong><?= (int)$count ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php endif; ?>
    </section>

    <section class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Gesperrte Länder</h2>
            <span class="be-list__section-badge"><?= count($blocked) ?></span>
        </div>

        <?php if ($blockedBroken): ?>
            <p style="padding:.5rem">
                <strong>Sperrliste unlesbar — die Regel ist derzeit AUS.</strong><br>
                <span style="<?= $muted ?>">
                    Die Datei <code>data/framework/forms/blocked-countries.json</code>
                    kann nicht gelesen werden. Die Formulare laufen weiter (die Regel
                    fällt offen aus), aber kein Land wird gesperrt, bis die Datei
                    repariert oder gelöscht ist. Details stehen im error_log.
                </span>
            </p>
        <?php elseif ($blocked === []): ?>
            <p style="<?= $muted ?>;padding:.5rem">
                Keine Sperre gesetzt — Übermittlungen werden aus allen Ländern
                angenommen. Das ist der Normalfall: gesperrt wird erst, wenn die
                Auszählung oben einen Grund zeigt.
            </p>
        <?php else: ?>
            <p style="<?= $muted ?>;padding:.25rem .5rem .75rem">
                Aus diesen Ländern weisen Formulare mit eingeschaltetem Geo-Guard
                jede Übermittlung ab. Ein Land, das der Datenbestand nicht zuordnen
                kann, wird <strong>nie</strong> gesperrt — im Zweifel läuft die
                Übermittlung durch.
            </p>
            <table class="be-table">
                <thead>
                    <tr>
                        <th>Land</th>
                        <th>Grund</th>
                        <th>Gesperrt am</th>
                        <th>Durch</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($blocked as $entry): ?>
                    <tr>
                        <td><strong><?= e($entry->getCode()) ?></strong></td>
                        <td><?= e($entry->getReason()) ?></td>
                        <td style="white-space:nowrap"><?= e($when($entry->getAddedAt())) ?></td>
                        <td style="<?= $muted ?>"><?= e($entry->getAddedBy() ?? '—') ?></td>
                        <td style="text-align:right">
                            <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                                    data-fetch-get="<?= e($actionBase) ?>/confirm-unblock?code=<?= e(rawurlencode($entry->getCode())) ?>">Aufheben</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ($total > 0): ?>
    <section class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title">Protokoll</h2>
        </div>

        <table class="be-table">
            <thead>
                <tr>
                    <th>Zeitpunkt</th>
                    <th>Formular</th>
                    <th>Land</th>
                    <th>Herkunft</th>
                    <th>Ausgang</th>
                    <th>Identität</th>
                    <th>Über</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $effective = (string)($row['effective'] ?? $row['outcome'] ?? '');
                $detail    = (string)($row['detail'] ?? '');
                [$label, $tone] = $says[$effective] ?? [$effective, 'muted'];
                // The note only still explains the row when it has not become
                // the label itself.
                if ($detail === $effective) {
                    $detail = '';
                }
            ?>
                <tr>
                    <td style="white-space:nowrap"><?= e($when($row['at'] ?? null)) ?></td>
                    <td style="<?= $muted ?>"><?= e((string)($row['form'] ?? '—')) ?></td>
                    <td><?= isset($row['country']) && $row['country'] !== null
                            ? '<strong>' . e((string)$row['country']) . '</strong>'
                            : '<span style="' . $muted . '">—</span>' ?></td>
                    <td style="<?= $muted ?>;white-space:nowrap"><?= e((string)($row['ip'] ?? '—')) ?></td>
                    <td>
                        <span class="badge badge--<?= e($tone) ?>"><?= e($label) ?></span>
                        <?php if ($detail !== ''): ?>
                            <span style="<?= $muted ?>"><?= e($detailSays[$detail] ?? $detail) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string)($row['identity'] ?? '—')) ?></td>
                    <td style="<?= $muted ?>"><?= e((string)($row['origin'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
    <?php endif; ?>

    <?php if ($geoReady): ?>
        <p style="<?= $muted ?>;padding:.75rem .5rem 0">
            <?= e($geoNotice) ?>
        </p>
    <?php endif; ?>
</div>
