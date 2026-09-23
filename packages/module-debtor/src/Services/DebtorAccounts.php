<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The ONE reader of `debtorConfig → debtorAccounts` (plan §6.1, Rule 2) —
 * the `LedgerService::vatAccountFor()` model, key for key.
 *
 * Five accounts, each a setting whose name says what it is about:
 *
 * | key | what is posted there |
 * |---|---|
 * | `receivable` | the receivables collective account — every finalised invoice debits it |
 * | `discount`   | Skonto granted on a payment (plan §6.3, with its VAT correction) |
 * | `loss`       | a receivable written off (plan §6.3, with its VAT correction) |
 * | `rounding`   | the invoice's 0.05 rounding line (plan §6.2) — NOT the VAT return's rounding, which is financial's own key (plan §5.1) |
 * | `dunningFee` | the dunning fee, without VAT (plan §6.5) |
 *
 * Two levels of answer, on purpose:
 *
 *   - {@see number()} reads the configured NUMBER and fails loudly on a
 *     malformed value — a typo in a config file is reported, never skipped
 *     (`LedgerService::listLimit()`);
 *   - {@see postableNumber()} additionally asks the bookkeeping whether that
 *     account will take a posting ({@see LedgerAccountCheck} → financial's
 *     `accountExists()`, plan §5.4) and refuses with a German message NAMING
 *     THE KEY. Without module-financial the check cannot tell, and the
 *     number is returned unverified — financial is only `suggest`ed
 *     (ADR-040 decision 5).
 *
 * Both are asked AT THE POINT OF USE, never at boot: an installation that
 * never duns must not fail because `dunningFee` names nothing.
 *
 * ⚠️ BOOT-CONFIG-001 (`bootstrap.md`): a project override of
 * `debtorConfig.inc.php` REPLACES the package config rather than merging
 * into it, so an override must carry the FULL `debtorAccounts` map, not
 * just the key it changes. A missing key is then an
 * {@see AccountNotConfiguredException} at the point of use — which is the
 * loud failure this caveat needs until the merge is built.
 */
final class DebtorAccounts
{
    /** The config key this class is the single reader of. */
    public const CONFIG_KEY = 'debtorAccounts';

    /** Every account a part of debtor posts to — the keys `debtorConfig` must carry. */
    public const KEYS = ['receivable', 'discount', 'loss', 'rounding', 'dunningFee'];

    /** German, for the screen that lists the settings and for the refusals. */
    public const LABELS = [
        'receivable' => 'Debitoren-Sammelkonto',
        'discount'   => 'Skonto',
        'loss'       => 'Debitorenverlust',
        'rounding'   => 'Rundungsdifferenz Rechnung',
        'dunningFee' => 'Mahngebühr',
    ];

    /** The longest account number financial's `Account::NUMBER_LENGTH` holds — repeated, not imported: financial may be absent. */
    private const NUMBER_LENGTH = 10;

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * The configured account number for $key.
     *
     * @throws \InvalidArgumentException      $key is not one of {@see KEYS} — a programming error
     * @throws \UnexpectedValueException      `debtorAccounts` is not a map of key => account number (digits string)
     * @throws AccountNotConfiguredException  the key is absent (a project override that dropped it, BOOT-CONFIG-001)
     */
    public static function number(string $key): string
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown debtor account key '{$key}' — one of " . implode(', ', self::KEYS));
        }

        $config     = DI::getModuleManager()->getModuleConfig('debtor');
        $configured = $config?->has(self::CONFIG_KEY) ? $config->get(self::CONFIG_KEY) : [];
        if (!is_array($configured)) {
            throw new \UnexpectedValueException(
                'debtorConfig: ' . self::CONFIG_KEY . ' must be an array of key => account number, got ' . get_debug_type($configured) . '.'
            );
        }
        if (!array_key_exists($key, $configured)) {
            throw new AccountNotConfiguredException(
                $key,
                'Für «' . (self::LABELS[$key] ?? $key) . '» ist kein Konto hinterlegt — debtorConfig → '
                . self::CONFIG_KEY . "['{$key}'] fehlt."
            );
        }

        $number = $configured[$key];
        if (!is_string($number) || !preg_match('/^[0-9]{1,' . self::NUMBER_LENGTH . '}$/', $number)) {
            throw new \UnexpectedValueException(
                "debtorConfig: " . self::CONFIG_KEY . "['{$key}'] must be an account number (digits, as a string), got " . var_export($number, true) . '.'
            );
        }

        return $number;
    }

    /**
     * The account for $key, refused unless the bookkeeping will take a
     * posting on it. The message names the config key, which is what makes
     * a wrong setting findable — «Konto 1100 …» alone would not.
     *
     * @throws AccountNotConfiguredException the key is absent, or financial refuses the number
     */
    public function postableNumber(string $key): string
    {
        $number = self::number($key);

        if ((new LedgerAccountCheck($this->em))->isPostable($number) === false) {
            throw new AccountNotConfiguredException(
                $key,
                'Konto ' . $number . ' für «' . (self::LABELS[$key] ?? $key) . '» gibt es in der Buchhaltung nicht, '
                . 'es ist eine Gruppe oder inaktiv — debtorConfig → ' . self::CONFIG_KEY . "['{$key}'] prüfen."
            );
        }

        return $number;
    }

    /**
     * Every key with its number and the refusal it would raise — what the
     * settings panel on the debtor screen shows, so a wrong account is found
     * before an invoice is posted rather than after.
     *
     * @return array<string, array{number: string, error: string|null}>
     */
    public function status(): array
    {
        $rows = [];
        foreach (self::KEYS as $key) {
            try {
                $rows[$key] = ['number' => $this->postableNumber($key), 'error' => null];
            } catch (AccountNotConfiguredException $e) {
                $rows[$key] = ['number' => self::softNumber($key), 'error' => $e->getMessage()];
            } catch (\UnexpectedValueException $e) {
                $rows[$key] = ['number' => '', 'error' => $e->getMessage()];
            }
        }

        return $rows;
    }

    /** The configured number for the status row of a refused key — '' when there is none. */
    private static function softNumber(string $key): string
    {
        try {
            return self::number($key);
        } catch (\Throwable) {
            return '';
        }
    }
}
