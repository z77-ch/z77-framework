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
        <div class="be-modal__alert be-modal__alert--error">
            <strong>Sperrliste unlesbar — die Regel ist derzeit AUS.</strong><br>
            Die Datei <code>data/framework/forms/blocked-countries.json</code>
            kann nicht gelesen werden. Die Formulare laufen weiter (die Regel
            fällt offen aus), aber kein Land wird gesperrt, bis die Datei
            repariert oder gelöscht ist. Details stehen im error_log.
        </div>
    <?php elseif ($blocked === []): ?>
        <p class="be-list__empty">
            Keine Sperre gesetzt — Übermittlungen werden aus allen Ländern
            angenommen. Das ist der Normalfall: gesperrt wird erst, wenn die
            Auszählung oben einen Grund zeigt.
        </p>
    <?php else: ?>
        <p class="be-list__section-hint">
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
                    <td class="be-table__cell--nowrap"><?= e($when($entry->getAddedAt())) ?></td>
                    <td class="be-table__cell--muted"><?= e($entry->getAddedBy() ?? '—') ?></td>
                    <td class="be-table__cell--end">
                        <button type="button" class="be-btn be-btn--ghost be-btn--sm"
                                data-fetch-get="<?= e($actionBase) ?>/confirm-unblock?code=<?= e(rawurlencode($entry->getCode())) ?>">Aufheben</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>
