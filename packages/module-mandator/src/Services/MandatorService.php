<?php

namespace Z77\Module\Mandator\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Validators\MandatorValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of {@see Mandator} — the rules that hold no matter which
 * screen or script writes (owner decision E1, ADR-039 decision 9):
 *
 *   - there is ONE record, and the database enforces it: the id is fixed
 *     (`Mandator::ID`), so a second INSERT fails on the primary key. The
 *     existence check in {@see save()} only spares the round trip; under a
 *     race the primary key decides and the loser gets
 *     {@see MandatorAlreadyExistsException} (review 2026-09-23, P7). There
 *     is no delete — a mandator is corrected, not removed;
 *   - every write runs {@see MandatorValidator}, with the account check
 *     against module-financial when that module is registered
 *     ({@see LedgerAccountCheck});
 *   - **an UNCHANGED account keeps its number** even when the chart has
 *     since grouped or deactivated it — the reference rule of ADR-043
 *     decision 19 applied to the account fields (the `DebtorProfileValidator`
 *     / `ManualEntryForm` model): a NEW record and a CHANGED field must name
 *     a postable account, an unchanged one is left alone, so a letterhead
 *     correction never fails on a bookkeeping field (review 2026-09-23,
 *     P3a3). The screen still flags the field, the reader still refuses at
 *     the point of use;
 *   - **validate BEFORE mutating the managed entity** (the `ContactService` /
 *     `DebtorProfileService` model): a change arrives as VALUES
 *     ({@see update()}); they go onto a detached clone, the clone is
 *     validated, and only a valid one is applied to the managed entity. A
 *     refused change never reaches the next flush (DOCTRINE-TX-007).
 *
 * One `flush()` per operation is one Doctrine transaction (ADR-039
 * decision 10); nothing here needs the transaction port.
 */
final class MandatorService
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * Persist the FIRST and only record. Nothing is managed before
     * `persist()`, so a refusal leaves no trace in the EntityManager.
     *
     * @throws InvalidMandatorException        carries the validator and the record
     * @throws MandatorAlreadyExistsException  a row exists — before the write, or (a race) at the primary key
     * @throws MandatorUnavailableException    the module is not registered, or the table is missing
     */
    public function save(Mandator $mandator): void
    {
        if ((new CurrentMandator($this->em))->exists()) {
            throw new MandatorAlreadyExistsException();
        }
        $this->assertValid($mandator, checkAccounts: array_keys(Mandator::ACCOUNT_KEYS));

        $this->em->persist($mandator);
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // The race: another first save committed between exists() and this flush. After the
            // failed flush the EntityManager is replaced (DOCTRINE-TX-004); the record is detached.
            throw new MandatorAlreadyExistsException($e);
        }
    }

    /**
     * Change the EXISTING record: $values (snake_case keys as the body
     * cleaner produces them) go onto a detached draft first; only a valid
     * draft is applied to the managed entity and flushed. Account fields
     * are checked against the bookkeeping only where the number CHANGED.
     *
     * @param array<string, mixed> $values
     * @throws InvalidMandatorException carries the validator and the DRAFT (for re-rendering the form)
     */
    public function update(Mandator $mandator, array $values): void
    {
        unset($values['id']);

        $draft = clone $mandator;
        $draft->mapFromArray($values);
        $this->assertValid($draft, checkAccounts: self::changedAccounts($mandator, $draft));

        $mandator->mapFromArray($values);
        $this->em->persist($mandator);
        $this->em->flush();
    }

    /**
     * The validator as the write path runs it — null = every account is
     * checked against the bookkeeping (a NEW record, or a caller that wants
     * to know what BECAME invalid). The screen hands it to the template
     * un-run for the field-error slots; the «prüfen» flag comes from
     * {@see MandatorAccounts::status()}.
     */
    public function validator(Mandator $mandator, ?array $checkAccounts = null): MandatorValidator
    {
        return new MandatorValidator($mandator, new LedgerAccountCheck($this->em), $checkAccounts ?? array_keys(Mandator::ACCOUNT_KEYS));
    }

    /** @return list<string> the account keys whose number differs between the stored record and the draft */
    private static function changedAccounts(Mandator $stored, Mandator $draft): array
    {
        return array_values(array_filter(
            array_keys(Mandator::ACCOUNT_KEYS),
            fn(string $key) => $draft->account($key) !== $stored->account($key)
        ));
    }

    /** @throws InvalidMandatorException */
    private function assertValid(Mandator $mandator, array $checkAccounts): void
    {
        $validator = $this->validator($mandator, $checkAccounts);
        if (!$validator->isValid()) {
            throw new InvalidMandatorException($validator, $mandator);
        }
    }
}
