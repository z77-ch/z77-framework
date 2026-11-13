<?php

namespace Z77\Module\Mandator\Mail;

use Z77\Core\DI;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Shared\Mail\MailCompany;
use Z77\Shared\Mail\MailCompanySource;

/**
 * The company at the foot of every template mail (owner decision 2026-11-13, board
 * «E-Mail-Standard»): the mandator — its full name (Art. 954a OR), the address on one line
 * (address suffixes, street + number, zip + city, the country when not CH), phone · e-mail ·
 * website on the next. Only the filled parts appear; no mandator record → no foot. Registered in
 * this module's config under `mailCompany`; kernel `EmailService` asks it when it renders a body.
 */
final class MandatorMailCompany implements MailCompanySource
{
    public function company(): ?MailCompany
    {
        $mandator = (new CurrentMandator(DI::getUnifiedEntityManager()))->find();
        if ($mandator === null) {
            return null;
        }
        $country = strtoupper(trim($mandator->getCountry()));

        return new MailCompany(
            trim($mandator->getName()),
            MailCompany::joinLine(
                $mandator->getAddressSuffixOne(),
                $mandator->getAddressSuffixTwo(),
                $mandator->getStreet() . ' ' . $mandator->getHouseNo(),
                $mandator->getZip() . ' ' . $mandator->getCity(),
                $country !== '' && $country !== 'CH' ? $country : '',
            ),
            MailCompany::joinLine($mandator->getPhone(), $mandator->getEmail(), $mandator->getWebsite()),
        );
    }
}
