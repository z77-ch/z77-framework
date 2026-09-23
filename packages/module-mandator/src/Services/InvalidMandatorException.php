<?php

namespace Z77\Module\Mandator\Services;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Validators\MandatorValidator;

/**
 * The record did not pass {@see MandatorValidator}. Carries the validator
 * (field errors for the form) and the DRAFT that was refused — on an update
 * that is the detached clone, never the managed entity (ADR-039 decision 9).
 */
final class InvalidMandatorException extends \RuntimeException
{
    public function __construct(
        public readonly MandatorValidator $validator,
        public readonly Mandator $mandator,
    ) {
        parent::__construct('Mandator refused: ' . implode(' ', array_merge($validator->getErrors(), $validator->getFieldErrors())));
    }
}
