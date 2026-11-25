<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Contact\Repositories\ContactRepository;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Recognition\InvoiceFacts;
use Z77\Module\Creditor\Repositories\CreditorIbanRepository;
use Z77\Module\Creditor\Repositories\CreditorInvoiceLineRepository;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Financial\Bank\BankAccountService;
use Z77\Module\Financial\Bank\BankLinkService;
use Z77\Module\Financial\Bank\BankRefusedException;
use Z77\Module\Financial\Entities\BankAccount;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Ui\ManualEntryForm;
use Z77\Module\Mandator\Services\Uid;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Services\PaymentMethodService;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Module\Receipt\Ui\ReceiptChoices;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\AmountFormat;
use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;
use Z77\Shared\Payment\QrReference;
use Z77\Shared\Payment\ScorReference;

/**
 * The Kreditorenjournal as the owner works it (creditor plan §2.3, §3, §5; owner answers §11):
 * CORRECT an invoice (head and lines), RELEASE a suspected duplicate, DELETE an invoice, take an
 * unknown creditor over («Kreditor übernehmen …»). Every write with optimistic locking — the
 * caller names the `version` it showed; the row is locked and compared inside the unit of work, a
 * mismatch refuses with {@see CreditorRefusedException::CHANGED}. A posted invoice is read-only.
 *
 * What {@see update()} applies, in this order:
 *
 *   - the values judged with module-receipt's field rules (`ReceiptFields` — one rule set, owner
 *     answer 1) plus the invoice's own: IBAN (`Iban`), the reference — its TYPE follows it (a valid
 *     QR reference = QRR, a valid RF = SCOR, empty = NON; a QR reference only with a QR-IBAN);
 *   - a FOREIGN currency (stage A0, owner 2026-11-15): the currency from receipt's list
 *     (`ReceiptFields::foreignCurrency()`) with the printed amount; the CHF amount (what the bank
 *     or card debited) may stay empty — the invoice is then incomplete; the rate is derived;
 *   - the creditor's DEFAULTS fill what is empty: a line without account gets the default account,
 *     a line without VAT decision the default one, an invoice without «Bezahlt mit» the default
 *     payment method (and its credit account);
 *   - a mandator NOT liable to VAT (owner answer 5): every line is «ohne MWST» — an undecided line
 *     is set so, a line with a tax code is refused with a German sentence;
 *   - ONE line without amount takes the invoice amount (the usual one-line invoice);
 *   - the DUPLICATE CHECK (owner answer 4): the same creditor + invoice number, or the same IBAN +
 *     reference, on any other invoice → BLOCKED with «mögliches Doppel von …»; when the match is
 *     gone (a typo corrected) the mark goes too. A pair the owner released is not checked again;
 *   - the LEARNED defaults (owner 2026-11-16, replaces «defaults once»): what the creditor does
 *     NOT have yet — its account (with the first line's VAT decision) and «Bezahlt mit» — it takes
 *     from this save's first line and «Bezahlt mit» ({@see CreditorProfileService::learnDefaults()}),
 *     complete or not; a default already set is never overwritten — only the switch «als Standard
 *     übernehmen» ($takeAsDefault, a complete invoice) or the creditor window does.
 *
 * «Bezahlt am …» (stage 1c, plan §6): per invoice through {@see update()} (`paid_on`, empty = unpaid
 * again) and for several ticked rows at once through {@see markPaid()} (`id:version` each, optionally
 * «Bezahlt mit» for all) — a valid day, never in the future; it is the posting date of the direct
 * variant. A posted invoice is immutable.
 *
 * Deleting (owner 2026-11-15, {@see delete()}): an UNPOSTED invoice goes COMPLETELY — lines and
 * intake row, so its file hash is free and the same PDF may be uploaded again (the screen moves the
 * PDF to the DMS trash); a suspected duplicate DISCARDED («Verwerfen …») keeps its intake row as
 * `discarded` — that file stays refused. A posted invoice cannot be deleted.
 *
 * Who: the actor's name is the caller's, or the session's ({@see Actor::current()}).
 */
final class CreditorInvoiceService
{
    /** The VAT select's value for «ohne MWST» — never a tax code (module-receipt's convention). */
    public const WITHOUT_VAT = '-';

    private ?string $actor;
    private ?bool $liable = null;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    public function find(int $id): ?CreditorInvoice
    {
        return $id > 0 ? $this->invoices()->find($id) : null;
    }

    /** @return list<CreditorInvoiceLine> */
    public function linesOf(CreditorInvoice $invoice): array
    {
        return $this->lines()->forInvoice($invoice);
    }

    /** The mandator's VAT liability (module-mandator; unreadable = liable, the creditor profile's rule). */
    public function liableToVat(): bool
    {
        return $this->liable ??= (new CreditorProfileService($this->em))->liableToVat();
    }

    /**
     * What a NEW line of $creditor starts with: the default account and VAT decision — «ohne
     * MWST» whenever the mandator is not liable (owner answer 5). A learned default naming one of
     * the mandator's VAT accounts locked today loads empty (`ReceiptChoices::offeredAccount()`,
     * ADR-042 addendum 2 of 2026-11-25, point 7 — the record keeps it until it is changed).
     *
     * @return array{account: ?string, taxCode: ?string, withoutVat: bool}
     */
    public function lineDefaults(CreditorProfile $creditor): array
    {
        $account = (new ReceiptChoices($this->em))->offeredAccount($creditor->getDefaultAccount());
        if (!$this->liableToVat()) {
            return ['account' => $account, 'taxCode' => null, 'withoutVat' => true];
        }

        return ['account' => $account, 'taxCode' => $creditor->getDefaultTaxCode(), 'withoutVat' => $creditor->getDefaultTaxCode() === null && $creditor->isDefaultWithoutVat()];
    }

    /**
     * «Bezahlt mit» of $creditor and the credit account it resolves to — an unknown or inactive
     * payment method gives none.
     *
     * @return array{code: ?string, credit: ?string}
     */
    public function paymentDefaults(CreditorProfile $creditor): array
    {
        $code   = $creditor->getDefaultPaymentMethodCode();
        $method = $code === null ? null : (new PaymentMethodService($this->em))->find($code);
        if (!$method instanceof PaymentMethod || !$method->isActive()) {
            return ['code' => null, 'credit' => null];
        }

        return ['code' => $method->getCode(), 'credit' => $method->getCreditAccount() !== '' ? $method->getCreditAccount() : null];
    }

    /**
     * Correct an open or blocked invoice — its head and the COMPLETE list of its lines.
     *
     * @param array<string, mixed> $values creditor (profile id), iban, invoice_number, invoice_date,
     *                                     due_date, reference, message, amount (CHF), payment_method_code,
     *                                     credit_account, paid_on («bezahlt am»; '' = unpaid, the key
     *                                     ABSENT = unchanged), currency + original_amount (stage A0:
     *                                     '' / CHF = a CHF invoice; a foreign currency of
     *                                     `ReceiptFields::foreignCurrencies()` — or the invoice's own —
     *                                     requires the original amount, CHF may stay empty; the key
     *                                     `currency` ABSENT = unchanged), exchange_rate (owner 2026-11-18: the
     *                                     rate typed for a foreign invoice — with «Betrag CHF» empty the amount
     *                                     is original × rate, with both they must agree to the Rappen; stored;
     *                                     the key ABSENT = unchanged) — strings as a form posts them
     * @param array<mixed> $lineRows       rows {text, account, tax (code, «-» or ''), amount} ({@see lineRows()})
     * @param bool $takeAsDefault          «als Standard übernehmen» — this invoice's account, VAT and
     *                                     «Bezahlt mit» become the creditor's defaults even when set
     * @param ?array $learned            receives what the creditor LEARNED from this save
     *                                     ({@see CreditorProfileService::learnDefaults()}; [] = nothing)
     * @throws InvalidCreditorInvoiceException field → German message; nothing written
     * @throws CreditorRefusedException CHANGED — gone, posted, or another version than $version
     */
    public function update(int $id, int $version, array $values, array $lineRows, bool $takeAsDefault = false, ?array &$learned = null): CreditorInvoice
    {
        $current = $this->find($id) ?? throw self::changed();
        $previous = $current->getCreditor()?->getId();   // read before the unit of work refreshes the entity
        $errors  = [];
        $fields  = new ReceiptFields($this->em);
        $str     = static fn(string $key): string => is_scalar($values[$key] ?? null) ? trim((string) $values[$key]) : '';

        $creditor = null;
        $creditorId = (int) $str('creditor');
        if ($creditorId > 0) {
            $creditor = $this->profiles()->find($creditorId);
            if (!$creditor instanceof CreditorProfile) {
                $errors['creditor'] = 'Diesen Kreditor gibt es nicht mehr.';
                $creditor = null;
            } elseif (!$creditor->isActive() && $current->getCreditor()?->getId() !== $creditorId) {
                $errors['creditor'] = '«' . $creditor->getContact()?->displayName() . '» ist inaktiv — einen aktiven Kreditor wählen.';
            }
        }

        $iban = Iban::normalize($str('iban'));
        if ($iban !== '' && (!Iban::isWellFormed($iban) || !Iban::hasValidCheckDigits($iban))) {
            $errors['iban'] = 'Keine gültige IBAN — Länge oder Prüfziffer stimmt nicht.';
        }
        $number = self::clean($str('invoice_number'));
        if (mb_strlen($number) > CreditorInvoice::NUMBER_LENGTH) {
            $errors['invoice_number'] = 'Die Rechnungs-Nr. ist länger als ' . CreditorInvoice::NUMBER_LENGTH . ' Zeichen.';
        }
        $invoiceDate = $this->optionalDate($str('invoice_date'), 'invoice_date', 'Rechnungsdatum', $errors);
        $dueDate     = $this->optionalDate($str('due_date'), 'due_date', 'Fällig am', $errors);
        if ($invoiceDate !== null && $dueDate !== null && $dueDate < $invoiceDate) {
            $errors['due_date'] = '«Fällig am» liegt vor dem Rechnungsdatum.';
        }
        [$referenceType, $reference] = self::reference($str('reference'), $iban, $errors);
        $message = self::clean($str('message'));
        if (mb_strlen($message) > CreditorInvoice::MESSAGE_LENGTH) {
            $errors['message'] = 'Die Mitteilung ist länger als ' . CreditorInvoice::MESSAGE_LENGTH . ' Zeichen.';
        }
        $amount = $str('amount') === '' ? null : $fields->amount($str('amount'), 'amount', $errors);
        // Foreign currency (stage A0): the key ABSENT = the original stays as it is.
        $original = $current->getOriginal();
        if (array_key_exists('currency', $values)) {
            $currency = $fields->foreignCurrency($str('currency'), 'currency', $errors, $current->getOriginalCurrency());
            $original = isset($errors['currency']) ? null : $fields->originalAmount($str('original_amount'), $currency, 'original_amount', $errors);
        }
        // The typed rate (owner 2026-11-18): the key ABSENT = unchanged. With «Betrag CHF» EMPTY the amount is
        // original × rate; with both typed they must agree to the Rappen (three invoices of one card payment,
        // each rounded, may differ by one) — a stored rate always explains the amount.
        $typesRate = array_key_exists('exchange_rate', $values);
        $rate      = $typesRate ? $fields->rate($str('exchange_rate'), 'exchange_rate', $errors) : null;
        if ($rate !== null && $original === null) {
            if (!isset($errors['currency']) && !isset($errors['original_amount'])) {
                $errors['exchange_rate'] = 'Kurs: nur bei einer Rechnung in fremder Währung — das Feld leeren.';
            }
            $rate = null;
        }
        if ($rate !== null) {
            $computed = ReceiptFields::convert($original, $rate);
            if (!$computed->isPositive()) {
                $errors['exchange_rate'] = 'Kurs: ergibt keinen Betrag.';
                $rate = null;
            } elseif ($amount === null && !isset($errors['amount'])) {
                $amount = $computed;
            } elseif ($amount !== null && abs($amount->minor - $computed->minor) > 1) {
                $errors['exchange_rate'] = 'Kurs: ' . AmountFormat::of($original) . ' ' . $original->currency . ' × ' . ReceiptFields::formatRate($rate) . ' ergibt ' . ReceiptFields::currency() . ' '
                    . AmountFormat::of($computed) . ', im Feld «Betrag ' . ReceiptFields::currency() . '» steht ' . AmountFormat::of($amount) . ' — eines der beiden Felder leeren.';
            }
        }
        $paidOn = array_key_exists('paid_on', $values) ? ['date' => $this->paymentDate($str('paid_on'), false, $errors)] : null;

        $methodCode = PaymentMethod::normalizeCode($str('payment_method_code'));
        $method     = null;
        if ($methodCode !== '') {
            $method = (new PaymentMethodService($this->em))->find($methodCode);
            if (!$method instanceof PaymentMethod) {
                $errors['payment_method_code'] = 'Die Zahlungsart «' . $methodCode . '» gibt es nicht.';
            } elseif (!$method->isActive() && $methodCode !== $current->getPaymentMethodCode()) {
                $errors['payment_method_code'] = 'Die Zahlungsart «' . $method->getLabel() . '» ist inaktiv.';
            }
        }
        $credit = $fields->account($str('credit_account'), 'credit_account', $errors);

        // The creditor's defaults fill what is empty (plan §5) — the payment method first.
        if ($creditor !== null && $methodCode === '' && !isset($errors['creditor'])) {
            $payment    = $this->paymentDefaults($creditor);
            $methodCode = (string) $payment['code'];
            $credit   ??= $payment['credit'];
        } elseif ($method instanceof PaymentMethod && $credit === null && $str('credit_account') === '' && $method->getCreditAccount() !== '') {
            $credit = $method->getCreditAccount();
        }

        $lines = $this->judgeLines(self::lineRows($lineRows), $creditor, $credit, $fields, $errors);
        if (count($lines) === 1 && $lines[0]['amount'] === null && $amount !== null) {
            $lines[0]['amount'] = $amount;   // the usual one-line invoice: the line is the whole amount
        }
        // Owner 2026-11-17: lines that do not add up to «Betrag CHF» are refused, not saved silently.
        $mismatch = self::lineSumMismatch($amount, array_column($lines, 'amount'));
        if ($mismatch !== null && !self::hasLineErrors($errors)) {
            $errors['lines'] = $mismatch;
        }

        if ($errors !== []) {
            throw new InvalidCreditorInvoiceException($errors);
        }
        $actor = $this->actor();

        $learned = [];

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $creditor, $iban, $number, $invoiceDate, $dueDate, $referenceType, $reference, $message, $amount, $typesRate, $rate, $original, $methodCode, $credit, $lines, $takeAsDefault, $paidOn, $actor, $previous, &$learned): CreditorInvoice {
            $invoice = $this->lockAt($id, $version);
            // Linked to a bank movement (ADR-061 stage C1): «Bezahlt am» and the amounts are the
            // bank's — changed only by «Zuordnung lösen». An EMPTY amount keeps the bank's (the
            // window shows the field disabled, so it posts nothing).
            $links  = new BankLinkService($this->em, $actor);
            $linked = $links->ofOwner($invoice->bankOwnerKey()) !== null;
            if ($linked) {
                $bank = [];
                if ($paidOn !== null && $paidOn['date']?->format('Y-m-d') !== $invoice->getPaidOn()?->format('Y-m-d')) {
                    $bank['paid_on'] = 'Bezahlt am: kommt von der Bankbewegung — zuerst «Zuordnung lösen».';
                }
                if ($amount !== null && ($invoice->getAmount() === null || !$amount->equals($invoice->getAmount()))) {
                    $bank['amount'] = 'Betrag: kommt von der Bankbewegung — zuerst «Zuordnung lösen».';
                }
                if (($original?->toDecimal() . ' ' . $original?->currency) !== ($invoice->getOriginal()?->toDecimal() . ' ' . $invoice->getOriginal()?->currency)) {
                    $bank['original_amount'] = 'Betrag in Währung: die Rechnung ist einer Bankbewegung zugeordnet — zuerst «Zuordnung lösen».';
                }
                if ($rate !== null) {
                    $bank['exchange_rate'] = 'Kurs: der Betrag kommt von der Bankbewegung — zuerst «Zuordnung lösen».';
                }
                if ($bank !== []) {
                    throw new InvalidCreditorInvoiceException($bank);
                }
                $amount = $invoice->getAmount();
                if (count($lines) === 1 && $lines[0]['amount'] === null && $amount !== null) {
                    $lines[0]['amount'] = $amount;   // as outside the unit of work: the one line is the whole amount
                }
                $mismatch = self::lineSumMismatch($amount, array_column($lines, 'amount'));   // against the bank's amount
                if ($mismatch !== null) {
                    throw new InvalidCreditorInvoiceException(['lines' => $mismatch]);
                }
            }
            $invoice->correct(
                $creditor, $iban === '' ? null : $iban, $number === '' ? null : $number, $invoiceDate, $dueDate,
                $referenceType, $reference, $message, $amount, $original, $methodCode === '' ? null : $methodCode, $credit, $actor, new \DateTimeImmutable()
            );
            if ($typesRate && !$linked) {
                $invoice->typeRate($rate);   // '' in the form = no typed rate (any more)
            }
            if ($linked) {
                $links->relabel($invoice->bankOwnerKey(), $invoice->bankLabel());   // the list «Bewegungen» names the invoice as it reads now
            }
            $paidChanged = $paidOn !== null && $paidOn['date']?->format('Y-m-d') !== $invoice->getPaidOn()?->format('Y-m-d');
            if ($paidChanged && $paidOn['date'] !== null) {
                // Bank-driven (ADR-061 decision 13, stage C4): on a switched-on bank account, from «Abgleich
                // ab», «Bezahlt am» comes ONLY from the bank — judged with the payment method as saved now.
                $bank = $this->bankDriving($invoice->getPaymentMethodCode(), $paidOn['date']);
                if ($bank !== null) {
                    throw new InvalidCreditorInvoiceException(['paid_on' => 'Bezahlt am: ' . self::bankDrivenSentence($bank) . ' Die Zahlung übernimmst du mit «Zahlung suchen …».']);
                }
            }
            if ($paidChanged) {
                $invoice->markPaid($paidOn['date'], $actor, new \DateTimeImmutable());
            }
            $saved = $this->replaceLines($invoice, $lines);
            $this->checkDuplicate($invoice);
            // A suspected duplicate is not paid before the owner decided (owner 2026-11-16) — judged
            // AFTER the duplicate check, so an edit that clears the suspicion may set the date in
            // the same save. Emptying it stays allowed; a date set before the block stays.
            if ($invoice->isBlocked() && $paidOn !== null && $paidOn['date'] !== null && $paidChanged) {
                throw new InvalidCreditorInvoiceException(['paid_on' => 'Bezahlt am: Die Rechnung ist gesperrt (mögliches Doppel). Zuerst entscheiden: «Kein Doppel — übernehmen», «Verwerfen …» oder «Löschen …».']);
            }
            if ($creditor !== null && $saved !== [] && $takeAsDefault && $invoice->isComplete($saved)) {
                // «als Standard übernehmen» — with the first line's text as the standard line text (owner 2026-11-17)
                (new CreditorProfileService($this->em, $actor))->takeDefaults(
                    $creditor, $saved[0]->getAccount(), $saved[0]->getTaxCode(), $saved[0]->isWithoutVat(), $invoice->getPaymentMethodCode(), true, $saved[0]->getText()
                );
            } elseif ($creditor !== null && $saved !== []) {
                // Owner 2026-11-16: what the creditor has NOT yet (account + its VAT, «Bezahlt mit») it learns from this save.
                $learned = $this->learnFrom($creditor, $saved[0], $invoice->getPaymentMethodCode(), $actor);
            }
            if ($creditor !== null && $saved !== []) {
                // Owner 2026-11-17: the last saved line text — the recognition's fallback (after the defaults above: they re-read the row).
                (new CreditorProfileService($this->em, $actor))->noteLastLineText($creditor, $saved[0]->getText());
            }
            if ($creditor !== null) {
                // Owner 2026-11-16: the owner settled the creditor — it learns this invoice's sender
                // features and value labels from the text the job kept (CreditorLearningService);
                // 2026-11-17 also the column header above the first line's text.
                (new CreditorLearningService($this->em, $actor))->learnFromInvoice($invoice, $creditor, $previous !== null ? (int) $previous : null, ($saved[0] ?? null)?->getText());
            }
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * «Kein Doppel — übernehmen»: the owner decided a BLOCKED invoice is not a duplicate of the
     * marked one; it is open again and that pair is not checked any more.
     *
     * @throws CreditorRefusedException CHANGED — gone, not blocked, or another version
     */
    public function release(int $id, int $version): CreditorInvoice
    {
        $actor = $this->actor();

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $actor): CreditorInvoice {
            $invoice = $this->lockAt($id, $version);
            if (!$invoice->isBlocked()) {
                throw self::changed();
            }
            $invoice->releaseDuplicate($actor, new \DateTimeImmutable());
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * The bank account that DRIVES «Bezahlt am» for payment method $methodCode on $day (ADR-061
     * decision 13, stage C4): the method pays through a bank account with «Kontoauszug einlesen»
     * on and «Abgleich ab» on or before that day. Null = typing the date is allowed — a payment
     * method without a bank account (cash), an account that reads no statement, or a day before
     * «Abgleich ab».
     */
    private function bankDriving(?string $methodCode, \DateTimeImmutable $day): ?BankAccount
    {
        $method = (string) $methodCode === '' ? null : (new PaymentMethodService($this->em))->find((string) $methodCode);
        $code   = $method instanceof PaymentMethod ? $method->getBankAccountCode() : '';
        $bank   = $code === '' ? null : (new BankAccountService($this->em))->find($code);

        return $bank !== null && $bank->drives($day) ? $bank : null;
    }

    /** Why a typed «Bezahlt am» is refused on a bank-driven account — one German sentence, for the field and the bulk answer. */
    private static function bankDrivenSentence(BankAccount $bank): string
    {
        return 'Das Bankkonto «' . $bank->getLabel() . '» wird seit dem ' . $bank->getReconcileFrom()?->format('d.m.Y')
            . ' mit dem Kontoauszug abgeglichen — das Datum kommt dort nur von der Bankbewegung und lässt sich nicht von Hand setzen.';
    }

    /**
     * «Bezahlt am …» for several invoices at once (plan §6 — the ticked rows, `id:version` each, as
     * debtor's invoicing selection): the payment date for all of them and, when $methodCode is given,
     * «Bezahlt mit» with its credit account. One unit of work; the rows locked in id order. A row
     * changed, posted or deleted since the list showed it is NOT written and comes back in `refused`
     * (German, naming it) — the others are written. A BLOCKED invoice (a suspected duplicate) is
     * refused the same way (owner 2026-11-16: «sonst wäre er ja nicht gesperrt» — decide first);
     * the tab «Gesperrt» offers only «Löschen …».
     *
     * @param list<array{id: int, version: int}> $pairs
     * @return array{paid: list<CreditorInvoice>, refused: list<string>}
     * @throws InvalidCreditorInvoiceException `paid_on` (missing, no date, in the future), `payment_method_code`, `selection` (nothing ticked) — nothing written
     */
    public function markPaid(array $pairs, string $paidOn, string $methodCode = ''): array
    {
        $errors   = [];
        $versions = [];
        foreach ($pairs as $pair) {
            if (is_array($pair) && isset($pair['id'], $pair['version']) && (int) $pair['id'] > 0) {
                $versions[(int) $pair['id']] = (int) $pair['version'];
            }
        }
        if ($versions === []) {
            $errors['selection'] = 'Keine Rechnung angekreuzt — in der Liste die bezahlten Rechnungen ankreuzen.';
        }
        $date   = $this->paymentDate(trim($paidOn), true, $errors);
        $method = null;
        $code   = PaymentMethod::normalizeCode(trim($methodCode));
        if ($code !== '') {
            $method = (new PaymentMethodService($this->em))->find($code);
            if (!$method instanceof PaymentMethod || !$method->isActive()) {
                $errors['payment_method_code'] = $method === null ? 'Die Zahlungsart «' . $code . '» gibt es nicht.' : 'Die Zahlungsart «' . $method->getLabel() . '» ist inaktiv.';
            } elseif ($method->getCreditAccount() === '') {
                $errors['payment_method_code'] = 'Die Zahlungsart «' . $method->getLabel() . '» hat kein Haben-Konto — unter Zahlungsarten ergänzen.';
            }
        }
        if ($errors !== []) {
            throw new InvalidCreditorInvoiceException($errors);
        }
        $actor = $this->actor();

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($versions, $date, $method, $actor): array {
            $ids = array_keys($versions);
            sort($ids);
            $locked  = $this->invoices()->lockIds($ids);
            $linked  = (new BankLinkService($this->em, $actor))->linkedOwners(array_map(static fn(int $id) => CreditorInvoice::bankOwnerKeyOf($id), $ids));
            $paid    = [];
            $refused = [];
            $now     = new \DateTimeImmutable();
            foreach ($ids as $id) {
                $row = $locked[$id] ?? null;
                if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true) || $row['version'] !== $versions[$id]) {
                    $refused[] = 'Rechnung ' . $id . ': inzwischen geändert, verbucht oder gelöscht — nicht gesetzt';
                    continue;
                }
                if ($row['state'] === CreditorInvoice::STATE_BLOCKED) {
                    // A suspected duplicate is not paid before the owner decided (owner 2026-11-16).
                    $refused[] = ($this->invoices()->refreshed($id)?->label() ?? 'Rechnung ' . $id) . ': gesperrt (mögliches Doppel) — zuerst entscheiden, nicht gesetzt';
                    continue;
                }
                $invoice = $this->invoices()->refreshed($id) ?? throw self::changed();
                if (isset($linked[CreditorInvoice::bankOwnerKeyOf($id)])) {
                    // «Bezahlt am» of a linked invoice is the bank's booking date (ADR-061 decision 13).
                    $refused[] = $invoice->label() . ': «Bezahlt am» kommt von der Bankbewegung — nicht gesetzt';
                    continue;
                }
                // Bank-driven (ADR-061 decision 13, stage C4): judged with the payment method the row would carry.
                $bank = $this->bankDriving($method instanceof PaymentMethod ? $method->getCode() : $invoice->getPaymentMethodCode(), $date);
                if ($bank !== null) {
                    $refused[] = $invoice->label() . ': ' . self::bankDrivenSentence($bank) . ' Nicht gesetzt';
                    continue;
                }
                if ($method instanceof PaymentMethod) {
                    $invoice->applyPayment($method->getCode(), $method->getCreditAccount());
                }
                $invoice->markPaid($date, $actor, $now);
                $this->em->persist($invoice);
                $paid[] = $invoice;
            }

            return ['paid' => $paid, 'refused' => $refused];
        });
    }

    /**
     * Delete an unposted invoice (owner 2026-11-15): «Löschen …» of an OPEN invoice removes it
     * COMPLETELY — lines and intake row; its file hash is free, the same PDF may be uploaded again
     * (`freed` — the screen moves the PDF to the DMS trash). «Verwerfen …» of a BLOCKED invoice (a
     * confirmed duplicate) removes invoice and lines but keeps the intake row as `discarded`: that
     * file stays refused («bereits eingelesen … als Doppel verworfen»). $completely (the bulk
     * «Löschen …», owner 2026-11-16: «gesperrte müsste man löschen können genau gleich») removes a
     * blocked invoice like an open one — intake row gone, hash free. A posted invoice is refused.
     *
     * @return array{documentId: int, freed: bool} its PDF's DMS document id; whether the file hash was freed
     * @throws CreditorRefusedException CHANGED — gone, posted, or another version
     */
    public function delete(int $id, int $version, bool $completely = false): array
    {
        $actor = $this->actor();   // a delete is a write: who does it must be known

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $completely, $actor): array {
            $invoice = $this->lockAt($id, $version);
            foreach ($this->lines()->forInvoice($invoice) as $line) {
                $this->em->remove($line);
            }
            $documentId = $invoice->getDocumentId();
            $intake     = $invoice->getIntake();
            $discard    = $invoice->isBlocked() && !$completely;
            $label      = $invoice->label();
            (new CreditorLearningService($this->em, $actor))->dropText($id);
            // Its bank movement is free again — released in the same unit of work (ADR-061 decision 10).
            (new BankLinkService($this->em, $actor))->release(CreditorInvoice::bankOwnerKeyOf($id));
            $this->em->remove($invoice);
            if ($intake !== null && $discard) {
                $intake->discard($label . ' als Doppel verworfen am ' . (new \DateTimeImmutable())->format('d.m.Y') . ' von ' . $actor);
                $this->em->persist($intake);
            } elseif ($intake !== null) {
                $this->em->remove($intake);
            }

            return ['documentId' => $documentId, 'freed' => $intake !== null && !$discard];
        });
    }

    /**
     * «Löschen …» for several invoices at once (owner 2026-11-16 — the ticked rows of «Im Journal»
     * or «Gesperrt», `id:version` each, as «Bezahlt am …»): each through {@see delete()} COMPLETELY
     * (open and blocked alike: lines and intake row gone, the hash free), each in its own unit of
     * work at the version the list showed, in id order. A row changed, posted or deleted since is
     * NOT deleted and comes back in `refused` (German, naming it) — the others are. The caller moves
     * the PDFs of `deleted` to the DMS trash.
     *
     * @param list<array{id: int, version: int}> $pairs
     * @return array{deleted: list<array{id: int, label: string, documentId: int}>, refused: list<string>}
     * @throws InvalidCreditorInvoiceException `selection` — nothing ticked, nothing deleted
     */
    public function deleteSelected(array $pairs): array
    {
        $versions = [];
        foreach ($pairs as $pair) {
            if (is_array($pair) && isset($pair['id'], $pair['version']) && (int) $pair['id'] > 0) {
                $versions[(int) $pair['id']] = (int) $pair['version'];
            }
        }
        if ($versions === []) {
            throw new InvalidCreditorInvoiceException(['selection' => 'Keine Rechnung angekreuzt — in der Liste die Rechnungen ankreuzen, die weg sollen.']);
        }
        ksort($versions);
        $deleted = [];
        $refused = [];
        foreach ($versions as $id => $version) {
            $label = $this->find($id)?->label() ?? 'Rechnung ' . $id;
            try {
                $gone      = $this->delete($id, $version, true);
                $deleted[] = ['id' => $id, 'label' => $label, 'documentId' => $gone['documentId']];
            } catch (CreditorRefusedException) {
                $refused[] = $label . ': inzwischen geändert, verbucht oder gelöscht — nicht gelöscht';
            }
        }

        return ['deleted' => $deleted, 'refused' => $refused];
    }

    /**
     * «Kreditor übernehmen …» (plan §5): the invoice's PROPOSED creditor becomes $contact's creditor
     * — opened when the contact is none yet (`CreditorProfileService::open()`), the proposed IBAN
     * and UID added when missing (the creditor's own unit of work, as the role registry does it);
     * then the invoice names it, empty lines and an empty «Bezahlt mit» take its defaults, and the
     * creditor learns what it has not yet from this invoice's first line ({@see learnFrom()}).
     *
     * CARRIED OVER (owner 2026-11-16, CREDITOR-ADOPT-DUP-001): right after, every OTHER open or
     * blocked invoice without a creditor whose proposal names the SAME party ({@see sameParty()} —
     * UID, an IBAN only this creditor holds, or exactly the same name; never with a conflicting UID
     * or an IBAN of another creditor) is adopted the same way, each in its own unit of work at the
     * version read; one refused is skipped, the others stand. Each adopted invoice records
     * `recognition.match` = `adopted` (a carried one also `adopted_from` = this invoice's id and
     * `adopted_by` = uid / iban / name).
     *
     * @param ?array $carried receives the further invoices that got the creditor (list<CreditorInvoice>)
     * @throws InvalidCreditorInvoiceException `creditor` — the contact cannot be a creditor, the proposal's UID or IBAN refused
     * @throws CreditorRefusedException CHANGED — gone, posted, or another version
     */
    public function adoptCreditor(int $id, int $version, Contact $contact, ?array &$carried = null): CreditorInvoice
    {
        $adopted = $this->adoptOne($id, $version, $contact, ['match' => 'adopted']);
        $carried = $this->adoptSameParty($adopted, $contact);

        return $adopted;
    }

    /**
     * The further invoices of the same party (see {@see adoptCreditor()}) — each through
     * {@see adoptOne()}, a refusal skips that one.
     *
     * @return list<CreditorInvoice>
     */
    private function adoptSameParty(CreditorInvoice $adopted, Contact $contact): array
    {
        $profileId = $adopted->getCreditor()?->getId();
        $profile   = $profileId !== null ? $this->profiles()->refreshed((int) $profileId) : null;
        if ($profile === null) {
            return [];
        }
        $party = $adopted->proposal();
        $out   = [];
        foreach ($this->invoices()->proposedWithoutCreditor((int) $adopted->getId()) as $other) {
            $by = $this->sameParty($party, $contact, $profile, $other['proposal']);
            if ($by === null) {
                continue;
            }
            try {
                $out[]   = $this->adoptOne($other['id'], $other['version'], $contact, ['match' => 'adopted', 'adopted_from' => (int) $adopted->getId(), 'adopted_by' => $by]);
                $profile = $this->profiles()->refreshed((int) $profileId) ?? $profile;   // it may have gained an IBAN
            } catch (InvalidCreditorInvoiceException | CreditorRefusedException) {
                continue;   // changed meanwhile, or its UID / IBAN refused — left for the owner
            }
        }

        return $out;
    }

    /**
     * Whether the proposal $other names the same party as the adopted invoice's $party, now the
     * creditor $profile of $contact — conservative, along the matching rules of
     * `CreditorRecognitionService::match()`: a UID decides (equal = same, different = never; one
     * another creditor carries = never), an IBAN linked to ANOTHER creditor rules it out, an IBAN
     * only this creditor holds decides (a shared IBAN decides nothing), else exactly the same name
     * (case and whitespace normalised — the adopted proposal's or the contact's).
     *
     * @param array<string, string> $party
     * @param array<string, string> $other
     * @return ?string `uid` | `iban` | `name`, null = not the same party (or in doubt)
     */
    private function sameParty(array $party, Contact $contact, CreditorProfile $profile, array $other): ?string
    {
        $partyUid = Uid::normalize($party['uid'] ?? '');
        $ourUid   = $profile->getUid() ?? (Uid::isWellFormed($partyUid) ? $partyUid : null);
        $otherUid = Uid::normalize($other['uid'] ?? '');
        $uidSame  = null;
        if ($otherUid !== '') {
            if ($ourUid !== null) {
                $uidSame = $otherUid === $ourUid;
            } else {
                $owner = $this->profiles()->findByUid($otherUid);
                if ($owner !== null && $owner->getId() !== $profile->getId()) {
                    return null;
                }
            }
        }
        if ($uidSame === false) {
            return null;
        }
        $iban     = Iban::normalize($other['iban'] ?? '');
        $ibanOurs = false;
        if ($iban !== '') {
            /** @var CreditorIbanRepository $ibans */
            $ibans = $this->em->getRepository(CreditorIban::class);
            $hits  = array_map(static fn(CreditorProfile $p): int => (int) $p->getId(), $ibans->findProfilesByIban($iban, false));
            if ($hits !== [] && !in_array((int) $profile->getId(), $hits, true)) {
                return null;   // the IBAN names another creditor
            }
            $ibanOurs = $hits === [(int) $profile->getId()];
        }
        if ($uidSame === true) {
            return 'uid';
        }
        if ($ibanOurs) {
            return 'iban';
        }
        $name  = ContactRepository::sameNameKey($other['name'] ?? '');
        $names = array_map([ContactRepository::class, 'sameNameKey'], [$party['name'] ?? '', $contact->getCompany(), $contact->displayName()]);

        return $name !== '' && in_array($name, $names, true) ? 'name' : null;
    }

    /**
     * One invoice adopted (the body of {@see adoptCreditor()}); $record goes into its recognition
     * record (`match`, and for a carried one where it came from).
     *
     * @param array<string, mixed> $record
     */
    private function adoptOne(int $id, int $version, Contact $contact, array $record): CreditorInvoice
    {
        $invoice = $this->find($id) ?? throw self::changed();
        if (!$invoice->isEditable() || $invoice->getVersion() !== $version) {
            throw self::changed();
        }
        $actor    = $this->actor();
        $profiles = new CreditorProfileService($this->em, $actor);
        $proposal = $invoice->proposal();
        try {
            $profile = $this->profiles()->findByContact($contact) ?? $profiles->open($contact);
            $rows    = array_map(static fn(CreditorIban $r) => ['iban' => $r->getIban(), 'hint' => $r->getHint(), 'active' => $r->isActive()], $profiles->ibansOf($profile));
            $add     = Iban::normalize($proposal['iban'] ?? '');
            $changes = [];
            if ($add !== '' && Iban::hasValidCheckDigits($add) && !in_array($add, array_column($rows, 'iban'), true)) {
                $rows[] = ['iban' => $add, 'hint' => '', 'active' => true];
                $changes['ibans'] = true;
            }
            if ($profile->getUid() === null && ($proposal['uid'] ?? '') !== '') {
                $changes['uid'] = $proposal['uid'];
            }
            if ($changes !== []) {
                $profiles->update($profile, $profile->getVersion(), isset($changes['uid']) ? ['uid' => $changes['uid']] : [], $rows);
                $profile = $this->profiles()->refreshed((int) $profile->getId()) ?? $profile;
            }
        } catch (InvalidCreditorProfileException $e) {
            throw new InvalidCreditorInvoiceException(['creditor' => 'Kreditor nicht übernommen: ' . $e->sentence()]);
        }

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $profile, $proposal, $actor, $record): CreditorInvoice {
            $invoice = $this->lockAt($id, $version);
            $invoice->assignCreditor($profile, $actor, new \DateTimeImmutable());
            $invoice->noteRecognition(array_merge($invoice->recognition(), $record));
            if ($invoice->getPaymentMethodCode() === null) {
                $payment = $this->paymentDefaults($profile);
                $invoice->applyPayment($payment['code'], $invoice->getCreditAccount() ?? $payment['credit']);
            }
            // The proposal's own account / tax code (stage 2 / 3) count only when the server accepts them.
            $ignored  = [];
            $fields   = new ReceiptFields($this->em);
            $proposed = ['account' => $fields->account($proposal['account'] ?? '', 'x', $ignored), 'taxCode' => $this->liableToVat() ? $fields->taxCode($proposal['tax_code'] ?? '', 'x', $ignored) : null];
            $defaults = $this->lineDefaults($profile);
            $rows     = [];
            $existing = $this->lines()->forInvoice($invoice);
            foreach ($existing as $line) {
                $decided = $line->vatDecided();
                // Owner 2026-11-17: the single EMPTY line takes the creditor's line text now it is known (as the recognition would have).
                $text    = $line->getText() === '' && count($existing) === 1
                    ? ($this->recognisedLineText($profile, null, InvoiceFacts::STEP_TEXT, [], (new CreditorLearningService($this->em, $actor))->textOf($id), true)[0] ?? '')
                    : $line->getText();
                $rows[]  = [
                    'text'       => $text,
                    'amount'     => $line->getAmount(),
                    'account'    => $line->getAccount() ?? $defaults['account'] ?? $proposed['account'],
                    'taxCode'    => $decided ? $line->getTaxCode() : ($defaults['taxCode'] ?? ($defaults['withoutVat'] ? null : $proposed['taxCode'])),
                    'withoutVat' => $decided ? $line->isWithoutVat() : ($defaults['withoutVat'] || (!$this->liableToVat())),
                ];
            }
            if ($invoice->getIban() === null && Iban::hasValidCheckDigits(Iban::normalize($proposal['iban'] ?? ''))) {
                $invoice->correct($profile, Iban::normalize($proposal['iban']), $invoice->getInvoiceNumber(), $invoice->getInvoiceDate(), $invoice->getDueDate(),
                    $invoice->getReferenceType(), $invoice->getReference(), $invoice->getMessage(), $invoice->getAmount(), $invoice->getOriginal(), $invoice->getPaymentMethodCode(), $invoice->getCreditAccount(), $actor, new \DateTimeImmutable());
            }
            $saved = $this->replaceLines($invoice, $rows);
            $this->checkDuplicate($invoice);
            if ($saved !== []) {
                $this->learnFrom($profile, $saved[0], $invoice->getPaymentMethodCode(), $actor);
            }
            (new CreditorLearningService($this->em, $actor))->learnFromInvoice($invoice, $profile);
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * The creditor learns what it has not yet from a saved first line and «Bezahlt mit» (owner
     * 2026-11-16) — {@see CreditorProfileService::learnDefaults()}; inside the invoice's unit of work.
     *
     * @return array{account?: string, taxCode?: ?string, withoutVat?: bool, paymentMethod?: string}
     */
    private function learnFrom(CreditorProfile $creditor, CreditorInvoiceLine $first, ?string $paymentMethodCode, string $actor): array
    {
        return (new CreditorProfileService($this->em, $actor))->learnDefaults(
            $creditor, $first->getAccount(), $first->getTaxCode(), $first->vatDecided() && $first->isWithoutVat(), $paymentMethodCode
        );
    }

    /**
     * The recognition's write (stage 2, plan §3 step 5): what ONE step read ($facts — text layer or
     * AI) fills only what is still EMPTY on the invoice, and the step is recorded per value in
     * `recognition` together with its state, confidence, warnings and notes. Server-initiated, so
     * no version is compared — the row is locked and read fresh, and an owner's value is never
     * overwritten (the AI may run after the owner has typed). A posted, deleted or vanished invoice
     * is left alone (null).
     *
     *   - creditor: the MATCHED one ($creditor, plan §5) when none is set — its defaults then fill
     *     «Bezahlt mit» and the single line's account and VAT; unmatched, the printed creditor
     *     becomes the PROPOSAL (`creditor_proposal`, «Kreditor übernehmen …») and a shared IBAN's
     *     candidates are listed — never a pick;
     *   - IBAN, the amount — in the base currency the CHF amount; in a currency of
     *     `ReceiptFields::foreignCurrencies()` the ORIGINAL amount + currency (stage A0 — the CHF
     *     the bank debits stays empty for the owner); any other currency a warning; only while
     *     neither is set —, the reference (QRR only with a QR-IBAN, SCOR never with one), message,
     *     invoice number, invoice / due date (due not before the invoice date);
     *   - the single line: its amount (the invoice amount) and text when empty — the text in the
     *     order of {@see recognisedLineText()} (owner 2026-11-17: the creditor's standard text, its
     *     learned column header, the step's own text, last the creditor's last saved text); a
     *     mandator NOT liable to VAT keeps every line «ohne MWST» (owner answer 5);
     *   - then the duplicate check on the recognised values (owner answer 4): blocked as 1b does.
     *
     * The TEXT step starts a run: the steps, warnings, notes and candidates of an earlier run go
     * (a re-run by «Jetzt erkennen» must not keep «nicht verfügbar»); `fields` stays — it still says
     * where the values came from. The LAST step of a run ($last) clears the pending mark.
     *
     * @param array<string, mixed> $step   the step's own record (state, reason, confidence, model)
     * @param list<array{id: int, name: string}> $candidates a shared IBAN's creditors (no pick)
     * @param list<string> $notes German sentences for the row
     * @param bool $last the run ends with this step (no AI step follows)
     * @param ?string $text the text layer the text step read — KEPT for learning per creditor
     *                      (owner 2026-11-16, {@see CreditorLearningService::keepText()}); null = none (a scan, no tool)
     */
    public function applyRecognition(int $id, string $stepKey, array $step, ?InvoiceFacts $facts, ?CreditorProfile $creditor = null, string $match = 'none', array $candidates = [], array $notes = [], bool $last = true, ?string $text = null): ?CreditorInvoice
    {
        $actor = $this->actor();

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $stepKey, $step, $facts, $creditor, $match, $candidates, $notes, $actor, $last, $text): ?CreditorInvoice {
            $row = $this->invoices()->lockRow($id);
            if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true)) {
                return null;
            }
            if ($stepKey === InvoiceFacts::STEP_TEXT && $text !== null) {
                (new CreditorLearningService($this->em, $actor))->keepText($id, $text);
            }
            $invoice  = $this->invoices()->refreshed($id) ?? throw self::changed();
            $record   = $invoice->recognition();
            if ($stepKey === InvoiceFacts::STEP_TEXT) {
                unset($record['steps'], $record['warnings'], $record['notes'], $record['candidates']);
            }
            $fields   = is_array($record['fields'] ?? null) ? $record['fields'] : [];
            $warnings = array_merge(is_array($record['warnings'] ?? null) ? $record['warnings'] : [], $facts->warnings ?? []);
            $notes    = array_merge(is_array($record['notes'] ?? null) ? $record['notes'] : [], $notes);
            $record['steps'][$stepKey] = $step + ['at' => (new \DateTimeImmutable())->format(DATE_ATOM)];
            // the step, or the finer source the step names for a field ('text-label', 'text-header')
            $sources  = $facts->sources ?? [];
            $filled   = static function (string $field) use (&$fields, $stepKey, $sources): void { $fields[$field] = $sources[$field] ?? $stepKey; };

            $current = $invoice->getCreditor();
            $target  = $current;
            if ($current === null && $creditor !== null && $creditor->isActive()) {
                $target = $creditor;
                $filled('creditor');
                $record['match'] = $match;
                unset($record['candidates']);
            } elseif ($current === null) {
                $record['match'] = $match;
                if ($candidates !== []) {
                    $record['candidates'] = $candidates;
                }
            }

            $iban     = $invoice->getIban();
            $amount   = $invoice->getAmount();
            $original = $invoice->getOriginal();
            [$refType, $reference] = [$invoice->getReferenceType(), $invoice->getReference()];
            $message = $invoice->getMessage();
            $number  = $invoice->getInvoiceNumber();
            $date    = $invoice->getInvoiceDate();
            $due     = $invoice->getDueDate();
            if ($facts !== null) {
                if ($iban === null && $facts->iban !== null && Iban::hasValidCheckDigits($facts->iban)) {
                    $iban = $facts->iban;
                    $filled('iban');
                }
                if ($amount === null && $original === null && $facts->amount !== null && $facts->amount->isPositive()) {
                    $currency = $facts->amount->currency;
                    if (($facts->currency ?? $currency) === ReceiptFields::currency() && $currency === ReceiptFields::currency()) {
                        $amount = $facts->amount;
                        $filled('amount');
                    } elseif (($facts->currency ?? $currency) === $currency && in_array($currency, ReceiptFields::foreignCurrencies(), true)) {
                        // stage A0: the printed amount in its currency; the CHF the bank debits is the owner's
                        $original = $facts->amount;
                        $filled('original_amount');
                    } else {
                        $warnings[] = 'Betrag in ' . ($facts->currency ?? $currency) . ' (' . $facts->amount->toDecimal() . ') — Währung nicht freigegeben (erlaubt: '
                            . implode(', ', ReceiptFields::foreignCurrencies()) . '); in ' . ReceiptFields::currency() . ' von Hand erfassen';
                    }
                }
                if ($reference === null && $facts->reference !== null && in_array($facts->referenceType, [CreditorInvoice::REFERENCE_QRR, CreditorInvoice::REFERENCE_SCOR], true)) {
                    $qrIban = $iban !== null && Iban::isQrIban($iban);
                    $valid  = $facts->referenceType === CreditorInvoice::REFERENCE_QRR
                        ? QrReference::isValid($facts->reference) && ($iban === null || $qrIban)
                        : ScorReference::isValid($facts->reference) && !$qrIban;
                    if ($valid && mb_strlen($facts->reference) <= CreditorInvoice::REFERENCE_LENGTH) {
                        [$refType, $reference] = [$facts->referenceType, $facts->referenceType === CreditorInvoice::REFERENCE_SCOR ? ScorReference::normalize($facts->reference) : $facts->reference];
                        $filled('reference');
                    } else {
                        $warnings[] = 'Referenz passt nicht zur IBAN — nicht übernommen';
                    }
                }
                if ($message === '' && $facts->message !== null && self::clean($facts->message) !== '') {
                    $message = mb_substr(self::clean($facts->message), 0, CreditorInvoice::MESSAGE_LENGTH);
                    $filled('message');
                }
                if ($number === null && $facts->invoiceNumber !== null && self::clean($facts->invoiceNumber) !== '' && mb_strlen(self::clean($facts->invoiceNumber)) <= CreditorInvoice::NUMBER_LENGTH) {
                    $number = self::clean($facts->invoiceNumber);
                    $filled('invoice_number');
                }
                if ($date === null && $facts->invoiceDate !== null && ($due === null || $due >= $facts->invoiceDate)) {
                    $date = $facts->invoiceDate;
                    $filled('invoice_date');
                }
                if ($due === null && $facts->dueDate !== null && ($date === null || $facts->dueDate >= $date)) {
                    $due = $facts->dueDate;
                    $filled('due_date');
                }
                if ($target === null && $invoice->proposal() === [] && ($facts->proposal['name'] ?? '') !== '') {
                    $invoice->proposeCreditor($facts->proposal);
                    $filled('proposal');
                    if (isset($sources['proposal_street'])) {
                        $filled('proposal_street');   // the street came from the letterhead, not the slip
                    }
                } elseif ($target === null && $invoice->proposal() !== [] && $facts->proposal !== []) {
                    // COMPLETE an earlier step's proposal (owner 2026-11-15, cyon: the QR slip names
                    // «cyon AG, 4052 Basel» without a street, the AI reads the street from the letterhead
                    // — it used to be dropped because a proposal already existed). Only for the SAME
                    // party (name, or postcode and town, equal) and only the parts still empty.
                    $current = $invoice->proposal();
                    $same    = static fn(string $key): bool => ($current[$key] ?? '') !== ''
                        && mb_strtolower(self::clean((string) $current[$key])) === mb_strtolower(self::clean((string) ($facts->proposal[$key] ?? '')));
                    if ($same('name') || ($same('zip') && $same('city'))) {
                        $added = array_filter(
                            $facts->proposal,
                            static fn($value, $key): bool => is_scalar($value) && (string) $value !== '' && (string) ($current[$key] ?? '') === '',
                            ARRAY_FILTER_USE_BOTH
                        );
                        if ($added !== []) {
                            $invoice->proposeCreditor($current + $added);
                            foreach (array_keys($added) as $key) {
                                $filled('proposal_' . $key);
                            }
                        }
                    }
                }
                if ($facts->category !== null) {
                    $notes[] = 'KI: Aufwand «' . $facts->category . '» — das Konto wählst du (oder es kommt vom Kreditor)';
                }
            }

            $methodCode = $invoice->getPaymentMethodCode();
            $credit     = $invoice->getCreditAccount();
            if ($target !== null && $methodCode === null) {
                $payment    = $this->paymentDefaults($target);
                $methodCode = $payment['code'];
                $credit   ??= $payment['credit'];
            }
            $invoice->correct($target, $iban, $number, $date, $due, $refType, $reference, $message, $amount, $original, $methodCode, $credit, $actor, new \DateTimeImmutable());

            // The single line (the usual invoice): its amount, its text, and — for a matched
            // creditor — its defaults; several lines are the owner's split and stay untouched.
            $lines = $this->lines()->forInvoice($invoice);
            if (count($lines) === 1) {
                $line     = $lines[0];
                $defaults = $target !== null ? $this->lineDefaults($target) : ['account' => null, 'taxCode' => null, 'withoutVat' => false];
                $decided  = $line->vatDecided();
                $lineText = $line->getText();
                if ($lineText === '') {
                    // Owner 2026-11-17: standard text → learned column header → the step's text → last saved text.
                    $layer  = $stepKey === InvoiceFacts::STEP_TEXT && $text !== null ? $text : (new CreditorLearningService($this->em, $actor))->textOf($id);
                    $chosen = $this->recognisedLineText($target, $facts, $stepKey, $sources, $layer, $last);
                    if ($chosen !== null) {
                        [$lineText, $fields['line_text']] = $chosen;
                    }
                }
                $rows = [[
                    'text'       => $lineText,
                    'amount'     => $line->getAmount() ?? $amount,
                    'account'    => $line->getAccount() ?? $defaults['account'],
                    'taxCode'    => $decided ? $line->getTaxCode() : $defaults['taxCode'],
                    'withoutVat' => $decided ? $line->isWithoutVat() : $defaults['withoutVat'],
                ]];
                if (!$this->liableToVat()) {
                    $rows[0]['taxCode']    = null;
                    $rows[0]['withoutVat'] = true;
                }
                $this->replaceLines($invoice, $rows);
            }

            $record['fields']   = $fields;
            $record['warnings'] = array_values(array_unique(array_map('strval', $warnings)));
            $record['notes']    = array_values(array_unique(array_map('strval', $notes)));
            $invoice->noteRecognition($record);
            if ($last) {
                $invoice->recognitionDone();
            }
            $this->checkDuplicate($invoice);
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * «Jetzt erkennen» (owner 2026-11-15): mark an OPEN or BLOCKED invoice «Erkennung ausstehend» —
     * the job `creditor-intake` recognises it (the caller queues the job). No version compare:
     * the recognition fills only empty fields, so a re-run can overwrite nothing the owner typed.
     *
     * @throws CreditorRefusedException CHANGED — gone, or posted meanwhile
     */
    public function queueRecognition(int $id): CreditorInvoice
    {
        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id): CreditorInvoice {
            $row = $this->invoices()->lockRow($id);
            if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true)) {
                throw new CreditorRefusedException(CreditorRefusedException::CHANGED, 'Diese Rechnung ist verbucht oder gelöscht — nichts mehr zu erkennen. Das Kreditorenjournal neu laden.');
            }
            $invoice = $this->invoices()->refreshed($id) ?? throw self::changed();
            $invoice->queueRecognition(new \DateTimeImmutable());
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * The job gives a run up (the PDF unreadable, the recognition threw): the pending mark goes, the
     * reason is a note on the row and the step `run` is «failed» — «Jetzt erkennen» stays offered.
     * Null when the invoice is gone or posted.
     */
    public function endRecognition(int $id, string $note): ?CreditorInvoice
    {
        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $note): ?CreditorInvoice {
            $row = $this->invoices()->lockRow($id);
            if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true)) {
                return null;
            }
            $invoice = $this->invoices()->refreshed($id) ?? throw self::changed();
            $record  = $invoice->recognition();
            $record['steps']['run'] = ['state' => 'failed', 'reason' => $note, 'at' => (new \DateTimeImmutable())->format(DATE_ATOM)];
            $record['notes']        = array_values(array_unique(array_merge(array_map('strval', (array) ($record['notes'] ?? [])), [$note])));
            $invoice->noteRecognition($record);
            $invoice->recognitionDone();
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * The line rows as a form hands them over → the clean list {@see update()} judges: strings
     * only, a row without text, account and amount dropped (the empty «+ Zeile» row, a row the
     * user emptied to remove it).
     *
     * @param array<mixed> $rows
     * @return list<array{text: string, account: string, tax: string, amount: string}>
     */
    public static function lineRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $clean = [];
            foreach (['text', 'account', 'tax', 'amount'] as $key) {
                $clean[$key] = is_scalar($row[$key] ?? null) ? trim((string) $row[$key]) : '';
            }
            if ($clean['text'] === '' && $clean['account'] === '' && $clean['amount'] === '') {
                continue;
            }
            $out[] = $clean;
        }

        return $out;
    }

    /**
     * Each row judged; the creditor's defaults and the VAT liability applied. At least ONE line
     * stays (an emptied invoice keeps an empty line on the defaults).
     *
     * @param list<array{text: string, account: string, tax: string, amount: string}> $rows
     * @param array<string, string> $errors
     * @return list<array{text: string, amount: ?Money, account: ?string, taxCode: ?string, withoutVat: bool}>
     */
    private function judgeLines(array $rows, ?CreditorProfile $creditor, ?string $credit, ReceiptFields $fields, array &$errors): array
    {
        if ($rows === []) {
            $rows = [['text' => '', 'account' => '', 'tax' => '', 'amount' => '']];
        }
        $defaults = $creditor !== null ? $this->lineDefaults($creditor) : ['account' => null, 'taxCode' => null, 'withoutVat' => false];
        $liable   = $this->liableToVat();
        $out      = [];
        foreach ($rows as $i => $row) {
            $text    = $fields->text($row['text'], "line_{$i}_text", $errors);
            $account = $fields->account($row['account'], "line_{$i}_account", $errors);
            $amount  = $row['amount'] === '' ? null : $fields->amount($row['amount'], "line_{$i}_amount", $errors);
            $without = $row['tax'] === self::WITHOUT_VAT;
            $code    = $without ? null : $fields->taxCode($row['tax'], "line_{$i}_tax", $errors);
            if (!$liable && $code !== null) {
                $errors["line_{$i}_tax"] = 'Der Mandant ist nicht MWST-pflichtig — jede Zeile ist «ohne MWST».';
                $code = null;
            }
            if ($row['account'] === '' && $account === null) {
                $account = $defaults['account'];
            }
            if ($row['tax'] === '' && $code === null && !$without) {
                $code    = $defaults['taxCode'];
                $without = $defaults['withoutVat'];
            }
            if (!$liable) {
                $code    = null;
                $without = true;
            }
            if ($account !== null && $account === $credit) {
                $errors["line_{$i}_account"] = 'Konto und Haben sind dasselbe Konto.';
            }
            $out[] = ['text' => $text, 'amount' => $amount, 'account' => $account, 'taxCode' => $code, 'withoutVat' => $without];
        }

        return $out;
    }

    /**
     * The invoice's lines replaced by $rows (position = order) — inside the unit of work.
     *
     * @param list<array{text: string, amount: ?Money, account: ?string, taxCode: ?string, withoutVat: bool}> $rows
     * @return list<CreditorInvoiceLine>
     */
    private function replaceLines(CreditorInvoice $invoice, array $rows): array
    {
        foreach ($this->lines()->forInvoice($invoice) as $line) {
            $this->em->remove($line);
        }
        $saved = [];
        foreach (array_values($rows) as $position => $row) {
            $line = new CreditorInvoiceLine($invoice, $position, $row['text'], $row['amount'], $row['account'], $row['taxCode'], $row['withoutVat'] && $row['taxCode'] === null);
            $this->em->persist($line);
            $saved[] = $line;
        }

        return $saved;
    }

    /** Mark or clear the suspected duplicate (owner answer 4) — inside the unit of work. */
    private function checkDuplicate(CreditorInvoice $invoice): void
    {
        $match = $this->invoices()->findDuplicate(
            (int) $invoice->getId(),
            $invoice->getCreditor()?->getId(),
            $invoice->getInvoiceNumber(),
            $invoice->getIban(),
            $invoice->getReferenceType() === CreditorInvoice::REFERENCE_NONE ? null : $invoice->getReference(),
            $invoice->getDuplicateClearedOf(),
        );
        if ($match === null) {
            if ($invoice->isBlocked()) {
                $invoice->clearDuplicateMark();
            }

            return;
        }
        $other = $this->invoices()->find($match['id']);
        $name  = $other?->getCreditor()?->getContact()?->displayName();
        $invoice->markDuplicate($match['id'], 'mögliches Doppel von ' . ($other?->label() ?? 'Rechnung ' . $match['id']) . ($name !== null ? ' (' . $name . ')' : '')
            . ' — ' . ($match['by'] === 'number' ? 'gleicher Kreditor und gleiche Rechnungs-Nr.' : 'gleiche IBAN und gleiche Referenz'));
    }

    /**
     * The reference and its type: empty = NON; a valid QR reference (27 digits) = QRR — only with
     * a QR-IBAN; a valid ISO 11649 reference = SCOR — never with a QR-IBAN (the QR-bill rules).
     *
     * @param array<string, string> $errors
     * @return array{0: string, 1: ?string}
     */
    private static function reference(string $value, string $iban, array &$errors): array
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $value));
        if ($compact === '') {
            return [CreditorInvoice::REFERENCE_NONE, null];
        }
        $qrIban = $iban !== '' && Iban::isQrIban($iban);
        if (QrReference::isValid($compact)) {
            if ($iban !== '' && !$qrIban) {
                $errors['reference'] = 'Eine QR-Referenz gehört zu einer QR-IBAN — die IBAN prüfen.';
            }

            return [CreditorInvoice::REFERENCE_QRR, $compact];
        }
        if (ScorReference::isValid($compact)) {
            if ($qrIban) {
                $errors['reference'] = 'Zu einer QR-IBAN gehört eine QR-Referenz (27 Ziffern), keine RF-Referenz.';
            }

            return [CreditorInvoice::REFERENCE_SCOR, ScorReference::normalize($compact)];
        }
        $errors['reference'] = 'Keine gültige Referenz — eine QR-Referenz (27 Ziffern) oder eine RF-Referenz (RF…).';

        return [CreditorInvoice::REFERENCE_NONE, null];
    }

    /**
     * «Bezahlt am»: a valid day, never in the future (it is the posting date — a payment that has
     * not happened yet is not booked). '' = unpaid, unless $required.
     *
     * @param array<string, string> $errors
     */
    private function paymentDate(string $value, bool $required, array &$errors): ?\DateTimeImmutable
    {
        if ($value === '') {
            if ($required) {
                $errors['paid_on'] = 'Bezahlt am: das Zahlungsdatum fehlt.';
            }

            return null;
        }
        $date = $this->optionalDate($value, 'paid_on', 'Bezahlt am', $errors);
        if ($date !== null && $date > new \DateTimeImmutable('today')) {
            $errors['paid_on'] = 'Bezahlt am: liegt in der Zukunft — erst nach der Zahlung setzen.';

            return null;
        }

        return $date;
    }

    /** @param array<string, string> $errors */
    private function optionalDate(string $value, string $field, string $label, array &$errors): ?\DateTimeImmutable

    {
        if ($value === '') {
            return null;
        }
        $date = ManualEntryForm::parseDate($value);
        if ($date === null) {
            $errors[$field] = $label . ': ein gültiges Datum (JJJJ-MM-TT).';
        }

        return $date;
    }

    /**
     * Take bank movement $movementId for the invoice (ADR-061 decisions 9, 11, 13 — stage C1).
     * ONE unit of work, the invoice row locked first, the movement row second
     * (`BankLinkService::link()`): the link is stored with the movement's full amount; an
     * UNPOSTED invoice gets «Bezahlt am» = the bank's BOOKING date and — in foreign currency — the
     * CHF amount the bank debited (its single line follows; the rate stays derived, so it is the
     * bank's effective one), and «Bezahlt mit» when exactly one active payment method hangs on
     * the movement's bank account and the invoice names none of that account. A POSTED invoice is
     * linked WITHOUT any change and without a posting (decision 2).
     *
     * The server judges the pair again, whatever the screen proposed: the movement must be free
     * (a debit, unlinked, on an account that takes statements); a CHF invoice needs its amount
     * and it must equal the movement's; a foreign-currency invoice must agree with the movement's
     * original amount and currency where the bank sent them; a posted foreign-currency invoice
     * must have been posted with the movement's CHF. A blocked invoice is decided first.
     *
     * @param string $basis        `BankLink::BASIS_*` — how certain the caller is
     * @param bool $learnBankText  a movement picked by hand teaches the creditor its «Banktext»
     *                             (the merchant after «Bezugsort:») while it has none
     * @throws CreditorRefusedException CHANGED (gone, another version) · BANK (German: why not)
     */
    public function takeMovement(int $id, int $version, int $movementId, string $basis, bool $learnBankText = false): CreditorInvoice
    {
        $actor = $this->actor();
        $bank  = static fn(string $message): CreditorRefusedException => new CreditorRefusedException(CreditorRefusedException::BANK, $message);
        try {
            return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $movementId, $basis, $learnBankText, $actor, $bank): CreditorInvoice {
                $row = $this->invoices()->lockRow($id);
                if ($row === null || $row['version'] !== $version) {
                    throw self::changed();
                }
                $invoice = $this->invoices()->refreshed($id) ?? throw self::changed();
                $label   = $invoice->label();
                if ($invoice->isBlocked()) {
                    throw $bank($label . ' ist gesperrt (mögliches Doppel) — zuerst entscheiden, dann die Zahlung zuordnen.');
                }
                $links    = new BankLinkService($this->em, $actor);
                $movement = $links->freeMovement($movementId);
                if ($movement === null || $movement->isCredit()) {
                    throw $bank('Diese Bankbewegung ist nicht mehr frei — sie ist inzwischen zugeordnet, oder ihr Bankkonto liest keine Kontoauszüge mehr ein. Bitte neu suchen.');
                }
                $chf  = $movement->getAmount();
                $date = $movement->getBookingDate()->format('d.m.Y');
                if ($invoice->isForeign()) {
                    $original = $invoice->getOriginal();
                    if ($movement->getOriginalAmount() !== null && $original !== null
                        && ($movement->getOriginalCurrency() !== $original->currency || !Money::fromDecimal($movement->getOriginalAmount(), $original->currency)->equals($original))) {
                        throw $bank($label . ' lautet auf ' . $invoice->originalLabel() . ', die Bankbewegung vom ' . $date . ' auf ' . $movement->getOriginalAmount() . ' ' . $movement->getOriginalCurrency() . ' — das ist nicht dieselbe Zahlung.');
                    }
                    if ($invoice->isPosted() && ($invoice->getAmount() === null || !$invoice->getAmount()->equals($chf))) {
                        throw $bank($label . ' ist mit ' . (string) $invoice->getAmount()?->toDecimal() . ' ' . $chf->currency . ' verbucht, die Bank hat am ' . $date . ' ' . $chf->toDecimal() . ' belastet — die Beträge müssen gleich sein.');
                    }
                } elseif ($invoice->getAmount() === null) {
                    throw $bank($label . ' hat noch keinen Betrag — zuerst den Betrag erfassen.');
                } elseif (!$invoice->getAmount()->equals($chf)) {
                    throw $bank($label . ' lautet auf ' . $invoice->getAmount()->toDecimal() . ' ' . $chf->currency . ', die Bankbewegung vom ' . $date . ' auf ' . $chf->toDecimal() . ' — die Beträge müssen gleich sein.');
                }
                if ($invoice->isForeign() && !$invoice->isPosted()) {
                    // Owner 2026-11-17: the bank's CHF becomes the amount — lines that would not add up
                    // to it (a split typed before) are refused, never saved as a mismatch. The single
                    // line that follows the amount (lineFollows()) is no mismatch.
                    $lines   = $this->lines()->forInvoice($invoice);
                    $follows = count($lines) === 1 && ($lines[0]->getAmount() === null || ($invoice->getAmount() !== null && $lines[0]->getAmount()->equals($invoice->getAmount())));
                    $differs = $follows ? null : self::lineSumMismatch($chf, array_map(static fn(CreditorInvoiceLine $l): ?Money => $l->getAmount(), $lines));
                    if ($differs !== null) {
                        throw $bank($label . ': die Bank hat am ' . $date . ' ' . $chf->currency . ' ' . $chf->toDecimal() . ' belastet; ' . $differs
                            . '. Zuerst die Zeilen auf ' . $chf->currency . ' ' . $chf->toDecimal() . ' anpassen, dann die Zahlung zuordnen.');
                    }
                }
                $links->link($movementId, CreditorInvoice::SOURCE_TYPE, $invoice->bankOwnerKey(), $invoice->bankLabel(), $basis);
                if (!$invoice->isPosted()) {
                    $before = $invoice->getAmount();
                    $invoice->payFromBank($movement->getBookingDate(), $invoice->isForeign() ? $chf : null, $actor, new \DateTimeImmutable());
                    if ($invoice->isForeign()) {
                        $this->lineFollows($invoice, $before, $chf);
                    }
                    $method = $this->bankPaymentMethod($movement->getBankAccount()->getCode(), $invoice->getPaymentMethodCode());
                    if ($method !== null) {
                        $invoice->applyPayment($method->getCode(), $method->getCreditAccount());
                    }
                    $this->em->persist($invoice);
                }
                $creditor = $invoice->getCreditor();
                if ($learnBankText && $creditor !== null && $creditor->getBankText() === null && $movement->getCounterpartyName() === ''
                    && preg_match('/Bezugsort:\s*([^,;\r\n]+)/u', $movement->getText(), $m)) {
                    (new CreditorProfileService($this->em, $actor))->learnBankText($creditor, $m[1]);
                }

                return $invoice;
            });
        } catch (BankRefusedException $e) {
            throw $bank($e->getMessage());
        }
    }

    /**
     * «Zuordnung lösen» (ADR-061 decision 20, stage C1): the invoice's link to its bank movement
     * is removed and the invoice is UNPAID again — one unit of work. The amounts stay as they
     * are (a foreign-currency invoice keeps the CHF the bank debited; it is an open field again).
     * A POSTED invoice is refused: its posting must be taken back first, and that reversing entry
     * comes with stage C2.
     *
     * @throws CreditorRefusedException CHANGED (gone, another version) · BANK (no link, or posted)
     */
    public function releaseMovement(int $id, int $version): CreditorInvoice
    {
        $actor = $this->actor();

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $actor): CreditorInvoice {
            $row = $this->invoices()->lockRow($id);
            if ($row === null || $row['version'] !== $version) {
                throw self::changed();
            }
            $invoice = $this->invoices()->refreshed($id) ?? throw self::changed();
            $links   = new BankLinkService($this->em, $actor);
            if ($links->ofOwner($invoice->bankOwnerKey()) === null) {
                throw new CreditorRefusedException(CreditorRefusedException::BANK, $invoice->label() . ' ist keiner Bankbewegung zugeordnet — nichts zu lösen.');
            }
            if ($invoice->isPosted()) {
                throw new CreditorRefusedException(
                    CreditorRefusedException::BANK,
                    $invoice->label() . ' ist verbucht (Buchung ' . $invoice->getLedgerEntryRef() . ') — die Zuordnung zur Bankbewegung lässt sich erst lösen, wenn die Buchung zurückgenommen ist. Das Zurücknehmen ist noch nicht gebaut.'
                );
            }
            $links->release($invoice->bankOwnerKey());
            $invoice->markPaid(null, $actor, new \DateTimeImmutable());
            $this->em->persist($invoice);

            return $invoice;
        });
    }

    /**
     * The single line of a foreign-currency invoice follows the CHF amount the bank debited —
     * while it carried no amount or the invoice's amount so far. Several lines are the owner's
     * split and stay (the invoice then names «Zeilen = Betrag»). Inside the unit of work.
     */
    private function lineFollows(CreditorInvoice $invoice, ?Money $before, Money $chf): void
    {
        $lines = $this->lines()->forInvoice($invoice);
        if (count($lines) !== 1 || ($lines[0]->getAmount() !== null && ($before === null || !$lines[0]->getAmount()->equals($before)))) {
            return;
        }
        $line = $lines[0];
        $this->replaceLines($invoice, [[
            'text' => $line->getText(), 'amount' => $chf, 'account' => $line->getAccount(), 'taxCode' => $line->getTaxCode(), 'withoutVat' => $line->isWithoutVat(),
        ]]);
    }

    /**
     * «Bezahlt mit» for an invoice paid through bank account $bankAccountCode: the ONE active
     * payment method that hangs on it (ADR-060 decision 9) — null when the invoice already names
     * a method of that account, or when none or several hang on it (never a guess).
     */
    private function bankPaymentMethod(string $bankAccountCode, ?string $current): ?PaymentMethod
    {
        $onAccount = array_values(array_filter(
            (new PaymentMethodService($this->em))->active(),
            static fn(PaymentMethod $m) => $m->getBankAccountCode() === $bankAccountCode && $m->getCreditAccount() !== ''
        ));
        foreach ($onAccount as $method) {
            if ($method->getCode() === $current) {
                return null;
            }
        }

        return count($onAccount) === 1 ? $onAccount[0] : null;
    }

    /**
     * Lines that do not add up to the invoice's CHF amount (owner 2026-11-17: «Zeilen = Betrag» was
     * only a soft hint — a save closed the window and the owner never saw it). A sentence only when
     * the amount is set AND every line carries an amount AND their gross sum (incl. VAT) differs:
     * «Zeilen ergeben CHF 25.00, Rechnungstotal CHF 20.00 — Differenz CHF 5.00». A line without an
     * amount or an empty amount (a foreign invoice before the bank, stage A0) stays saveable as
     * incomplete — `CreditorInvoice::missing()` names it.
     *
     * @param list<?Money> $lineAmounts
     */
    public static function lineSumMismatch(?Money $amount, array $lineAmounts): ?string
    {
        if ($amount === null || $lineAmounts === [] || in_array(null, $lineAmounts, true)) {
            return null;
        }
        $sum = null;
        foreach ($lineAmounts as $line) {
            $sum = $sum === null ? $line : $sum->add($line);
        }
        if ($sum->equals($amount)) {
            return null;
        }
        $difference = $sum->subtract($amount);
        $difference = $difference->isNegative() ? $difference->negate() : $difference;

        return 'Zeilen ergeben ' . $amount->currency . ' ' . $sum->toDecimal() . ', Rechnungstotal ' . $amount->currency . ' ' . $amount->toDecimal()
            . ' — Differenz ' . $amount->currency . ' ' . $difference->toDecimal();
    }

    /** Whether a line field or the amount was refused — the sum is judged only on accepted values. @param array<string, string> $errors */
    private static function hasLineErrors(array $errors): bool
    {
        foreach (array_keys($errors) as $key) {
            if ($key === 'amount' || preg_match('/^line_\d+_amount$/', (string) $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text for an invoice's single EMPTY line during recognition (owner 2026-11-17), in this
     * order: (1) the creditor's STANDARD line text — always when set; (2) the line text its learned
     * column header reads in the text layer; (3) what this step read itself (the slip's message,
     * the AI's text); (4) only on the run's LAST step — no AI follows — the creditor's last saved
     * line text. Null = nothing.
     *
     * @param array<string, string> $sources the step's finer sources
     * @return array{0: string, 1: string}|null [text, source for `recognition.fields`]
     */
    private function recognisedLineText(?CreditorProfile $creditor, ?InvoiceFacts $facts, string $stepKey, array $sources, ?string $layer, bool $last): ?array
    {
        $pick = static fn(?string $text): string => mb_substr(self::clean((string) $text), 0, CreditorInvoiceLine::TEXT_LENGTH);
        if ($creditor !== null && $pick($creditor->getDefaultLineText()) !== '') {
            return [$pick($creditor->getDefaultLineText()), 'creditor-standard'];
        }
        if ($creditor !== null) {
            $read = $pick((new CreditorLearningService($this->em, $this->actor()))->lineTextAt($creditor, $layer));
            if ($read !== '') {
                return [$read, 'text-learned'];
            }
        }
        if ($pick($facts?->lineText) !== '') {
            return [$pick($facts->lineText), $sources['line_text'] ?? $stepKey];
        }
        if ($creditor !== null && $last && $pick($creditor->getLastLineText()) !== '') {
            return [$pick($creditor->getLastLineText()), 'creditor-last'];
        }

        return null;
    }

    /** Control characters to a space, trimmed. */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));
    }

    /** The row locked, compared (open or blocked, the version), then read fresh — inside the unit of work. */
    private function lockAt(int $id, int $version): CreditorInvoice
    {
        $row = $this->invoices()->lockRow($id);
        if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true) || $row['version'] !== $version) {
            throw self::changed();
        }

        return $this->invoices()->refreshed($id) ?? throw self::changed();
    }

    private static function changed(): CreditorRefusedException
    {
        return new CreditorRefusedException(
            CreditorRefusedException::CHANGED,
            'Die Rechnung wurde inzwischen geändert, verbucht oder gelöscht — nichts gespeichert. Das Kreditorenjournal neu laden und die Änderung nochmals anbringen.'
        );
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function invoices(): CreditorInvoiceRepository
    {
        return $this->em->getRepository(CreditorInvoice::class);
    }

    private function lines(): CreditorInvoiceLineRepository
    {
        return $this->em->getRepository(CreditorInvoiceLine::class);
    }

    private function profiles(): CreditorProfileRepository
    {
        return $this->em->getRepository(CreditorProfile::class);
    }
}
