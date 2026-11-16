<?php

namespace Z77\Module\Creditor\Recognition;

use Z77\Module\Mandator\Entities\Mandator;

/**
 * The installation's OWN company as a supplier invoice prints it in the recipient block — what
 * learning per creditor ({@see SenderFeatures}, owner 2026-11-16) must NEVER take for a feature of
 * the supplier: the mandator's name and street, the domains of its e-mail and website, its UID.
 * Pure data; `CreditorLearningService` builds it from module-mandator's record.
 */
final class OwnParty
{
    /**
     * @param list<string> $names   normalised ({@see SenderFeatures::normalize()}): the company name, the street line
     * @param list<string> $domains registrable domains (`z77.ch`)
     */
    public function __construct(public readonly array $names = [], public readonly array $domains = [], public readonly string $uid = '') {}

    public static function none(): self
    {
        return new self();
    }

    public static function fromMandator(?Mandator $mandator): self
    {
        if ($mandator === null) {
            return self::none();
        }
        $names = array_filter([
            SenderFeatures::normalize($mandator->getName()),
            SenderFeatures::normalize(trim($mandator->getStreet() . ' ' . $mandator->getHouseNo())),
        ], static fn(string $n): bool => mb_strlen($n) >= 4);
        $domains = [];
        $mail    = $mandator->getEmail();
        if (str_contains($mail, '@')) {
            $domains[] = SenderFeatures::registrableDomain(substr($mail, strrpos($mail, '@') + 1));
        }
        $site = (string) preg_replace('#^[a-z]+://#i', '', $mandator->getWebsite());
        if ($site !== '') {
            $domains[] = SenderFeatures::registrableDomain(explode('/', $site)[0]);
        }

        return new self(array_values(array_unique($names)), array_values(array_unique(array_filter($domains))), $mandator->getUid());
    }

    public function isOwnDomain(string $domain): bool
    {
        return in_array($domain, $this->domains, true);
    }

    /** Whether a normalised segment of the text belongs to the own company (it contains its name, street or postcode + town). */
    public function isOwnSegment(string $segment): bool
    {
        foreach ($this->names as $name) {
            if ($name !== '' && str_contains($segment, $name)) {
                return true;
            }
        }

        return false;
    }
}
