<?php

namespace Z77\Module\Financial\Validators;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a NEW {@see FiscalYear} (part 1 has no edit of a year). The
 * rules, each for a reason (owner decisions 2026-09-22):
 *
 *   - code: required, lower-case kebab (`2026`, `2026-27`), at most
 *     {@see FiscalYear::CODE_LENGTH} — it becomes the dotted segment of the
 *     range name `journal-entry.{code}` (`persistence-doctrine.md`); unique;
 *   - start and end required, end AFTER start, at most 24 months — the
 *     longest an extended first year runs in practice;
 *   - contiguous: a new year starts the day after the latest year ends
 *     (no gap, no overlap — every date belongs to exactly one year); the
 *     first year may start anywhere.
 *
 * Without a repository only the year itself is checked (format, dates).
 */
class FiscalYearValidator extends EntityValidator
{
    /** The longest a fiscal year may run. */
    public const MAX_MONTHS = 24;

    public function __construct(FiscalYear $year, private ?FiscalYearRepository $years = null)
    {
        parent::__construct($year);
    }

    /** A conflict only the database could see (a unique index under a race). */
    public function flagFieldError(string $field, string $message): void
    {
        $this->addFieldError($field, $message);
    }

    public function validateCode(string $code): void
    {
        // The code is proposed from the dates when left empty. Without both
        // dates there is nothing to propose from, and the date errors already
        // say what to fix — an extra «Kürzel ist ein Pflichtfeld» would send
        // the user to the wrong field. The year stays refused either way.
        if ($code === '' && ($this->entity->getStartDate() === null || $this->entity->getEndDate() === null)) {
            return;
        }
        $this->validate('code', 'Kürzel', $code)
            ->notEmpty()
            ->maxLength(FiscalYear::CODE_LENGTH);

        if ($this->hasFieldError('code')) {
            return;
        }
        if (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $code)) {
            $this->addFieldError('code', 'Kürzel aus Kleinbuchstaben, Ziffern und einzelnen Bindestrichen (z.B. 2026 oder 2026-27).');
            return;
        }
        if ($this->years?->findOneBy(['code' => $code]) !== null) {
            $this->addFieldError('code', 'Das Kürzel «' . $code . '» ist bereits vergeben.');
        }
    }

    public function validateStartDate(?\DateTimeImmutable $start): void
    {
        if ($start === null) {
            $this->addFieldError('start_date', 'Beginn ist ein Pflichtfeld (gültiges Datum).');
            return;
        }
        $latest = $this->years?->latest();
        if ($latest === null) {
            return;
        }
        $expected = $latest->getEndDate()->modify('+1 day');
        if ($start->format('Y-m-d') !== $expected->format('Y-m-d')) {
            $this->addFieldError('start_date', 'Ein neues Geschäftsjahr beginnt am Tag nach dem Ende von «' . $latest->getCode()
                . '» — am ' . $expected->format('d.m.Y') . '.');
        }
    }

    public function validateEndDate(?\DateTimeImmutable $end): void
    {
        if ($end === null) {
            $this->addFieldError('end_date', 'Ende ist ein Pflichtfeld (gültiges Datum).');
            return;
        }
        $start = $this->entity->getStartDate();
        if ($start === null) {
            return;
        }
        if ($end <= $start) {
            $this->addFieldError('end_date', 'Das Ende liegt nach dem Beginn.');
            return;
        }
        $latestEnd = $start->modify('+' . self::MAX_MONTHS . ' months')->modify('-1 day');
        if ($end > $latestEnd) {
            $this->addFieldError('end_date', 'Ein Geschäftsjahr dauert höchstens ' . self::MAX_MONTHS . ' Monate — spätestens bis ' . $latestEnd->format('d.m.Y') . '.');
        }
    }
}
