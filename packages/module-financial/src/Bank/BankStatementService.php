<?php

namespace Z77\Module\Financial\Bank;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Entities\BankStatement;
use Z77\Module\Financial\Repositories\BankAccountRepository;
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Repositories\BankStatementRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;

/**
 * The camt.053 import of the bank account service (ADR-060, stage B1): a statement file becomes
 * {@see BankStatement}s with their {@see BankMovement}s, and the file goes to the archive. Nothing
 * is posted — the movements are an information source for the creditor journal (decision 1).
 *
 * THE CHAIN IS SEAMLESS, ALWAYS (ADR-060 decision 18, owner 2026-11-16) — the statement is the
 * source of truth, so what is stored for a bank account is one period without a hole:
 *
 *   - a statement must be consistent in itself — opening balance + booked entries = closing
 *     balance ({@see Camt053Reader} refuses it otherwise);
 *   - the FIRST statement of a bank account sets the start. From then on the account is
 *     «eingelesen bis D · Saldo C»: D the end date of the newest statement (the date of
 *     `FrToDt/ToDtTm`), C its closing balance;
 *   - a file that starts AFTER the day following D leaves a gap → refused (`gap`), with both dates;
 *   - a file that starts on or before that day OVERLAPS or continues. Its entries the account
 *     already holds are recognised by the bank's entry reference (`AcctSvcrRef`, else the
 *     fingerprint) and are NOT stored again; the rest are the new movements. The proof that the
 *     overlap is identical to what is stored is the balance: C + new movements must be the file's
 *     closing balance — which, the file being consistent in itself, is the same as «the file's
 *     balance at the covered end equals C». Anything else → refused (`chain-mismatch`), nothing
 *     stored;
 *   - the cut is by REFERENCE, not by a date comparison, on purpose: an export made during day D
 *     ends its period on D, and the bank may book further entries on D afterwards. They carry new
 *     references, so the next file brings them as new, and the balance proves the join. A date
 *     cut would lose them or refuse the file for ever;
 *   - a file that ends on or before D and brings nothing new adds nothing: no statement, no
 *     file in the archive — the result says so. A file that brings a movement from before the
 *     account's start is refused (`before-start`): nothing is inserted in front of the chain.
 *
 * Also refused, each with a German sentence and nothing stored: the same FILE again (SHA-256);
 * the same bank message for the account (`MsgId` + `Stmt/Id`); an IBAN that is no ACTIVE bank
 * account; «Kontoauszug einlesen» switched off (owner decision 17); another currency than the
 * base currency (decision 8).
 *
 * Every new movement gets the account's balance after it (`bank_movement.balance`): the chain is
 * seamless, so it is C plus the new movements in file order.
 *
 * The file is archived unaltered ({@see BankStatementArchive}) inside the unit of work — a
 * statement row only commits together with its file, and a rollback removes the file again. All
 * statements of a file are stored in ONE unit of work, or none.
 *
 * Lock order: the bank account rows of the file (by id) FIRST — two imports for one account
 * serialise, and the second judges the chain the first left — then the reads, then the writes.
 * Nothing is read inside the unit of work before the lock.
 */
final class BankStatementService
{
    private readonly string $actor;

    /**
     * @param string|null $actor    who imports; null = the logged-in backend user
     * @param string|null $basePath the installation root for the archive; null = `ABS_BASE_PATH`
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null, private readonly ?string $basePath = null)
    {
        $this->actor = $actor === null ? Actor::current() : Actor::normalize($actor);
    }

    /**
     * Reads a camt.053 file.
     *
     * @return list<BankImportResult> one per statement of the file, in file order
     * @throws BankRefusedException NOT_XML | NOT_CAMT053 | INCOMPLETE | DUPLICATE_FILE | DUPLICATE_MESSAGE
     *                              | ACCOUNT_UNKNOWN | IMPORT_OFF | FOREIGN_CURRENCY | GAP | CHAIN_MISMATCH
     *                              | BEFORE_START | ARCHIVE
     */
    public function import(string $bytes, string $fileName): array
    {
        $sha256   = hash('sha256', $bytes);
        $existing = $this->statements()->findBySha256($sha256);
        if ($existing !== null) {
            throw new BankRefusedException(
                BankRefusedException::DUPLICATE_FILE,
                'Diese Datei wurde bereits am ' . $existing->getImportedAt()->format('d.m.Y H:i') . ' eingelesen (' . $existing->getFileName() . ').'
            );
        }

        $data     = Camt053Reader::read($bytes);
        $accounts = [];
        foreach ($data as $i => $statement) {
            $accounts[$i] = $this->accountFor($statement);
            $earlier      = $this->statements()->findByMessage((int) $accounts[$i]->getId(), $statement->messageId, $statement->statementId);
            if ($earlier !== null) {
                throw self::duplicateMessage($earlier->getImportedAt()->format('d.m.Y H:i') . ' eingelesen (' . $earlier->getFileName() . ')');
            }
        }
        $lockIds = array_values(array_unique(array_map(static fn(BankAccount $a) => (int) $a->getId(), $accounts)));
        sort($lockIds);
        $archive = new BankStatementArchive($this->basePath);
        $file    = [
            'bytes'   => $bytes,
            'sha256'  => $sha256,
            'name'    => $fileName,
            'closing' => max(array_map(static fn(StatementData $s) => $s->closingDate, $data)),
            'code'    => $accounts[0]->getCode(),
            'now'     => new \DateTimeImmutable(),
            'path'    => null,
        ];

        try {
            return $this->em->getTransaction(BankStatement::class)->run(function () use ($data, $accounts, $lockIds, $archive, &$file): array {
                foreach ($lockIds as $id) {
                    $this->bankAccounts()->lockRow($id);   // the FIRST locks — see the class docblock
                }
                $results = [];
                foreach ($data as $i => $statement) {
                    $results[] = $this->join($accounts[$i], $statement, $archive, $file);
                    $this->em->flush();   // the next statement of the file joins this one
                }

                return $results;
            });
        } catch (UniqueConstraintViolationException $e) {
            $archive->discardWritten();
            throw self::duplicateMessage('soeben von jemand anderem eingelesen', $e);
        } catch (\Throwable $e) {
            $archive->discardWritten();
            throw $e;
        }
    }

    /**
     * Where the bank account stands: the newest statement — its `getToDate()` is «eingelesen bis»,
     * its `getClosingBalance()` the balance there — or null before the first import.
     */
    public function coveredTo(BankAccount $account): ?BankStatement
    {
        return $this->statements()->latestOf((int) $account->getId());
    }

    /** @return list<BankStatement> the statements of the bank account, the newest first */
    public function statementsOf(BankAccount $account): array
    {
        return $this->statements()->ofAccount((int) $account->getId());
    }

    // ── one statement against the chain ─────────────────────────────────

    /**
     * Judges $data against the account's chain and stores it when it joins — see the class docblock.
     *
     * @param array{bytes: string, sha256: string, name: string, closing: \DateTimeImmutable, code: string, now: \DateTimeImmutable, path: string|null} $file
     * @throws BankRefusedException GAP | CHAIN_MISMATCH | BEFORE_START | ARCHIVE
     */
    private function join(BankAccount $account, StatementData $data, BankStatementArchive $archive, array &$file): BankImportResult
    {
        $currency  = $data->currency;
        $accountId = (int) $account->getId();
        $opening   = Money::fromDecimal($data->openingBalance, $currency);
        $closing   = Money::fromDecimal($data->closingBalance, $currency);
        $latest    = $this->statements()->latestOf($accountId);
        $known     = $this->movements()->knownKeys($accountId, array_map(
            static fn(MovementData $m) => BankMovement::dedupKey($m->entryRef, $m->fingerprint),
            $data->movements
        ));
        $new = array_values(array_filter(
            $data->movements,
            static fn(MovementData $m) => !isset($known[BankMovement::dedupKey($m->entryRef, $m->fingerprint)])
        ));
        $duplicates = count($data->movements) - count($new);
        $added      = Money::zero($currency);
        foreach ($new as $movement) {
            $amount = Money::fromDecimal($movement->amount, $currency);
            $added  = $movement->isCredit ? $added->add($amount) : $added->subtract($amount);
        }

        $balance = $opening;   // the first statement of the account sets the start
        if ($latest !== null) {
            $coveredTo = $latest->getToDate();
            $stored    = $latest->getClosingBalance();
            $label     = 'Das Bankkonto «' . $account->getLabel() . '» ist eingelesen bis ' . $coveredTo->format('d.m.Y');
            if ($data->from > $coveredTo->modify('+1 day')) {
                throw new BankRefusedException(BankRefusedException::GAP, $label . ', die Datei beginnt am ' . $data->from->format('d.m.Y')
                    . ' — dazwischen fehlen Bewegungen. Nichts eingelesen: bitte einen Auszug laden, der spätestens am ' . $coveredTo->modify('+1 day')->format('d.m.Y') . ' beginnt.');
            }
            if ($new === [] && $data->to <= $coveredTo) {
                return new BankImportResult($account, null, 0, $duplicates, $data->skipped, $coveredTo, $stored);
            }
            $start = $this->statements()->firstOf($accountId)?->getFromDate();
            foreach ($new as $movement) {
                if ($start !== null && $movement->bookingDate < $start) {
                    throw new BankRefusedException(BankRefusedException::BEFORE_START, 'Die Datei enthält Bewegungen vor dem Beginn der eingelesenen Auszüge ('
                        . $start->format('d.m.Y') . ') — ältere Bewegungen lassen sich nicht nachträglich davor einfügen. Nichts eingelesen.');
                }
            }
            if ($data->to < $coveredTo || !$stored->add($added)->equals($closing)) {
                throw new BankRefusedException(BankRefusedException::CHAIN_MISMATCH, $label . ' mit Saldo ' . AmountFormat::of($stored)
                    . '. Die Datei schliesst daran nicht an: mit ihren ' . count($new) . ' neuen Bewegungen ergäbe das ' . AmountFormat::of($stored->add($added))
                    . ', sie schliesst aber am ' . $data->to->format('d.m.Y') . ' mit ' . AmountFormat::of($closing)
                    . '. Nichts eingelesen — bitte den Auszug neu aus dem E-Banking laden, beginnend am ' . $coveredTo->format('d.m.Y') . ' oder früher.');
            }
            $balance = $stored;
        }

        if ($file['path'] === null) {
            try {
                $file['path'] = $archive->store($file['bytes'], $file['sha256'], $file['closing'], $file['code']);
            } catch (\RuntimeException $e) {
                error_log('financial bank import: ' . $e->getMessage());
                throw new BankRefusedException(BankRefusedException::ARCHIVE, 'Nichts eingelesen: das Archiv konnte nicht geschrieben werden (' . BankStatementArchive::DIR . '). Die Ursache steht im Fehlerprotokoll.', $e);
            }
        }
        $statement = new BankStatement(
            $account, $data->messageId, $data->statementId, $data->electronicSequence, $data->legalSequence,
            $data->from, $data->to, $opening, $data->openingDate, $closing, $data->closingDate, $data->createdOn,
            $file['name'], $file['sha256'], $file['path'], $this->actor, $file['now'],
        );
        $statement->record(count($new), $duplicates);
        $this->em->persist($statement);
        foreach ($new as $movement) {
            $amount  = Money::fromDecimal($movement->amount, $currency);
            $balance = $movement->isCredit ? $balance->add($amount) : $balance->subtract($amount);
            $this->em->persist(new BankMovement(
                $account, $statement, $movement->position, $movement->entryRef, $movement->fingerprint,
                $movement->isCredit, $movement->isReversal, $movement->bookingDate, $movement->valueDate,
                $amount, $balance,
                $movement->originalAmount, $movement->originalCurrency, $movement->exchangeRate,
                $movement->exchangeRate !== null ? BankMovement::RATE_STRUCTURED : null,
                $movement->bankTxCode, $movement->counterpartyName, $movement->counterpartyIban,
                $movement->referenceType, $movement->reference, $movement->text, $movement->txCount,
            ));
        }

        return new BankImportResult($account, $statement, count($new), $duplicates, $data->skipped, $data->to, $closing);
    }

    /** @throws BankRefusedException ACCOUNT_UNKNOWN | IMPORT_OFF | FOREIGN_CURRENCY */
    private function accountFor(StatementData $statement): BankAccount
    {
        $named   = $statement->iban !== '' ? Iban::format($statement->iban) : '(ohne IBAN)';
        $account = $this->bankAccounts()->findByIban($statement->iban);
        if ($account === null || !$account->isActive()) {
            throw new BankRefusedException(
                BankRefusedException::ACCOUNT_UNKNOWN,
                'Das Konto ' . $named . ' des Kontoauszugs ist kein aktives Bankkonto — zuerst unter Stammdaten › Finanzen › Bankkonten anlegen.'
            );
        }
        if (!$account->isStatementImport()) {
            throw new BankRefusedException(
                BankRefusedException::IMPORT_OFF,
                'Für das Bankkonto «' . $account->getLabel() . '» ist «Kontoauszug einlesen» ausgeschaltet — nichts eingelesen. Der Schalter steht unter Stammdaten › Finanzen › Bankkonten.'
            );
        }
        $base = LedgerService::baseCurrency();
        if ($statement->currency !== $base || $account->getCurrency() !== $base) {
            throw new BankRefusedException(
                BankRefusedException::FOREIGN_CURRENCY,
                'Der Kontoauszug lautet auf ' . $statement->currency . ' — die Buchhaltung läuft in ' . $base
                . '. Fremdwährungskonten können in dieser Ausbaustufe nicht eingelesen werden.'
            );
        }

        return $account;
    }

    private static function duplicateMessage(string $when, ?\Throwable $previous = null): BankRefusedException
    {
        return new BankRefusedException(BankRefusedException::DUPLICATE_MESSAGE, 'Dieser Kontoauszug wurde bereits ' . $when . '.', $previous);
    }

    private function bankAccounts(): BankAccountRepository
    {
        return $this->em->getRepository(BankAccount::class);
    }

    private function statements(): BankStatementRepository
    {
        return $this->em->getRepository(BankStatement::class);
    }

    private function movements(): BankMovementRepository
    {
        return $this->em->getRepository(BankMovement::class);
    }
}
