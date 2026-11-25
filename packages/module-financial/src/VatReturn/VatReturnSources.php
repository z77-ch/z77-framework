<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Core\Services\ModuleManager;

/**
 * Where the coded journal lines of a VAT return come from — the ONE place that turns a journal
 * entry's opaque origin (`journal_entry.source_type`, ADR-040 decision 2) into
 *
 *   - the {@see RowOrigin} the calculator places field 235 by (ADR-041 addendum 2026-11-24,
 *     point 7; review C6: by ORIGIN, never by the sign of the base), and
 *   - the DISPLAY origin of the tab «Herkunft» (point 6: «invoices, receipts, creditor invoices,
 *     bank, manual journal entries — the source type is on every entry»): a key and a German label.
 *
 * Financial names no module: the module that WRITES a source type declares it, under its config
 * key `vatReturnSources` (or an extension file `App/Config/vatReturnSourcesConfig.inc.php`) — the
 * `entryDocuments` / `bankEntryOwners` pattern, data instead of classes:
 *
 * ```php
 * // module-debtor: debtorConfig.inc.php
 * 'vatReturnSources' => [
 *     'invoice'     => ['label' => 'Rechnungen'],
 *     'credit-note' => ['label' => 'Gutschriften', 'origin' => 'reduction'],
 * ],
 * ```
 *
 * `origin` is `ordinary` (default) or `reduction` — a module declares a source type a REDUCTION
 * when every coded line it posts under it reduces the consideration (a credit note; debtor's
 * discount and loss allocations, the only allocation kinds that carry tax data). A bad entry fails
 * at registration, naming its origin; a source type declared by two modules is refused. A source
 * type nobody declared is ordinary and labelled by itself.
 *
 * The classification ({@see rowOrigin()}), in this order:
 *
 *   1. an entry of a REVERSAL PAIR — a reversal, or an entry reversed by a reversal dated on or
 *      before the period's end — is {@see RowOrigin::Reversal}: both are netted into field 200,
 *      a cancelled invoice is neither turnover nor a reduction. «On or before the period's end»
 *      keeps an earlier period stable: its original stays what it was when that period was
 *      computed, the later reversal alone is netted into the running period;
 *   2. a source type declared `reduction` is {@see RowOrigin::Reduction};
 *   3. everything else — an invoice, a fee, a receipt, a creditor invoice, a bank posting, a
 *      MANUAL entry (no source type; a hand-made revenue reduction is netted into 200) — is
 *      {@see RowOrigin::Ordinary}.
 *
 * A CORRECTION ENTRY (ADR-042 addendum 2 of 2026-11-25, point 1 — a manual entry marked with the
 * period it corrects) never reaches this classification: the ordinary aggregate leaves it out, and
 * the block of the return computes its lines as {@see RowOrigin::Correction}
 * (`VatReturnService::correctionBlocks()`).
 */
final class VatReturnSources
{
    public const CONFIG_KEY = 'vatReturnSources';

    /** The display key of a manual journal entry (no source type) — a module must not declare it. */
    public const MANUAL = 'manual';

    /** The label of {@see MANUAL}. */
    public const MANUAL_LABEL = 'Manuelle Buchungen';

    /** @var array<string, array{label: string, origin: RowOrigin}> source type → declaration, in registration order */
    private array $sources = [];

    /**
     * @param array<string, mixed> $declarations where each set was declared (a module, a file) → its source type => declaration;
     *                                           every set is checked, a source type declared by two of them is refused
     */
    public function __construct(array $declarations = [])
    {
        foreach ($declarations as $origin => $set) {
            $this->add($set, (string) $origin);
        }
    }

    /** The union of every registered module's `vatReturnSources` (and extension files). */
    public static function fromModules(ModuleManager $modules): self
    {
        $sources = [];
        foreach ($modules->getModuleKeys() as $moduleKey) {
            $declared = $modules->getModuleConfig($moduleKey)?->get(self::CONFIG_KEY, []);
            if ($declared !== null && $declared !== []) {
                $sources[self::CONFIG_KEY . " of module '{$moduleKey}'"] = $declared;
            }
            $sources += $modules->getConfigExtensions($moduleKey, self::CONFIG_KEY);
        }

        return new self($sources);
    }

    /**
     * The calculator's origin of a coded line — see the class comment for the order.
     *
     * @param bool $reversalPair the entry is a reversal, or reversed by one dated on or before the period's end
     */
    public function rowOrigin(?string $sourceType, bool $reversalPair): RowOrigin
    {
        if ($reversalPair) {
            return RowOrigin::Reversal;
        }

        return $sourceType !== null ? ($this->sources[$sourceType]['origin'] ?? RowOrigin::Ordinary) : RowOrigin::Ordinary;
    }

    /** The display key of an entry's origin: its source type, {@see MANUAL} for a manual entry. */
    public function key(?string $sourceType): string
    {
        return $sourceType ?? self::MANUAL;
    }

    /** The German label of a display key (an undeclared source type is shown as itself). */
    public function label(string $key): string
    {
        return $key === self::MANUAL ? self::MANUAL_LABEL : ($this->sources[$key]['label'] ?? $key);
    }

    /** The source type of a display key — null for {@see MANUAL}. */
    public function sourceTypeOf(string $key): ?string
    {
        return $key === self::MANUAL ? null : $key;
    }

    /** The origin a source type is DECLARED with — what a line of it is unless it belongs to a reversal pair. */
    public function declaredOrigin(?string $sourceType): RowOrigin
    {
        return $this->rowOrigin($sourceType, false);
    }

    /** Sort weight of a display key: declared ones in registration order, then undeclared ones, manual entries last. */
    public function position(string $key): int
    {
        if ($key === self::MANUAL) {
            return PHP_INT_MAX;
        }
        $index = array_search($key, array_keys($this->sources), true);

        return $index === false ? PHP_INT_MAX - 1 : $index;
    }

    private function add(mixed $declarations, string $origin): void
    {
        if (!is_array($declarations) || ($declarations !== [] && array_is_list($declarations))) {
            throw new \RuntimeException("❌ {$origin} must be an array of source type => ['label' => …, 'origin' => 'ordinary'|'reduction'].");
        }
        foreach ($declarations as $sourceType => $declaration) {
            if (!is_string($sourceType) || trim($sourceType) === '' || $sourceType === self::MANUAL) {
                throw new \RuntimeException("❌ {$origin}: a source type must be a non-empty string other than '" . self::MANUAL . "', got " . var_export($sourceType, true) . '.');
            }
            $label = is_array($declaration) ? ($declaration['label'] ?? null) : null;
            if (!is_string($label) || trim($label) === '') {
                throw new \RuntimeException("❌ {$origin}: source type '{$sourceType}' needs a German 'label'.");
            }
            $kind = RowOrigin::tryFrom((string) ($declaration['origin'] ?? RowOrigin::Ordinary->value));
            if ($kind !== RowOrigin::Ordinary && $kind !== RowOrigin::Reduction) {
                throw new \RuntimeException("❌ {$origin}: source type '{$sourceType}' declares origin " . var_export($declaration['origin'], true) . " — only 'ordinary' or 'reduction' (a reversal pair is recognised by the journal itself).");
            }
            if (isset($this->sources[$sourceType])) {
                if ($this->sources[$sourceType] === ['label' => $label, 'origin' => $kind]) {
                    continue;
                }
                throw new \RuntimeException("❌ {$origin}: source type '{$sourceType}' is declared twice — a source type belongs to the one module that posts it.");
            }
            $this->sources[$sourceType] = ['label' => $label, 'origin' => $kind];
        }
    }
}
