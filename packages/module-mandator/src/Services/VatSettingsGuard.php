<?php

namespace Z77\Module\Mandator\Services;

use Z77\Core\DI;
use Z77\Persistence\Doctrine\OpenWork\OpenWorkChecks;

/**
 * «May the VAT settings of the record change like this?» — asked of whoever keeps the VAT returns,
 * through the open-work registry of persistence-doctrine (`OpenWorkChecks`, scope {@see SCOPE}).
 * This module names no module: a module that posts VAT returns registers a check under the scope in
 * its config (`openWorkChecks`); without one nothing is refused. The registry is the seam the year
 * close and the VAT return already ask (financial.md «the close check») — no second registry.
 *
 * The scope, owned and documented here:
 *
 *   - `mandator-vat-settings` — parameters `before` and `after`, each a {@see VatSettings}: the
 *     stored record and the draft about to be saved. A BLOCKING finding refuses the save; its
 *     `reference` is the FORM FIELD the sentence belongs to (`liable_to_vat`, `vat_method`,
 *     `vat_consideration`, `vat_settlement_rhythm`, `vat_settlement_from`, `vat_settlement_until`) —
 *     any other reference is shown above the form. A warning refuses nothing and is not shown.
 *
 * Asked by {@see \Z77\Module\Mandator\Validators\MandatorValidator} on an UPDATE only (a new record
 * has nothing posted against it) and only when a VAT setting changed. A plain read, no lock: the
 * writer of the returns re-reads the record under its own locks and refuses a period that no longer
 * matches (financial's backstop) — this guard spares the user that refusal, it does not replace it.
 */
final class VatSettingsGuard
{
    /** The open-work scope this module asks — see the class docblock. */
    public const SCOPE = 'mandator-vat-settings';

    /** The form fields a finding may name as its reference. */
    public const FIELDS = ['liable_to_vat', 'vat_method', 'vat_consideration', 'vat_settlement_rhythm', 'vat_settlement_from', 'vat_settlement_until'];

    /** @param OpenWorkChecks|null $checks the registry; null = every registered module's (`OpenWorkChecks::fromModules()`) */
    public function __construct(private readonly VatSettings $before, private readonly ?OpenWorkChecks $checks = null) {}

    /**
     * The refusals of changing the stored settings to $after: one `[field, sentence]` per blocking
     * finding, field null when the reference names no form field. Empty when no VAT setting changed.
     *
     * @return list<array{0: ?string, 1: string}>
     */
    public function refusals(VatSettings $after): array
    {
        if ($after == $this->before) {   // value comparison: enums by identity, dates by their time
            return [];
        }
        $checks   = $this->checks ?? OpenWorkChecks::fromModules(DI::getModuleManager());
        $refusals = [];
        foreach ($checks->ask(self::SCOPE, ['before' => $this->before, 'after' => $after])->blocking() as $finding) {
            $refusals[] = [in_array($finding->reference, self::FIELDS, true) ? $finding->reference : null, $finding->message];
        }

        return $refusals;
    }
}
