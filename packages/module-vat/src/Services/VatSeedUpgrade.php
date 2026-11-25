<?php

namespace Z77\Module\Vat\Services;

use Z77\Module\Vat\Entities\TaxCode;
use Z77\Module\Vat\Entities\TaxRate;
use Z77\Module\Vat\Repositories\TaxCodeRepository;
use Z77\Module\Vat\Repositories\TaxRateRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The ONE upgrade step of the seed codes the VAT return needs (ADR-041 addendum 2026-11-25,
 * point 5): `tax_codes.json` / `tax_rates.json` are seeded ONCE (ADR-024, VAT-SEED-001), so an
 * installation from before the return lacks the codes the form maps by NAME — UL (supplies
 * abroad, field 221), VE / VK / VN (the input-tax corrections), BM / BI / BM2 / BI2 (reverse
 * charge, whose input field the rule file finds by the code). There is no generic record merge
 * for file master data (ADR-032), so this is the smallest one, idempotent (the pattern of order's
 * `OrderStatusUpgrade`):
 *
 *   - no code file yet (no code at all) → nothing; the installer seeds the current set;
 *   - a code of {@see CODES} exists → untouched, whatever it carries — when its category is not
 *     the seed's it is reported as FOREIGN (an installation's own code under that name blocks the
 *     form's mapping; the setup check `vat.seed-codes` names it);
 *   - a missing code is created from the package's own seed files — the code through
 *     {@see VatMasterData::saveCode()}, its rates through {@see VatMasterData::addRate()} NEWEST
 *     FIRST (the first rate of a code may start at any date, every older one is backfill before
 *     the earliest row — VAT-RATE-002 — so the backdating rule holds unchanged); rates are added
 *     only while the code has none.
 *
 * Run by the tax-code list (Stammdaten › Finanzen › MWST-Codes) and by the setup check
 * {@see \Z77\Module\Vat\ProjectHealth\VatSeedCodesCheck}. A file write, never inside a Doctrine
 * unit of work.
 */
final class VatSeedUpgrade
{
    /** The codes the upgrade guarantees, in seed order. */
    public const CODES = ['UL', 'VE', 'VK', 'VN', 'BM', 'BI', 'BM2', 'BI2'];

    /** Where the package's seed files live — the one source of the codes' category, label and rates. */
    public const SEED_DIR = __DIR__ . '/../../data/framework/vat';

    /**
     * @return array{created: list<string>, foreign: list<string>} the codes written now; the codes
     *         present under a seed name with another category (never changed)
     */
    public static function apply(UnifiedEntityManager $em, \DateTimeImmutable $today): array
    {
        /** @var TaxCodeRepository $codes */
        $codes = $em->getRepository(TaxCode::class);
        /** @var TaxRateRepository $rates */
        $rates  = $em->getRepository(TaxRate::class);
        $result = ['created' => [], 'foreign' => []];
        if ($codes->findAll() === []) {
            return $result;
        }

        $seed   = self::seed();
        $master = new VatMasterData($em);
        foreach (self::CODES as $name) {
            $row      = $seed['codes'][$name];
            $existing = $codes->findByCode($name);
            if ($existing !== null) {
                if ($existing->getCategory() !== $row['category']) {
                    $result['foreign'][] = $name;
                }
                continue;
            }
            $master->saveCode(new TaxCode([
                'code'     => $name,
                'country'  => $row['country'],
                'category' => $row['category'],
                'label'    => $row['label'],
                'active'   => (bool) $row['active'],
            ]));
            if ($rates->findByCode($name) === []) {
                $own = $seed['rates'][$name] ?? [];
                usort($own, static fn(array $a, array $b): int => strcmp($b['valid_from'], $a['valid_from']));
                foreach ($own as $rate) {
                    $master->addRate(new TaxRate(['code' => $name, 'valid_from' => $rate['valid_from'], 'rate' => $rate['rate']]), $today);
                }
            }
            $result['created'][] = $name;
        }

        return $result;
    }

    /**
     * The upgrade's codes and their rates, read from the package's seed files.
     *
     * @return array{codes: array<string, array<string, mixed>>, rates: array<string, list<array{valid_from: string, rate: int}>>}
     */
    private static function seed(): array
    {
        $read = static function (string $name): array {
            $data = json_decode((string) file_get_contents(self::SEED_DIR . '/' . $name . '.default.json'), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \UnexpectedValueException("module-vat seed {$name}.default.json is not a list");
            }

            return $data;
        };
        $seed = ['codes' => [], 'rates' => []];
        foreach ($read('tax_codes') as $row) {
            $seed['codes'][$row['code']] = $row;
        }
        foreach ($read('tax_rates') as $row) {
            $seed['rates'][$row['code']][] = ['valid_from' => $row['valid_from'], 'rate' => $row['rate']];
        }
        foreach (self::CODES as $name) {
            if (!isset($seed['codes'][$name])) {
                throw new \LogicException("module-vat seed has no code {$name} — the upgrade and the seed must name the same codes");
            }
        }

        return $seed;
    }
}
