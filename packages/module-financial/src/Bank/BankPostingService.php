<?php

namespace Z77\Module\Financial\Bank;

use Z77\Core\DI;
use Z77\Module\Financial\Entities\Account;
use Z77\Module\Financial\Entities\BankLink;
use Z77\Module\Financial\Entities\BankMovement;
use Z77\Module\Financial\Entities\BankPosting;
use Z77\Module\Financial\Entities\JournalEntry;
use Z77\Module\Financial\Ledger\EntryRef;
use Z77\Module\Financial\Ledger\GrossPart;
use Z77\Module\Financial\Ledger\PostingLine;
use Z77\Module\Financial\Ledger\PostingRequest;
use Z77\Module\Financial\Repositories\BankMovementRepository;
use Z77\Module\Financial\Repositories\BankPostingRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\GrossEntryBuilder;
use Z77\Module\Financial\Services\GrossEntryRefusedException;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Services\PostingRefusedException;
use Z77\Module\Financial\Services\ReversalRefusedException;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;

/**
 * «Geschäftlich buchen …» and «Buchung zurücknehmen …» (ADR-061 decisions 14–21, stage C2):
 * a ledger entry made FROM a bank movement by an explicit act of the office — a movement never
 * posts by itself.
 *
 * {@see post()} — ONE unit of work:
 *
 *   1. the entry is built before anything is written (`GrossEntryBuilder`, the one VAT split of
 *      the module): the movement's amount is the GROSS, dated its booking date; a DEBIT books
 *      «contra an Bank», a CREDIT «Bank an contra»; the bank side is the ledger account of the
 *      movement's bank account (`BankAccount`, the one source). The contra is an account the
 *      office names, or one of two named ones from the config ({@see KIND_PRIVATE} — Privatbezug
 *      / Privateinlage; {@see KIND_TRANSFER} — the transfer clearing account between two business
 *      accounts, decision 17), both always without a tax code;
 *   2. THE ATTACHMENT RULE (decision 15): with a tax code and a gross of at least
 *      {@see voucherFromGross()} a file must come with it, else nothing is saved; below it, and
 *      without a tax code, a file is voluntary. Without a tax code the archived camt.053 is the
 *      voucher (decision 16) — the entry's origin names movement and statement file;
 *   3. a `BankPosting` row (the owner), the LINK through `BankLinkService::link()` — the same
 *      port every owning module uses; it locks the movement row and refuses a movement that is
 *      linked already —, the pattern (`BankMovementStates::booked()`: the proposal learned,
 *      decision 8 asked), the generated entry through `LedgerService::post()` (source
 *      `bank`, one idempotency key per posting row), the file into the write-once archive
 *      ({@see BankVoucherArchive}).
 *
 * {@see takeBack()} (decision 19) — the reversing entry on the entry's own date (the ledger's
 * period rules decide: a closed year refuses, a VAT-settled period refuses an entry with a tax
 * line — decision 21, each a German sentence), the link released in the same unit of work
 * (decision 10), the movement held on «zu prüfen». The posting row and its voucher stay.
 *
 * Refusals: {@see BankPostingRefusedException} (the form's fields) and {@see BankRefusedException}
 * (the link, the pattern question) — German, nothing written.
 */
final class BankPostingService
{
    /** The contra is an account the office names. */
    public const KIND_ACCOUNT  = 'account';
    /** The contra is the configured private account: a debit is a Privatbezug, a credit a Privateinlage. */
    public const KIND_PRIVATE  = 'private';
    /** The contra is the configured transfer clearing account (a transfer between two business accounts). */
    public const KIND_TRANSFER = 'transfer';
    public const KINDS = [self::KIND_ACCOUNT, self::KIND_PRIVATE, self::KIND_TRANSFER];

    /** A voucher is a scan or a photo — larger files are refused. */
    public const VOUCHER_MAX_BYTES = 10 * 1024 * 1024;

    private ?string $actor;

    /**
     * @param string|null $actor    who books; null = the logged-in backend user
     * @param string|null $basePath the installation root for the voucher archive; null = `ABS_BASE_PATH`
     */
    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null, private readonly ?string $basePath = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    // ── the settings (financialConfig `bankPosting` and the mandator's two accounts — read HERE) ──

    /**
     * From which gross amount a posting with a tax code needs a file (decision 15) —
     * `bankPosting.voucherFromGross`. No fallback in code: the config is the one source (Rule 2).
     *
     * @throws \UnexpectedValueException the key is missing or no decimal string — e.g. a project override that
     *                                   replaces financialConfig without it (BOOT-CONFIG-001)
     */
    public static function voucherFromGross(): Money
    {
        $value = self::setting('voucherFromGross');
        if (!is_string($value) || preg_match('/^\d+\.\d{2}$/', $value) !== 1) {
            throw new \UnexpectedValueException('financialConfig: bankPosting.voucherFromGross must be a decimal string like \'100.00\', got ' . var_export($value, true) . '.');
        }

        return Money::fromDecimal($value, LedgerService::baseCurrency());
    }

    /**
     * The account number behind {@see KIND_PRIVATE} / {@see KIND_TRANSFER} — the mandator's
     * accounts `private` / `bank-transfer` (Stammdaten › Firma › Mandant, Reiter «Konten»; stage
     * C4 — in `financialConfig` for stage C2 only), through the one reader
     * `LedgerService::bankPostingAccount()`; '' = not set (or no mandator record to read).
     */
    public function namedAccount(string $kind): string
    {
        try {
            return (string) (new LedgerService($this->em))->bankPostingAccount($kind === self::KIND_TRANSFER ? LedgerService::BANK_TRANSFER_ACCOUNT : LedgerService::BANK_PRIVATE_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return '';
        }
    }

    private static function setting(string $key): mixed
    {
        $settings = DI::getModuleManager()->getModuleConfig('financial')?->get('bankPosting');

        return is_array($settings) ? ($settings[$key] ?? null) : null;
    }

    // ── reads ────────────────────────────────────────────────────────────

    /** The posting that books movement $id now, or null (never booked directly, or taken back). */
    public function currentOf(int $movementId): ?BankPosting
    {
        return $this->postings()->currentOf($movementId);
    }

    /**
     * What the window offers for $movement: what was last booked for its pattern (the same text /
     * merchant → the same proposal, decision 14), and the text from the movement.
     *
     * @return array{kind: string, contra: string, taxCode: string, text: string}
     */
    public function proposalFor(BankMovement $movement): array
    {
        $pattern = (new BankMovementStates($this->em))->patternOf($movement);
        $contra  = (string) $pattern?->getContraAccount();
        $kind    = self::KIND_ACCOUNT;
        foreach ([self::KIND_PRIVATE, self::KIND_TRANSFER] as $named) {
            if ($contra !== '' && $contra === $this->namedAccount($named)) {
                $kind = $named;
            }
        }

        return ['kind' => $kind, 'contra' => $kind === self::KIND_ACCOUNT ? $contra : '', 'taxCode' => (string) $pattern?->getTaxCode(), 'text' => self::textOf($movement)];
    }

    /** The entry text a movement proposes: the counterparty, then what the bank wrote — one line, cut to the entry's length. */
    public static function textOf(BankMovement $movement): string
    {
        $flat = static fn(string $value): string => trim((string) preg_replace('/\s+/u', ' ', $value));
        $text = $flat($movement->getText());
        $name = $flat($movement->getCounterpartyName());
        $full = $name === '' ? $text : ($text === '' ? $name : $name . ' · ' . $text);

        return mb_substr($full !== '' ? $full : 'Bankbewegung vom ' . $movement->getBookingDate()->format('d.m.Y'), 0, JournalEntry::TEXT_LENGTH);
    }

    /**
     * The archived voucher of posting $postingId.
     *
     * @return array{bytes: string, name: string, type: string}
     * @throws BankPostingRefusedException NOTHING — no such posting or no file; VOUCHER — the file is missing or altered
     */
    public function voucher(int $postingId): array
    {
        $posting = $this->postings()->find($postingId);
        if (!$posting instanceof BankPosting || !$posting->hasVoucher()) {
            throw new BankPostingRefusedException(BankPostingRefusedException::NOTHING, 'Zu dieser Buchung ist kein Beleg abgelegt.');
        }
        $bytes = (new BankVoucherArchive($this->basePath))->bytes((string) $posting->getVoucherPath(), (string) $posting->getVoucherSha256());
        if ($bytes === null) {
            error_log('financial bank voucher: posting ' . $postingId . ': file missing or SHA-256 mismatch: ' . $posting->getVoucherPath());
            throw new BankPostingRefusedException(
                BankPostingRefusedException::VOUCHER,
                'Der abgelegte Beleg fehlt oder ist verändert (Prüfsumme stimmt nicht) — Datei ' . $posting->getVoucherPath() . ' aus der Datensicherung zurückholen; die Ursache steht im Fehlerprotokoll.'
            );
        }

        return ['bytes' => $bytes, 'name' => (string) $posting->getVoucherName(), 'type' => BankVoucherArchive::typeOf($bytes) ?? 'application/octet-stream'];
    }

    // ── «Geschäftlich buchen …» ──────────────────────────────────────────

    /**
     * Books movement $movementId — see the class docblock.
     *
     * @param string      $kind    {@see KINDS}
     * @param string      $contra  the contra account's number (only with {@see KIND_ACCOUNT})
     * @param string|null $taxCode null or '' = «ohne MWST»
     * @param string      $text    the entry text; '' = the movement's ({@see textOf()})
     * @param array{name: string, bytes: string}|null $voucher the attached file
     * @param bool|null   $patternAnswer the answer to decision 8 when a private pattern takes the movement: true «Ja», false «Nein»
     * @throws BankPostingRefusedException CONTRA | TAX_CODE | VOUCHER | PERIOD | ENTRY
     * @throws BankRefusedException        LINK_GONE | LINK_TAKEN | PATTERN_QUESTION | NOT_DRIVEN (the bank account is not
     *                                     bank-driven on the movement's day — information only, ADR-061 decision 1)
     */
    public function post(int $movementId, string $kind, string $contra, ?string $taxCode, string $text = '', ?array $voucher = null, ?bool $patternAnswer = null): BankPosting
    {
        $movement = $this->movements()->find($movementId);
        if (!$movement instanceof BankMovement) {
            throw new BankRefusedException(BankRefusedException::LINK_GONE, 'Diese Bankbewegung gibt es nicht mehr — die Liste neu laden.');
        }
        if (!$movement->getBankAccount()->drives($movement->getBookingDate())) {
            throw BankRefusedException::notDriven($movement);
        }
        $taxCode = $taxCode !== null && trim($taxCode) !== '' ? trim($taxCode) : null;
        $kind    = in_array($kind, self::KINDS, true) ? $kind : self::KIND_ACCOUNT;
        $text    = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) > JournalEntry::TEXT_LENGTH) {
            throw new BankPostingRefusedException(BankPostingRefusedException::ENTRY, 'Der Text ist zu lang — höchstens ' . JournalEntry::TEXT_LENGTH . ' Zeichen.', 'text');
        }
        $text    = $text !== '' ? $text : self::textOf($movement);
        $amount  = $movement->getAmount();
        $date    = $movement->getBookingDate();
        $account = $this->contraAccount($kind, $contra, $taxCode);
        $bank    = $movement->getBankAccount()->getLedgerAccount();
        $lines   = $this->lines($movement, $account, $bank, $taxCode, $date);

        $type = null;
        if ($voucher !== null) {
            $type = strlen($voucher['bytes']) <= self::VOUCHER_MAX_BYTES ? BankVoucherArchive::typeOf($voucher['bytes']) : null;
            if ($type === null) {
                throw new BankPostingRefusedException(BankPostingRefusedException::VOUCHER, 'Der Beleg muss ein PDF oder ein Foto (JPEG, PNG) sein, höchstens 10 MB.', 'voucher');
            }
        } elseif ($taxCode !== null) {
            try {
                $from = self::voucherFromGross();
            } catch (\UnexpectedValueException $e) {
                // A project override that replaces financialConfig without the key (BOOT-CONFIG-001): say so, do not guess a threshold.
                throw new BankPostingRefusedException(BankPostingRefusedException::VOUCHER, 'Die Einstellung «bankPosting.voucherFromGross» (financialConfig) fehlt oder ist ungültig — ohne sie wird mit MWST-Code nur mit Beleg gebucht.', 'voucher', $e);
            }
            if (!$amount->lessThan($from)) {
                throw new BankPostingRefusedException(
                    BankPostingRefusedException::VOUCHER,
                    'Mit MWST-Code und ab ' . AmountFormat::of($from) . ' ' . $amount->currency . ' braucht die Buchung einen Beleg (PDF oder Foto) — ohne ihn wird nicht gespeichert.',
                    'voucher'
                );
            }
        }

        $archive = new BankVoucherArchive($this->basePath);
        try {
            return $this->em->getTransaction(JournalEntry::class)->run(function () use ($movement, $account, $taxCode, $text, $date, $lines, $voucher, $type, $patternAnswer, $archive): BankPosting {
                $now     = new \DateTimeImmutable();
                $posting = new BankPosting($movement, $account->getNumber(), $taxCode, $this->actor(), $now);
                $this->em->persist($posting);
                $this->em->flush();   // the id is the owner key and the idempotency key
                $links = new BankLinkService($this->em, $this->actor());
                // Locks the movement row; refuses a movement that is linked already (one link per movement).
                $links->link((int) $movement->getId(), BankPosting::OWNER_TYPE, $posting->ownerKey(), 'Buchung · ' . $account->label(), BankLink::BASIS_MANUAL);
                (new BankMovementStates($this->em, $this->actor()))->booked($movement, $account->getNumber(), $taxCode, $patternAnswer);
                try {
                    $ref = (new LedgerService($this->em, $this->actor()))->post(PostingRequest::generated($date, $text, BankPosting::SOURCE_TYPE, self::sourceRef($movement), $posting->idempotencyKey(), $lines));
                } catch (PostingRefusedException $e) {
                    throw self::periodRefusal($e, $date, 'gebucht');
                }
                $posting->booked($ref);
                if ($voucher !== null && $type !== null) {
                    $sha256 = hash('sha256', $voucher['bytes']);
                    try {
                        $path = $archive->store($voucher['bytes'], $sha256, $type, $date, $movement->getBankAccount()->getCode(), (int) $movement->getId());
                    } catch (\RuntimeException $e) {
                        error_log('financial bank voucher: ' . $e->getMessage());
                        throw new BankPostingRefusedException(BankPostingRefusedException::VOUCHER, 'Nichts gebucht: der Beleg konnte nicht abgelegt werden (' . BankVoucherArchive::DIR . '). Die Ursache steht im Fehlerprotokoll.', 'voucher', $e);
                    }
                    $posting->attach($path, $sha256, $voucher['name']);
                }
                $this->em->persist($posting);
                $links->relabel($posting->ownerKey(), 'Buchung ' . $ref->fiscalYear . '/' . $ref->number . ' · ' . $account->label());

                return $posting;
            });
        } catch (\Throwable $e) {
            $archive->discardWritten();
            throw $e;
        }
    }

    // ── «Buchung zurücknehmen …» ─────────────────────────────────────────

    /**
     * Takes the direct posting of movement $movementId back — see the class docblock.
     *
     * @return EntryRef the reversing entry
     * @throws BankPostingRefusedException NOTHING | PERIOD | ENTRY
     * @throws BankRefusedException        LINK_GONE
     */
    public function takeBack(int $movementId): EntryRef
    {
        return $this->em->getTransaction(JournalEntry::class)->run(function () use ($movementId): EntryRef {
            /** @var BankMovement|null $movement */
            $movement = $this->movements()->lockRow($movementId) ? $this->movements()->find($movementId) : null;
            if ($movement === null) {
                throw new BankRefusedException(BankRefusedException::LINK_GONE, 'Diese Bankbewegung gibt es nicht mehr — die Liste neu laden.');
            }
            $posting = $this->postings()->currentOf($movementId);
            $ref     = $posting?->entryRef();
            if ($posting === null || $ref === null) {
                throw new BankPostingRefusedException(
                    BankPostingRefusedException::NOTHING,
                    'Diese Bewegung hat keine Buchung aus «Geschäftlich buchen» — eine Zuordnung zu einer Rechnung oder Zahlung löst du dort, wo sie gemacht wurde.'
                );
            }
            $entry = $this->em->getRepository(JournalEntry::class)->findByRef($ref);
            $date  = $entry?->getDate() ?? $movement->getBookingDate();
            try {
                $reversal = (new LedgerService($this->em, $this->actor()))->reverse($ref, $date, mb_substr('Zurückgenommen: ' . (string) $entry?->getText(), 0, JournalEntry::TEXT_LENGTH));
            } catch (PostingRefusedException $e) {
                throw self::periodRefusal($e, $date, 'zurückgenommen');
            } catch (ReversalRefusedException $e) {
                throw new BankPostingRefusedException(BankPostingRefusedException::ENTRY, 'Die Buchung ' . $ref->fiscalYear . '/' . $ref->number . ' lässt sich nicht zurücknehmen — sie ist bereits storniert oder gibt es nicht mehr.', null, $e);
            }
            $posting->takeBack($reversal, $this->actor(), new \DateTimeImmutable());
            $this->em->persist($posting);
            (new BankLinkService($this->em, $this->actor()))->release($posting->ownerKey());
            (new BankMovementStates($this->em, $this->actor()))->held($movement);

            return $reversal;
        });
    }

    // ── internals ────────────────────────────────────────────────────────

    /** @throws BankPostingRefusedException CONTRA | TAX_CODE */
    private function contraAccount(string $kind, string $contra, ?string $taxCode): Account
    {
        $builder = GrossEntryBuilder::of($this->em);
        if ($kind !== self::KIND_ACCOUNT) {
            $word = $kind === self::KIND_PRIVATE ? '«Privat»' : '«Übertrag eigenes Konto»';
            if ($taxCode !== null) {
                throw new BankPostingRefusedException(BankPostingRefusedException::TAX_CODE, $word . ' wird ohne MWST gebucht — den MWST-Code leer lassen.', 'tax_code');
            }
            $number = $this->namedAccount($kind);
            try {
                return $builder->account($number);
            } catch (GrossEntryRefusedException $e) {
                throw new BankPostingRefusedException(
                    BankPostingRefusedException::CONTRA,
                    'Für ' . $word . ' ist kein gültiges Konto hinterlegt' . ($number !== '' ? ' (' . $number . ': ' . $e->getMessage() . ')' : '')
                    . ' — das Konto ' . LedgerService::MANDATOR_HINT . ' oder als Gegenkonto direkt eingeben.',
                    'kind',
                    $e
                );
            }
        }
        try {
            return $builder->account($contra);
        } catch (GrossEntryRefusedException $e) {
            throw new BankPostingRefusedException(BankPostingRefusedException::CONTRA, $e->getMessage(), 'contra', $e);
        }
    }

    /**
     * The balanced lines: a debit books «contra an Bank», a credit «Bank an contra»; the VAT split is
     * the builder's.
     *
     * @return list<PostingLine>
     * @throws BankPostingRefusedException
     */
    private function lines(BankMovement $movement, Account $contra, string $bank, ?string $taxCode, \DateTimeImmutable $date): array
    {
        $liable = (new CurrentMandator($this->em))->find()?->isLiableToVat() ?? true;
        $debit  = !$movement->isCredit();
        try {
            return GrossEntryBuilder::of($this->em)->build(
                $date,
                $debit ? $bank : $contra->getNumber(),
                [new GrossPart($debit ? $contra->getNumber() : $bank, $movement->getAmount(), $taxCode)],
                $liable
            )->lines;
        } catch (GrossEntryRefusedException $e) {
            // Which side an account stands on depends on the direction: name the form field by what was refused.
            $bankSide = ($e->field === GrossEntryRefusedException::ACCOUNT) !== $debit && in_array($e->field, [GrossEntryRefusedException::ACCOUNT, GrossEntryRefusedException::COUNTER], true);
            [$reason, $field] = match (true) {
                in_array($e->field, [GrossEntryRefusedException::TAX_CODE, GrossEntryRefusedException::TAX_AMOUNT], true) => [BankPostingRefusedException::TAX_CODE, 'tax_code'],
                $bankSide => [BankPostingRefusedException::ENTRY, null],
                in_array($e->field, [GrossEntryRefusedException::ACCOUNT, GrossEntryRefusedException::COUNTER], true) => [BankPostingRefusedException::CONTRA, 'contra'],
                default => [BankPostingRefusedException::ENTRY, null],
            };

            throw new BankPostingRefusedException($reason, ($bankSide ? 'Konto des Bankkontos (' . $bank . '): ' : '') . $e->getMessage(), $field, $e);
        }
    }

    /** The origin of the entry: the bank account, the movement and the archived statement file — the voucher without a tax code (decision 16). */
    private static function sourceRef(BankMovement $movement): string
    {
        return mb_substr($movement->getBankAccount()->getCode() . '/' . (int) $movement->getId() . ' · ' . basename($movement->getStatement()->getArchivePath()), 0, JournalEntry::SOURCE_REF_LENGTH);
    }

    /** The ledger's refusal of a date, as a German sentence (decision 21). */
    private static function periodRefusal(PostingRefusedException $e, \DateTimeImmutable $date, string $verb): BankPostingRefusedException
    {
        $day     = $date->format('d.m.Y');
        $message = match ($e->reason) {
            PostingRefusedException::NO_FISCAL_YEAR, PostingRefusedException::NO_PERIOD
                => 'Für den ' . $day . ' ist kein Geschäftsjahr eröffnet — zuerst das Geschäftsjahr eröffnen (Finanzen › Geschäftsjahre).',
            PostingRefusedException::PERIOD_CLOSED
                => 'Der ' . $day . ' liegt in einem abgeschlossenen Geschäftsjahr — dort wird nichts mehr ' . $verb . '. Die Korrektur ist eine Buchung von Hand im offenen Jahr.',
            PostingRefusedException::PERIOD_VAT_SETTLED
                => 'Die MWST der Periode des ' . $day . ' ist abgerechnet — eine Buchung mit MWST-Code wird dort nicht mehr ' . $verb . '. Die Korrektur ist eine Buchung von Hand in der offenen Periode.',
            PostingRefusedException::ACCOUNT_UNKNOWN, PostingRefusedException::ACCOUNT_NOT_POSTABLE, PostingRefusedException::ACCOUNT_INACTIVE
                => 'Ein Konto der Buchung gibt es nicht mehr, ist eine Gruppe oder inaktiv' . ($e->account !== null ? ' (' . $e->account . ')' : '') . '.',
            default => 'Die Buchung wurde abgelehnt: ' . $e->getMessage(),
        };
        $period = in_array($e->reason, [PostingRefusedException::NO_FISCAL_YEAR, PostingRefusedException::NO_PERIOD, PostingRefusedException::PERIOD_CLOSED, PostingRefusedException::PERIOD_VAT_SETTLED], true);

        return new BankPostingRefusedException($period ? BankPostingRefusedException::PERIOD : BankPostingRefusedException::ENTRY, $message, null, $e);
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function movements(): BankMovementRepository
    {
        return $this->em->getRepository(BankMovement::class);
    }

    private function postings(): BankPostingRepository
    {
        return $this->em->getRepository(BankPosting::class);
    }
}
