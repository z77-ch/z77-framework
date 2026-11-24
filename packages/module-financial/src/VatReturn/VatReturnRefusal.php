<?php

namespace Z77\Module\Financial\VatReturn;

/**
 * Why a VAT return cannot be shown — the settings gate and the refusals of one period (ADR-041
 * addendum 2026-11-24, points 1–5, 8). `reason` is the stable key a screen or a harness compares;
 * `message` the German sentence the screen shows as it is, naming what to do; `fix` the opaque
 * place that clears it, for the screen to turn into a link:
 *
 *   - `mandator:vat`      — Stammdaten › Firma › Mandant, Reiter «UID und MWST»
 *   - `mandator`          — the mandator screen (the record cannot be read, or none is saved)
 *   - `tax-code:{CODE}`   — the tax code in Stammdaten › Finanzen › MWST-Codes
 *   - ``                  — nothing to link (a period without a form edition)
 */
final class VatReturnRefusal
{
    /** The mandator record cannot be read (module not registered, table missing). */
    public const MANDATOR_UNAVAILABLE = 'mandator-unavailable';
    /** Not liable to VAT, and no «Abrechnung bis» keeps the screen (point 4). */
    public const NOT_LIABLE = 'not-liable';
    /** «Abrechnungsmethode» not set — no silent default (point 1). */
    public const METHOD_NOT_SET = 'method-not-set';
    /** The net tax rate or flat tax rate method — not built (point 1). */
    public const METHOD_UNSUPPORTED = 'method-unsupported';
    /** «Abrechnungsart» not set. */
    public const CONSIDERATION_NOT_SET = 'consideration-not-set';
    /** Received consideration — not built (point 1). */
    public const CONSIDERATION_UNSUPPORTED = 'consideration-unsupported';
    /** «Abrechnungsrhythmus» not set (point 2). */
    public const RHYTHM_NOT_SET = 'rhythm-not-set';
    /** «Abrechnung ab» not set (point 3). */
    public const FROM_NOT_SET = 'from-not-set';
    /** The asked day lies outside the liability (before «Abrechnung ab» or after «bis»). */
    public const NO_PERIOD = 'no-period';
    /** No edition of the form covers the period (before 2024 — the earlier form is not mapped). */
    public const NO_FORM = 'no-form';
    /** The period holds reverse-charge lines — refused until stage S6 (point 8). */
    public const REVERSE_CHARGE = 'reverse-charge';
    /** A code + rate the form does not map (point 5: never a silent omission). */
    public const UNMAPPED_CODE = 'unmapped-code';
    /** A journal line carries a code module-vat does not know (a hand-edited code file). */
    public const UNKNOWN_TAX_CODE = 'unknown-tax-code';

    /** Where the VAT settings of the mandator are edited — the end of every settings sentence. */
    public const SETTINGS_PLACE = 'Stammdaten › Firma › Mandant, Reiter «UID und MWST»';

    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $fix = '',
    ) {}
}
