<?php

namespace Z77\Module\Mandator\ProjectHealth;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Mandator\Services\MandatorAccounts;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Shared\ProjectHealth\SetupCheck;

/**
 * Setup step 2 («Ersteinrichtung», owner 2026-11-19), three answers on one screen, in this order:
 *
 *   1. the mandator is not saved yet — «Mandant erfassen» (was the dashboard line «Mandant nicht
 *      erfasst», moved here from `MandatorDashboard`);
 *   2. one of its accounts with a KMU start value (`MandatorAccounts::DEFAULTS`: debtor — the payment
 *      charges included (2026-11-24) —, VAT, year opening — WITHOUT the VAT return's two) is empty or not a postable, active account of the
 *      chart — «Mandant: Konten zuweisen»;
 *   3. (2026-11-24, ADR-041 addendum points 4 and 13) the mandator is LIABLE to VAT and a setting of
 *      the VAT return is missing — method, consideration, rhythm, «Abrechnung ab», the settlement,
 *      the rounding or (2026-11-25) the transit accounts «Vorsteuer, noch nicht abgerechnet» and
 *      «MWST-Korrektur, noch nicht abgerechnet» (empty or not postable; an existing chart lacks 6945,
 *      1179 and 2209 until they are created)
 *      — one line naming each missing item, the logo step's style: «Mandant MWST: Abrechnungsmethode,
 *      … und Rundungskonto festlegen». A mandator that is NOT liable is never asked for them.
 *
 * `private` / `bank-transfer` have no start value and are asked only when a mixed bank account needs
 * them (financial's `financial.bank-mixed`). Without module-financial registered the numbers cannot
 * be checked (`LedgerAccountCheck` answers null) — then only an EMPTY field counts. An unreadable
 * record (`MandatorUnavailableException`) throws: the bridge shows «nicht verfügbar».
 *
 * Two queries: the record, and the chart rows of its numbers (`LedgerAccountCheck::notPostable()`).
 */
final class MandatorAccountsCheck extends SetupCheck
{
    /** The words of the VAT-return items, in the order the line names them (German, the screen's labels). */
    public const VAT_ITEMS = [
        'method'         => 'Abrechnungsmethode',
        'consideration'  => 'Abrechnungsart',
        'rhythm'         => 'Abrechnungsrhythmus',
        'from'           => 'Abrechnung ab',
        'vat-settlement' => 'Abrechnungskonto',
        'vat-rounding'   => 'Rundungskonto',
        // «Vorsteuer, noch nicht abgerechnet» (KMU 1179, 2026-11-25) — short in the line, like the two above.
        'vat-input-pending' => 'Durchgangskonto Vorsteuer',
        // «MWST-Korrektur, noch nicht abgerechnet» (KMU 2209, 2026-11-25) — the same way.
        'vat-correction-pending' => 'Durchgangskonto MWST-Korrektur',
    ];

    private bool $missing = false;

    /** @var list<string> keys of {@see VAT_ITEMS} missing for a liable mandator — set by isOpen() */
    private array $vatMissing = [];

    public function id(): string
    {
        return 'mandator.accounts';
    }

    public function order(): int
    {
        return 20;
    }

    public function target(): array
    {
        return ['backend', 'finance', 'mandator', 'edit'];
    }

    public function label(): string
    {
        if ($this->missing) {
            return 'Mandant erfassen';
        }
        if ($this->vatMissing === []) {
            return 'Mandant: Konten zuweisen';
        }
        $words = array_map(static fn(string $item): string => self::VAT_ITEMS[$item], $this->vatMissing);
        $last  = array_pop($words);

        return 'Mandant MWST: ' . ($words === [] ? $last : implode(', ', $words) . ' und ' . $last) . ' festlegen';
    }

    public function isOpen(): bool
    {
        $em       = DI::getUnifiedEntityManager();
        $mandator = (new CurrentMandator($em))->find();
        $this->missing    = $mandator === null;
        $this->vatMissing = [];
        if ($mandator === null) {
            return true;
        }
        $baseKeys = array_values(array_diff(array_keys(MandatorAccounts::DEFAULTS), MandatorAccounts::VAT_RETURN_KEYS));
        $vatKeys  = $mandator->isLiableToVat() ? MandatorAccounts::VAT_RETURN_KEYS : [];
        $numbers  = array_map(static fn(string $key) => $mandator->account($key), array_merge($baseKeys, $vatKeys));
        $refused  = (new LedgerAccountCheck($em))->notPostable($numbers) ?? [];

        $unusable = static fn(string $key): bool => $mandator->account($key) === '' || in_array($mandator->account($key), $refused, true);
        if (array_filter($baseKeys, $unusable) !== []) {
            return true;   // «Konten zuweisen» first — the VAT items follow once the accounts are in place
        }
        if ($vatKeys !== []) {
            $this->vatMissing = self::missingVatItems(VatSettings::of($mandator), $unusable);
        }

        return $this->vatMissing !== [];
    }

    /**
     * @param callable(string): bool $unusable
     * @return list<string>
     */
    private static function missingVatItems(VatSettings $settings, callable $unusable): array
    {
        $missing = array_keys(array_filter([
            'method'         => $settings->method === null,
            'consideration'  => $settings->consideration === null,
            'rhythm'         => $settings->rhythm === null,
            'from'           => $settings->from === null,
            'vat-settlement' => $unusable('vat-settlement'),
            'vat-rounding'   => $unusable('vat-rounding'),
            'vat-input-pending' => $unusable('vat-input-pending'),
            'vat-correction-pending' => $unusable('vat-correction-pending'),
        ]));

        return array_values($missing);
    }
}
