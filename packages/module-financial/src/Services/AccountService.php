<?php

namespace Z77\Module\Financial\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Repositories\AccountRepository;
use Z77\Module\Financial\Validators\AccountValidator;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * The write side of the chart of accounts (plan §5.1) — the rules that hold
 * no matter which screen or script writes:
 *
 *   - an account is never deleted, only deactivated: there is no method for
 *     it — journal lines reference it (P2 part 2);
 *   - its number is fixed once it exists ({@see AccountNumberChangedException});
 *   - every write runs {@see AccountValidator}, so an import is bound to the
 *     same rules as the backend;
 *   - the KMU chart is adopted only into an EMPTY chart
 *     ({@see adoptKmuChart()}, owner 2026-09-22).
 *
 * Validate BEFORE mutating a managed entity (ADR-039 decision 9, flush
 * scope — the `ContactService` model): a change to an existing account
 * arrives as VALUES ({@see update()}); they are applied to a detached
 * clone, the clone is validated, and only then are they applied to the
 * managed entity. A NEW account is not managed until `persist()`, so
 * {@see save()} validates it as it is. One `flush()` per operation is one
 * Doctrine transaction — the chart adoption included.
 */
final class AccountService
{
    /** The Swiss SME chart (KMU-Kontenrahmen) shipped with the package, UTF-8 without BOM. */
    public const KMU_CHART_FILE = __DIR__ . '/../../res/charts/kmu.json';

    public function __construct(private readonly UnifiedEntityManager $em) {}

    /**
     * Persist a NEW account. Nothing here is managed before `persist()`, so
     * a refusal leaves no trace in the EntityManager.
     *
     * @throws InvalidAccountException carries the validator and the account
     * @throws \LogicException the account already exists — use {@see update()}
     */
    public function save(Account $account): void
    {
        if ($account->getId() !== null) {
            throw new \LogicException('save() takes a NEW account — an existing one is changed through update($account, $values)');
        }
        $this->assertValid($account);

        $this->em->persist($account);
        $this->flushOrRefuse($account);
    }

    /**
     * Change an EXISTING account: $values (snake_case keys as the body
     * cleaner produces them; the parent as the entity under `parent`) go
     * onto a detached draft first; only a valid draft is applied to the
     * managed entity and flushed. `number` may be passed only unchanged.
     *
     * @param array<string, mixed> $values
     * @throws InvalidAccountException carries the validator and the draft (for re-rendering the form)
     * @throws AccountNumberChangedException $values carry a different number
     */
    public function update(Account $account, array $values): void
    {
        if ($account->getId() === null) {
            throw new \LogicException('update() takes an existing account — a new one goes through save()');
        }
        if (array_key_exists('number', $values) && trim((string) $values['number']) !== $account->getNumber()) {
            throw new AccountNumberChangedException($account->getNumber(), trim((string) $values['number']));
        }
        $draft = clone $account;
        $draft->mapFromArray($values);
        $this->assertValid($draft);

        $account->mapFromArray($values);
        $this->em->persist($account);
        $this->flushOrRefuse($account);
    }

    public function setActive(Account $account, bool $active): void
    {
        $this->update($account, ['active' => $active]);
    }

    /**
     * «KMU-Kontenrahmen übernehmen»: every account of {@see KMU_CHART_FILE}
     * — classes, main groups, groups and the common postable accounts — in
     * ONE flush, only while the chart is empty. Every row passes the
     * validator before anything is persisted; a broken resource file is a
     * programming error and fails loudly. Two adoptions racing each other
     * collide on the unique number and the second writes nothing.
     *
     * Deliberately a button, not an installer seed or a migration (owner,
     * 2026-09-22): an installation that migrates its own chart (P5b) must
     * not get this one forced on it.
     *
     * @return int how many accounts were created
     * @throws ChartNotEmptyException the chart already has an account
     */
    public function adoptKmuChart(): int
    {
        $repository = $this->accounts();
        if (!$repository->isEmpty()) {
            throw new ChartNotEmptyException();
        }

        $byNumber = [];
        foreach (self::readChart(self::KMU_CHART_FILE) as $i => $row) {
            $number = (string) ($row['number'] ?? '');
            $parent = $row['parent'] ?? null;
            if (isset($byNumber[$number])) {
                throw new \UnexpectedValueException("KMU chart row {$i}: account {$number} appears twice");
            }
            if ($parent !== null && !isset($byNumber[(string) $parent])) {
                throw new \UnexpectedValueException("KMU chart row {$i}: parent {$parent} of {$number} must come before it");
            }
            $account = new Account([
                'number'   => $number,
                'name'     => (string) ($row['name'] ?? ''),
                'type'     => (string) ($row['type'] ?? ''),
                'postable' => (bool) ($row['postable'] ?? false),
                'parent'   => $parent === null ? null : $byNumber[(string) $parent],
            ]);
            $this->assertValid($account);
            $byNumber[$number] = $account;
        }

        foreach ($byNumber as $account) {
            $this->em->persist($account);
        }
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (str_contains($e->getMessage(), Account::UNIQUE_NUMBER)) {
                throw new ChartNotEmptyException();
            }
            throw $e;
        }

        return count($byNumber);
    }

    /** @return list<array<string, mixed>> the rows of a chart resource file, parents before children */
    private static function readChart(string $file): array
    {
        $json = @file_get_contents($file);
        if ($json === false) {
            throw new \RuntimeException("Chart of accounts not readable: {$file}");
        }
        $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \UnexpectedValueException("Chart of accounts must be a JSON list of accounts: {$file}");
        }

        return $rows;
    }

    private function accounts(): AccountRepository
    {
        return $this->em->getRepository(Account::class);
    }

    /** @throws InvalidAccountException */
    private function assertValid(Account $account): void
    {
        $validator = new AccountValidator($account, $this->accounts());
        if (!$validator->isValid()) {
            throw new InvalidAccountException($validator, $account);
        }
    }

    /**
     * The flush, with the unique number turned into the field error the
     * validator would have given: two requests can pass the validator with
     * the same new number, and only the database sees the race. After a
     * failed flush the EntityManager has been replaced (DOCTRINE-TX-004);
     * the account handed in is detached and serves the form only.
     *
     * @throws InvalidAccountException
     */
    private function flushOrRefuse(Account $account): void
    {
        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            if (!str_contains($e->getMessage(), Account::UNIQUE_NUMBER)) {
                throw $e;
            }
            $validator = new AccountValidator($account);
            $validator->flagFieldError('number', 'Konto ' . $account->getNumber() . ' gibt es bereits.');
            throw new InvalidAccountException($validator, $account);
        }
    }
}
