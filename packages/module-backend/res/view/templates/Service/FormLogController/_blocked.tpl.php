<?php
/**
 * «Gesperrte Länder» — the blocklist section. Rendered by the list and by FormLogController's
 * block / unblock answer (`replace-html` of `[data-form-log-blocked]`, ADR-047 addendum
 * 2026-10-10), so both show the same section; core.js wires its «Aufheben» triggers.
 *
 * @var array<string,\Z77\Shared\Entities\BlockedCountry> $blocked
 * @var bool   $blockedBroken
 * @var string $actionBase
 */
$muted = 'font-size:.75rem;color:var(--be-muted,#94a3b8)';
$when  = static function (?string $iso): string {
    $time = $iso === null ? false : strtotime($iso);
    return $time === false ? (string)$iso : date('d.m.Y, H:i:s', $time);
};
?>
<section class="be-list__section" data-form-log-blocked>
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
                <tr data-entity="blocked-country:<?= e($entry->getCode()) ?>">
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
