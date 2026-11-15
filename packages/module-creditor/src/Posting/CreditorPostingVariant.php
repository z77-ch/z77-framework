<?php

namespace Z77\Module\Creditor\Posting;

use Z77\Core\DI;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The installation's posting variant (creditor plan §2.4, ADR-059) — `creditorConfig['postingVariant']`,
 * read in THIS place only. A project switches it under `override/` (CE-first); per installation, not
 * per creditor: one accounting method per company.
 *
 * Only `direct` is built: any other value is refused LOUDLY ({@see current()} throws), never silently
 * replaced — a project that names `collective` before it exists must notice at the first intake.
 * A NEW invoice snapshots {@see current()} (`CreditorIntakeService::receive()`); the posting run
 * resolves the strategy from the INVOICE's snapshot ({@see strategyFor()}).
 */
final class CreditorPostingVariant
{
    /** The config key under `creditorConfig`. */
    public const CONFIG_KEY = 'postingVariant';

    /** The variants that are built. */
    public const BUILT = [DirectPosting::KEY];

    /**
     * The variant a new invoice gets — the config value, `direct` when the module config carries none
     * (or no module manager is wired: a script, a test).
     *
     * @throws \LogicException the config names a variant that is not built
     */
    public static function current(): string
    {
        try {
            $value = DI::getModuleManager()->getModuleConfig('creditor')?->get(self::CONFIG_KEY, CreditorInvoice::VARIANT_DIRECT);
        } catch (\Throwable) {
            $value = CreditorInvoice::VARIANT_DIRECT;
        }

        return self::assertBuilt(is_string($value) && $value !== '' ? $value : CreditorInvoice::VARIANT_DIRECT, "creditorConfig['" . self::CONFIG_KEY . "']");
    }

    /**
     * The strategy of $invoice's OWN variant (its snapshot, never the config).
     *
     * @throws \LogicException the invoice names a variant that is not built
     */
    public static function strategyFor(CreditorInvoice $invoice, UnifiedEntityManager $em): CreditorPostingStrategy
    {
        return match (self::assertBuilt($invoice->getPostingVariant(), 'creditor invoice ' . $invoice->getId())) {
            DirectPosting::KEY => new DirectPosting($em),
        };
    }

    private static function assertBuilt(string $variant, string $where): string
    {
        if (!in_array($variant, self::BUILT, true)) {
            throw new \LogicException("{$where} names the posting variant '{$variant}' — only '" . implode("', '", self::BUILT) . "' is built (creditor plan §2.4, ADR-059)");
        }

        return $variant;
    }
}
