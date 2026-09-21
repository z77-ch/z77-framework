<?php

namespace Z77\Module\Contact\Entities;

/**
 * What a {@see Contact} is (plan §4a: «person or company»). Domain only, no
 * labels — German display text lives in the UI layer, like `TaxCategory`.
 * The kind decides which name part is required: a person has a last name, an
 * organisation a company name.
 */
enum ContactKind: string
{
    case Person       = 'person';
    case Organisation = 'organisation';
}
