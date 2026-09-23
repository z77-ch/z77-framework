<?php

namespace Z77\Module\Debtor\Services;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The ONE reader of the accounts the receivables side posts to (plan §6.1,
 * Rule 2) — the `LedgerService::vatAccountFor()` model, key for key. Since
 * owner decision E2 (2026-09-23) the numbers live on the MANDATOR record
 * (`z77/module-mandator`, edited under `/backend/finance/mandator`), no
 * longer in `debtorConfig → debtorAccounts`; this class stays the access
 * point, so the resolution and its refusal live here and nowhere else.
 *
 * Five accounts, each a setting whose name says what it is about:
 *
 * | key | mandator key | what is posted there |
 * |---|---|---|
 * | `receivable` | `receivable`  | the receivables collective account — every finalised invoice debits it |
 * | `discount`   | `discount`    | Skonto granted on a payment (plan §6.3, with its VAT correction) |
 * | `loss`       | `loss`        | a receivable written off (plan §6.3, with its VAT correction) |
 * | `rounding`   | `rounding`    | the invoice's 0.05 rounding line (plan §6.2) — NOT the VAT return's rounding, which is financial's own (plan §5.1) |
 * | `dunningFee` | `dunning-fee` | the dunning fee, without VAT (plan §6.5) |
 *
 * Two levels of answer, on purpose:
 *
 *   - {@see number()} reads the stored NUMBER and refuses when there is
 *     none — no mandator saved, or the field left empty
 *     ({@see AccountNotConfiguredException}, German, naming the key and
 *     where it is set); a mandator that CANNOT be read (module not
 *     registered, table missing — `MandatorUnavailableException`) is the
 *     same refusal with that exception's sentence, never a 500;
 *   - {@see postableNumber()} additionally asks the bookkeeping whether that
 *     account will take a posting ({@see LedgerAccountCheck} → financial's
 *     `accountExists()`, plan §5.4) and refuses with a message NAMING THE
 *     KEY. Without module-financial the check cannot tell, and the number
 *     is returned unverified — financial is only `suggest`ed (ADR-040
 *     decision 5).
 *
 * Both are asked AT THE POINT OF USE, never at boot: an installation that
 * never duns must not fail because `dunningFee` names nothing.
 *
 * A `debtorAccounts` key still present in debtorConfig (a project override
 * copied before the move, BOOT-CONFIG-001) is refused loudly by
 * {@see number()} — `UnexpectedValueException`, a GERMAN sentence naming the
 * move: a second source silently ignored would be worse than a typo.
 * {@see notice()} is the same state asked without throwing, for the red
 * band on the debtor screens (owner decision, review 2026-09-23).
 */
final class DebtorAccounts
{
    /** The config key of the pre-E2 model — refused when still present. */
    public const LEGACY_CONFIG_KEY = 'debtorAccounts';

    /** Every account a part of debtor posts to. */
    public const KEYS = ['receivable', 'discount', 'loss', 'rounding', 'dunningFee'];

    /** German, for the screen that lists the settings and for the refusals. */
    public const LABELS = [
        'receivable' => 'Debitoren-Sammelkonto',
        'discount'   => 'Skonto',
        'loss'       => 'Debitorenverlust',
        'rounding'   => 'Rundungsdifferenz Rechnung',
        'dunningFee' => 'Mahngebühr',
    ];

    /** debtor key → the mandator record's account key (`Mandator::ACCOUNT_KEYS`). */
    private const MANDATOR_KEYS = [
        'receivable' => 'receivable',
        'discount'   => 'discount',
        'loss'       => 'loss',
        'rounding'   => 'rounding',
        'dunningFee' => 'dunning-fee',
    ];

    /** Where a missing account is set — the sentence every refusal ends with. */
    private const MANDATOR_HINT = 'im Mandanten hinterlegen (Finanzen → Mandant, Konten)';

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * The stored account number for $key.
     *
     * @throws \InvalidArgumentException      $key is not one of {@see KEYS} — a programming error
     * @throws \UnexpectedValueException      debtorConfig still carries `debtorAccounts` (the pre-E2 model)
     * @throws AccountNotConfiguredException  no mandator, the field is empty on it, or the mandator cannot be read
     */
    public function number(string $key): string
    {
        if (!in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown debtor account key '{$key}' — one of " . implode(', ', self::KEYS));
        }
        self::refuseLegacyConfig();

        try {
            $mandator = (new CurrentMandator($this->em))->find();
        } catch (MandatorUnavailableException $e) {
            throw new AccountNotConfiguredException($key, $e->getMessage(), $e);
        }
        if ($mandator === null) {
            throw new AccountNotConfiguredException(
                $key,
                'Für «' . self::LABELS[$key] . '» ist kein Konto hinterlegt — es ist noch kein Mandant erfasst (Finanzen → Mandant).'
            );
        }
        $number = $mandator->account(self::MANDATOR_KEYS[$key]);
        if ($number === '') {
            throw new AccountNotConfiguredException(
                $key,
                'Für «' . self::LABELS[$key] . '» ist kein Konto hinterlegt — ' . self::MANDATOR_HINT . '.'
            );
        }

        return $number;
    }

    /**
     * The account for $key, refused unless the bookkeeping will take a
     * posting on it. The message names the key, which is what makes a wrong
     * setting findable — «Konto 1100 …» alone would not.
     *
     * @throws AccountNotConfiguredException the key is unset, or financial refuses the number
     */
    public function postableNumber(string $key): string
    {
        $number = $this->number($key);

        if ((new LedgerAccountCheck($this->em))->isPostable($number) === false) {
            throw new AccountNotConfiguredException(
                $key,
                'Konto ' . $number . ' für «' . self::LABELS[$key] . '» gibt es in der Buchhaltung nicht, '
                . 'es ist eine Gruppe oder inaktiv — ' . self::MANDATOR_HINT . '.'
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
                $rows[$key] = ['number' => $this->softNumber($key), 'error' => $e->getMessage()];
            } catch (\UnexpectedValueException $e) {
                $rows[$key] = ['number' => '', 'error' => $e->getMessage()];
            }
        }

        return $rows;
    }

    /**
     * The German sentence to show as a red band when the WHOLE set cannot be
     * read — a leftover `debtorAccounts`, the mandator module not
     * registered, its table missing — or null when the record is readable
     * (saved or not). Per-key states stay in {@see status()}.
     */
    public function notice(): ?string
    {
        try {
            self::refuseLegacyConfig();
            (new CurrentMandator($this->em))->find();
        } catch (\UnexpectedValueException | MandatorUnavailableException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** The stored number for the status row of a refused key — '' when there is none. */
    private function softNumber(string $key): string
    {
        try {
            return $this->number($key);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @throws \UnexpectedValueException the key of the pre-E2 model is still in the config (German, for the screen) */
    private static function refuseLegacyConfig(): void
    {
        if (DI::getModuleManager()->getModuleConfig('debtor')?->has(self::LEGACY_CONFIG_KEY)) {
            throw new \UnexpectedValueException(
                'debtorConfig enthält noch den Schlüssel «' . self::LEGACY_CONFIG_KEY . '» — die Debitorenkonten liegen seit dem 23.09.2026 im Mandanten (Finanzen → Mandant, Konten). '
                . 'Den Schlüssel aus dem Projekt-Override (override/z77/module/debtor/…/debtorConfig.inc.php) entfernen; bis dahin wird keine Rechnung fakturiert.'
            );
        }
    }
}
