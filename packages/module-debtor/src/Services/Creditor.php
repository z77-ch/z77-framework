<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\PaymentTarget;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The EFFECTIVE creditor block of a payment target — the account holder a
 * QR-bill names (owner 2026-09-23, `debtor.md`): each field from the target,
 * and where the target leaves it empty, from the mandator's address — FIELD
 * BY FIELD (wdv-630's `picDepositor*()` model; the normal case is that the
 * holder is the company and nobody wants the address twice). The country
 * follows the same rule: it belongs to the HOLDER (wdv read it from the
 * mandator unconditionally — an account held abroad printed the wrong
 * country; not taken over).
 *
 * Why a value of its own: the invoice SNAPSHOTS this block at issue
 * (`PaymentSnapshot`), and the payment-target screen SHOWS it, so the name
 * that lands on the bill is visible before printing — `fromMandator` says
 * which fields came from the fallback.
 *
 * No length check here: the target's own fields are validated at save; a
 * fallback value longer than the QR field (the mandator's letterhead fields
 * are longer) is reported by `QrBill` as a problem of the printed bill,
 * never truncated.
 */
final class Creditor
{
    /** @param list<string> $fromMandator the field names (`name`, `street`, …) taken from the mandator */
    public function __construct(
        public readonly string $name,
        public readonly string $street,
        public readonly string $houseNo,
        public readonly string $zip,
        public readonly string $city,
        public readonly string $country,
        public readonly array $fromMandator,
    ) {}

    public static function of(PaymentTarget $target, ?Mandator $mandator): self
    {
        $fields = [
            'name'     => [$target->getHolderName(), $mandator?->getName()],
            'street'   => [$target->getHolderStreet(), $mandator?->getStreet()],
            'house_no' => [$target->getHolderHouseNo(), $mandator?->getHouseNo()],
            'zip'      => [$target->getHolderZip(), $mandator?->getZip()],
            'city'     => [$target->getHolderCity(), $mandator?->getCity()],
            'country'  => [$target->getHolderCountry(), $mandator?->getCountry()],
        ];
        $value = [];
        $fallback = [];
        foreach ($fields as $key => [$own, $mandatorValue]) {
            if ($own !== '') {
                $value[$key] = $own;
            } else {
                $value[$key] = trim((string) $mandatorValue);
                if ($value[$key] !== '') {
                    $fallback[] = $key;
                }
            }
        }

        return new self($value['name'], $value['street'], $value['house_no'], $value['zip'], $value['city'], mb_strtoupper($value['country']), $fallback);
    }

    /**
     * The mandator record the fallback reads, or null — none saved yet, or
     * the record cannot be read (module not registered, table missing). A
     * creditor block without the fallback is still a block; what is missing
     * is reported by `QrBill`, never a 500 here.
     */
    public static function mandator(UnifiedEntityManager $em): ?Mandator
    {
        try {
            return (new CurrentMandator($em))->find();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }
}
