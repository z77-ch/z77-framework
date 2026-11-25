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

    // ── «MWST buchen» (stage S2 slice 5, `VatReturnPostingService`) ──────────────────────────
    // The posting preconditions are also BLOCKING findings of the plan (`VatSettlementPlan`), with
    // the reason as the finding's code — the screen shows them as «Offene Punkte», post() refuses
    // with the first of them.

    /** The period asked for is not a period of the mandator's calendar (a stale or typed address). */
    public const NOT_A_PERIOD = 'not-a-period';
    /** A live ordinary return covers the period already (a second «MWST buchen», or a stale screen). */
    public const ALREADY_SETTLED = 'already-settled';
    /** The period has not ended yet. */
    public const NOT_ELAPSED = 'not-elapsed';
    /** An earlier elapsed period is not settled — returns follow one another without a gap, in order (ADR-041 addendum point 2). */
    public const NOT_NEXT = 'not-next';
    /** No fiscal year holds the period's last day — the settlement entry has nowhere to go. */
    public const NO_FISCAL_YEAR = 'no-fiscal-year';
    /** The fiscal year holding the period's last day is closed (ADR-042 addendum point 5: the year must be open). */
    public const YEAR_CLOSED = 'year-closed';
    /** The settlement entry needs the mandator's settlement or rounding account, and it is not set or not postable. */
    public const ACCOUNT_MISSING = 'account-missing';
    /** A blocking finding — the open-work registry or the return's own checks; the findings ride on the exception. */
    public const BLOCKED = 'blocked';
    /** There are warnings and none were confirmed. */
    public const WARNINGS_UNCONFIRMED = 'warnings-unconfirmed';
    /** The warnings differ from the ones confirmed (their fingerprint). */
    public const WARNINGS_CHANGED = 'warnings-changed';
    /** The figures or the settlement entry differ from the ones the poster saw (the figures fingerprint) — a posting landed meanwhile. */
    public const FIGURES_CHANGED = 'figures-changed';
    /** «Eingereicht am» lies after today or before the period's last day. */
    public const FILED_ON_INVALID = 'filed-on-invalid';
    /** The fiscal years changed between the look before the locks and the locks themselves — post again. */
    public const FISCAL_YEAR_CHANGED = 'fiscal-year-changed';
    /** The return asked for does not exist (setting «Eingereicht am»). */
    public const NOT_FOUND = 'not-found';
    /** The return is not posted any more (lifted) — «Eingereicht am» stands on a posted return only. */
    public const NOT_POSTED = 'not-posted';
    /** The return was changed since the screen was loaded (its version). */
    public const STALE = 'stale';

    /** Where the VAT settings of the mandator are edited — the end of every settings sentence. */
    public const SETTINGS_PLACE = 'Stammdaten › Firma › Mandant, Reiter «UID und MWST»';

    public function __construct(
        public readonly string $reason,
        public readonly string $message,
        public readonly string $fix = '',
    ) {}
}
