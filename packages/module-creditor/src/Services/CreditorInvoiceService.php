<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceLine;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Repositories\CreditorInvoiceLineRepository;
use Z77\Module\Creditor\Repositories\CreditorInvoiceRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Ui\ManualEntryForm;
use Z77\Module\Receipt\Entities\PaymentMethod;
use Z77\Module\Receipt\Services\PaymentMethodService;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\Resolver\UnifiedEntityManager;
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
 *   - the creditor's DEFAULTS fill what is empty: a line without account gets the default account,
 *     a line without VAT decision the default one, an invoice without «Bezahlt mit» the default
 *     payment method (and its credit account);
 *   - a mandator NOT liable to VAT (owner answer 5): every line is «ohne MWST» — an undecided line
 *     is set so, a line with a tax code is refused with a German sentence;
 *   - ONE line without amount takes the invoice amount (the usual one-line invoice);
 *   - the DUPLICATE CHECK (owner answer 4): the same creditor + invoice number, or the same IBAN +
 *     reference, on any other invoice → BLOCKED with «mögliches Doppel von …»; when the match is
 *     gone (a typo corrected) the mark goes too. A pair the owner released is not checked again;
 *   - the DEFAULTS-ONCE rule (plan §5): the first COMPLETE invoice of a creditor without defaults
 *     decides them (line 1's account and VAT, «Bezahlt mit») — later invoices never overwrite them,
 *     only the switch «als Standard übernehmen» ($takeAsDefault) or the creditor window does.
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
     * MWST» whenever the mandator is not liable (owner answer 5).
     *
     * @return array{account: ?string, taxCode: ?string, withoutVat: bool}
     */
    public function lineDefaults(CreditorProfile $creditor): array
    {
        if (!$this->liableToVat()) {
            return ['account' => $creditor->getDefaultAccount(), 'taxCode' => null, 'withoutVat' => true];
        }

        return ['account' => $creditor->getDefaultAccount(), 'taxCode' => $creditor->getDefaultTaxCode(), 'withoutVat' => $creditor->getDefaultTaxCode() === null && $creditor->isDefaultWithoutVat()];
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
     *                                     due_date, reference, message, amount, payment_method_code,
     *                                     credit_account, paid_on («bezahlt am»; '' = unpaid, the key
     *                                     ABSENT = unchanged) — strings as a form posts them
     * @param array<mixed> $lineRows       rows {text, account, tax (code, «-» or ''), amount} ({@see lineRows()})
     * @param bool $takeAsDefault          «als Standard übernehmen» — this invoice's account, VAT and
     *                                     «Bezahlt mit» become the creditor's defaults even when set
     * @throws InvalidCreditorInvoiceException field → German message; nothing written
     * @throws CreditorRefusedException CHANGED — gone, posted, or another version than $version
     */
    public function update(int $id, int $version, array $values, array $lineRows, bool $takeAsDefault = false): CreditorInvoice
    {
        $current = $this->find($id) ?? throw self::changed();
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

        if ($errors !== []) {
            throw new InvalidCreditorInvoiceException($errors);
        }
        $actor = $this->actor();

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $creditor, $iban, $number, $invoiceDate, $dueDate, $referenceType, $reference, $message, $amount, $methodCode, $credit, $lines, $takeAsDefault, $paidOn, $actor): CreditorInvoice {
            $invoice = $this->lockAt($id, $version);
            $invoice->correct(
                $creditor, $iban === '' ? null : $iban, $number === '' ? null : $number, $invoiceDate, $dueDate,
                $referenceType, $reference, $message, $amount, $methodCode === '' ? null : $methodCode, $credit, $actor, new \DateTimeImmutable()
            );
            if ($paidOn !== null && $paidOn['date']?->format('Y-m-d') !== $invoice->getPaidOn()?->format('Y-m-d')) {
                $invoice->markPaid($paidOn['date'], $actor, new \DateTimeImmutable());
            }
            $saved = $this->replaceLines($invoice, $lines);
            $this->checkDuplicate($invoice);
            if ($creditor !== null && $saved !== [] && $invoice->isComplete($saved) && ($takeAsDefault || $creditor->getDefaultsSetAt() === null)) {
                (new CreditorProfileService($this->em, $actor))->takeDefaults(
                    $creditor, $saved[0]->getAccount(), $saved[0]->getTaxCode(), $saved[0]->isWithoutVat(), $invoice->getPaymentMethodCode(), $takeAsDefault
                );
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
     * «Bezahlt am …» for several invoices at once (plan §6 — the ticked rows, `id:version` each, as
     * debtor's invoicing selection): the payment date for all of them and, when $methodCode is given,
     * «Bezahlt mit» with its credit account. One unit of work; the rows locked in id order. A row
     * changed, posted or deleted since the list showed it is NOT written and comes back in `refused`
     * (German, naming it) — the others are written.
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
            $paid    = [];
            $refused = [];
            $now     = new \DateTimeImmutable();
            foreach ($ids as $id) {
                $row = $locked[$id] ?? null;
                if ($row === null || !in_array($row['state'], [CreditorInvoice::STATE_OPEN, CreditorInvoice::STATE_BLOCKED], true) || $row['version'] !== $versions[$id]) {
                    $refused[] = 'Rechnung ' . $id . ': inzwischen geändert, verbucht oder gelöscht — nicht gesetzt';
                    continue;
                }
                $invoice = $this->invoices()->refreshed($id) ?? throw self::changed();
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
     * file stays refused («bereits eingelesen … als Doppel verworfen»). A posted invoice is refused.
     *
     * @return array{documentId: int, freed: bool} its PDF's DMS document id; whether the file hash was freed
     * @throws CreditorRefusedException CHANGED — gone, posted, or another version
     */
    public function delete(int $id, int $version): array
    {
        $actor = $this->actor();   // a delete is a write: who does it must be known

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $actor): array {
            $invoice = $this->lockAt($id, $version);
            foreach ($this->lines()->forInvoice($invoice) as $line) {
                $this->em->remove($line);
            }
            $documentId = $invoice->getDocumentId();
            $intake     = $invoice->getIntake();
            $discard    = $invoice->isBlocked();
            $label      = $invoice->label();
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
     * «Kreditor übernehmen …» (plan §5): the invoice's PROPOSED creditor becomes $contact's creditor
     * — opened when the contact is none yet (`CreditorProfileService::open()`), the proposed IBAN
     * and UID added when missing (the creditor's own unit of work, as the role registry does it);
     * then the invoice names it, empty lines and an empty «Bezahlt mit» take its defaults, and a
     * creditor without defaults takes them from this invoice's first line.
     *
     * @throws InvalidCreditorInvoiceException `creditor` — the contact cannot be a creditor, the proposal's UID or IBAN refused
     * @throws CreditorRefusedException CHANGED — gone, posted, or another version
     */
    public function adoptCreditor(int $id, int $version, Contact $contact): CreditorInvoice
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

        return $this->em->getTransaction(CreditorInvoice::class)->run(function () use ($id, $version, $profile, $proposal, $actor): CreditorInvoice {
            $invoice = $this->lockAt($id, $version);
            $invoice->assignCreditor($profile, $actor, new \DateTimeImmutable());
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
            foreach ($this->lines()->forInvoice($invoice) as $line) {
                $decided = $line->vatDecided();
                $rows[]  = [
                    'text'       => $line->getText(),
                    'amount'     => $line->getAmount(),
                    'account'    => $line->getAccount() ?? $defaults['account'] ?? $proposed['account'],
                    'taxCode'    => $decided ? $line->getTaxCode() : ($defaults['taxCode'] ?? ($defaults['withoutVat'] ? null : $proposed['taxCode'])),
                    'withoutVat' => $decided ? $line->isWithoutVat() : ($defaults['withoutVat'] || (!$this->liableToVat())),
                ];
            }
            if ($invoice->getIban() === null && Iban::hasValidCheckDigits(Iban::normalize($proposal['iban'] ?? ''))) {
                $invoice->correct($profile, Iban::normalize($proposal['iban']), $invoice->getInvoiceNumber(), $invoice->getInvoiceDate(), $invoice->getDueDate(),
                    $invoice->getReferenceType(), $invoice->getReference(), $invoice->getMessage(), $invoice->getAmount(), $invoice->getPaymentMethodCode(), $invoice->getCreditAccount(), $actor, new \DateTimeImmutable());
            }
            $saved = $this->replaceLines($invoice, $rows);
            $this->checkDuplicate($invoice);
            if ($saved !== [] && $profile->getDefaultsSetAt() === null && $saved[0]->getAccount() !== null && $saved[0]->vatDecided()) {
                (new CreditorProfileService($this->em, $actor))->takeDefaults(
                    $profile, $saved[0]->getAccount(), $saved[0]->getTaxCode(), $saved[0]->isWithoutVat(), $invoice->getPaymentMethodCode(), false
                );
            }
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
