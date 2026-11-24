<?php

namespace Z77\Module\Vat\CountryPack;

/**
 * A country pack's VAT return form mapping: the readable rule file
 * `res/country-packs/{country}.json` of this package, with one entry per
 * EDITION of the authority's form (ADR-041 addendum 2026-11-24, point 5 —
 * keyed by category + rate, not by code; versioned by the validity of the
 * form).
 *
 * Package data, not installation data: the file ships with the package and
 * is read in place (like financial's `res/charts/kmu.json`), so a new
 * edition of the form reaches every installation with the update — a seed
 * under `data/` is copied once and never again (VAT-SEED-001).
 *
 * The consumer (financial's VAT return) asks for the edition of a settlement
 * period and assigns each tax code + rate a field through it.
 */
final class FormMapping
{
    /** Where the country packs' rule files live. */
    public const PACK_DIR = __DIR__ . '/../../res/country-packs';

    /** @param list<FormEdition> $editions in date order */
    private function __construct(
        public readonly string $country,
        private readonly array $editions,
    ) {}

    /**
     * The mapping of one country (`CH`) — its rule file, read and checked.
     *
     * @throws FormMappingException no pack for the country, or the file is not usable
     */
    public static function forCountry(string $country): self
    {
        if (!preg_match('/^[A-Z]{2}$/', $country)) {
            throw new FormMappingException("Not a country code: '{$country}'");
        }

        return self::fromFile(self::PACK_DIR . '/' . strtolower($country) . '.json');
    }

    /**
     * Read and check one rule file (UTF-8 JSON without BOM).
     *
     * @throws FormMappingException
     */
    public static function fromFile(string $path): self
    {
        $source = basename($path);
        $json   = is_file($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new FormMappingException("No VAT form mapping at {$path}");
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new FormMappingException("{$source}: not valid JSON — {$e->getMessage()}", 0, $e);
        }
        if (!is_array($data) || !is_string($data['country'] ?? null) || !preg_match('/^[A-Z]{2}$/', $data['country'])) {
            throw new FormMappingException("{$source}: needs a \"country\" (two capitals)");
        }
        if (!is_array($data['editions'] ?? null) || $data['editions'] === [] || !array_is_list($data['editions'])) {
            throw new FormMappingException("{$source}: needs a list of \"editions\"");
        }

        $editions = [];
        foreach ($data['editions'] as $edition) {
            if (!is_array($edition)) {
                throw new FormMappingException("{$source}: every edition must be an object");
            }
            $editions[] = FormEdition::fromArray($edition, $source);
        }
        usort($editions, static fn(FormEdition $a, FormEdition $b): int => $a->validFrom <=> $b->validFrom);
        for ($i = 1; $i < count($editions); $i++) {
            $before = $editions[$i - 1];
            if ($before->validTo === null || $before->validTo >= $editions[$i]->validFrom) {
                throw new FormMappingException("{$source}: editions '{$before->name}' and '{$editions[$i]->name}' overlap");
            }
        }

        return new self($data['country'], $editions);
    }

    /**
     * The edition of the form a settlement period is filed on: the one whose
     * validity holds the whole period.
     *
     * @throws FormMappingException no edition covers the period
     */
    public function edition(\DateTimeImmutable $from, \DateTimeImmutable $to): FormEdition
    {
        $fromDay = $from->format('Y-m-d');
        $toDay   = $to->format('Y-m-d');
        foreach ($this->editions as $edition) {
            if ($edition->covers($fromDay, $toDay)) {
                return $edition;
            }
        }

        throw new FormMappingException("No edition of the {$this->country} VAT return form covers {$fromDay} – {$toDay}");
    }
}
