<?php

namespace Z77\Module\Financial\Ui;

use Z77\Core\DI;
use Z77\Module\Financial\VatReturn\VatReturnRefusal;
use Z77\Module\Financial\VatReturn\VatReturnService;
use Z77\Shared\Navigation\MenuCondition;

/**
 * Finanzen › «MWST-Abrechnungen» in the backend menu only for a mandator that settles VAT
 * (owner 2026-11-24). Registered in `financialConfig` under `menuConditions` for the list route
 * (`VatReturnLayout::BASE` + `/list`, the seed entry `mwst-abrechnungen`).
 *
 * The ONE question is the screen's own gate, {@see VatReturnService::settingsRefusal()}: hidden
 * only on {@see VatReturnRefusal::NOT_LIABLE} — not liable AND no «Abrechnung bis». Every other
 * answer shows the entry, because the page has something to say:
 *
 *   - liable, settings complete → the returns;
 *   - liable but a setting missing, or a method z77 does not build → the page names it;
 *   - no longer liable but «Abrechnung bis» set (review U5: a mandator that stops being liable
 *     keeps its last returns) → the periods up to that day — the same reading as `periods()`;
 *   - no mandator saved → liable by default (`VatSettings::none()`), the page asks for the settings;
 *   - the mandator record unreadable → the page shows why.
 *
 * Cost: one read of the mandator record by id (Doctrine's identity map — no second query when the
 * request already loaded it); the registry asks once per request, and only when the menu reaches
 * this entry for a user who may open it.
 */
final class VatReturnMenuCondition implements MenuCondition
{
    /** @param VatReturnService|null $service null = the mandator's, through DI (a harness passes its own) */
    public function __construct(private readonly ?VatReturnService $service = null) {}

    public function visible(): bool
    {
        $service = $this->service ?? new VatReturnService(DI::getUnifiedEntityManager());

        return $service->settingsRefusal()?->reason !== VatReturnRefusal::NOT_LIABLE;
    }
}
