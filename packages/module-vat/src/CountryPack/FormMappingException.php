<?php

namespace Z77\Module\Vat\CountryPack;

use Z77\Module\Vat\Services\VatException;

/**
 * A country pack's rule file cannot be used: missing, not valid JSON, a rule
 * or field that contradicts the form's structure, or no edition of the form
 * covers the settlement period. Loud on purpose — a return computed from a
 * half-read mapping would put turnover into the wrong field silently.
 */
class FormMappingException extends VatException
{
}
