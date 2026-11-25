<?php

namespace Z77\Module\Mandator\Validators;

use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Entities\VatConsideration;
use Z77\Module\Mandator\Entities\VatMethod;
use Z77\Module\Mandator\Entities\VatRhythm;
use Z77\Module\Mandator\Services\LedgerAccountCheck;
use Z77\Module\Mandator\Services\MandatorAccounts;
use Z77\Module\Mandator\Services\Uid;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Module\Mandator\Services\VatSettingsGuard;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see Mandator}.
 *
 * Letterhead: the name is required, everything else optional with the
 * column's length; country ISO 3166-1 alpha-2 upper-case, zip four digits
 * for CH (the `AddressValidator` rule), e-mail deliverable when set. The
 * logo is no field of this form (the logo window, `Logo\LogoService`; the
 * old path fields are gone since 2026-11-20).
 *
 * UID: required while liable to VAT (owner 2026-11-24), optional otherwise;
 * when set it must be the canonical `CHE-ddd.ddd.ddd` (the setter normalises
 * what it can) AND its modulo-11 check digit must hold — two messages, so a
 * typo and a wrong shape are told apart ({@see Uid}).
 *
 * VAT return settings (2026-11-24): shape only — see the section below.
 *
 * Accounts (E2): each optional — '' means «not set», refused at the point of
 * use by the reader that needs it, never here (an installation that never
 * duns needs no dunning-fee account). When set: digits, at most 10, and —
 * softly — postable in the bookkeeping: {@see LedgerAccountCheck} answers
 * `null` without a registered module-financial and the number is stored
 * unverified. A GROUP or an inactive account IS refused when financial is
 * there (`false`) — the message names the account and the field's label.
 * **Which fields are checked against the bookkeeping is the caller's
 * choice** (`$checkAccounts`): the service passes every key for a NEW
 * record and only the CHANGED keys for an update, so an unchanged number
 * survives a chart change (ADR-043 decision 19 on the account fields) while
 * a new reference must be valid; the screen passes every key to FLAG what
 * became invalid. Shape (digits, length) is always checked, and so are the
 * two cross-field rules: owed VAT and input tax never on the same account
 * (owner 2026-11-25, {@see validateVatAccountNotShared()}), and the year-end
 * VAT correction (2209) on an account of its own ({@see validateVatCorrectionAccountOwn()}).
 *
 * Without the check the account rules are format-only.
 *
 * The posted VAT returns (2026-11-25, mandator.md MANDATOR-VAT-GUARD-001): on an UPDATE the service
 * hands in a {@see VatSettingsGuard} holding the STORED settings; after the field rules the guard
 * asks the module that keeps the returns whether the draft's settings may replace them, and each
 * refusal stands at the field it names (a field that already carries an error keeps its own). No
 * guard (a new record, the screen's un-run validator, a test) — no question.
 */
class MandatorValidator extends EntityValidator
{
    /**
     * @param list<string>|null $checkAccounts account keys to check against the bookkeeping; null = all
     * @param VatSettingsGuard|null $vatGuard the stored VAT settings and who to ask about changing them; null = not asked
     */
    public function __construct(
        Mandator $mandator,
        private ?LedgerAccountCheck $ledger = null,
        private ?array $checkAccounts = null,
        private ?VatSettingsGuard $vatGuard = null,
    ) {
        parent::__construct($mandator);
    }

    /** The field rules, then — on a full run with a guard — the refusals of the posted VAT returns. */
    protected function executeValidation(?array $only = null): void
    {
        parent::executeValidation($only);
        if ($this->vatGuard === null || $only !== null) {
            return;
        }
        $byGuard = [];
        foreach ($this->vatGuard->refusals(VatSettings::of($this->entity)) as [$field, $message]) {
            // The VAT return's fields sit in a panel the switch hides while it is off — their refusal
            // then stands at the switch, so it is seen.
            if ($field !== null && str_starts_with($field, 'vat_') && !$this->entity->isLiableToVat()) {
                $field = 'liable_to_vat';
            }
            if ($field === null) {
                $this->addError($message);
            } elseif (isset($byGuard[$field])) {
                $this->addFieldError($field, $this->getFieldError($field) . ' ' . $message);
            } elseif (!$this->hasFieldError($field)) {
                $this->addFieldError($field, $message);
                $byGuard[$field] = true;
            }
        }
    }

    public function validateName(string $name): void
    {
        $this->validate('name', 'Name', $name)->notEmpty()->maxLength(Mandator::NAME_LENGTH);
    }

    public function validateAddressSuffixOne(string $line): void
    {
        $this->validate('address_suffix_one', 'Adresszusatz 1', $line)->maxLength(Mandator::SUFFIX_LENGTH);
    }

    public function validateAddressSuffixTwo(string $line): void
    {
        $this->validate('address_suffix_two', 'Adresszusatz 2', $line)->maxLength(Mandator::SUFFIX_LENGTH);
    }

    public function validateStreet(string $street): void
    {
        $this->validate('street', 'Strasse', $street)->maxLength(Mandator::STREET_LENGTH);
    }

    public function validateHouseNo(string $houseNo): void
    {
        $this->validate('house_no', 'Hausnummer', $houseNo)->maxLength(Mandator::HOUSE_NO_LENGTH);
    }

    public function validateZip(string $zip): void
    {
        $this->validate('zip', 'PLZ', $zip)->maxLength(Mandator::ZIP_LENGTH);
        if ($zip === '' || $this->hasFieldError('zip')) {
            return;
        }
        if ($this->entity->getCountry() === 'CH') {
            if (!preg_match('/^\d{4}$/', $zip)) {
                $this->addFieldError('zip', 'Eine Schweizer PLZ hat vier Ziffern.');
            }
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{1,8}[A-Za-z0-9]$/', $zip)) {
            $this->addFieldError('zip', 'PLZ: 3–10 Zeichen, Buchstaben, Ziffern, Leerzeichen oder Bindestrich.');
        }
    }

    public function validateCity(string $city): void
    {
        $this->validate('city', 'Ort', $city)->maxLength(Mandator::CITY_LENGTH);
    }

    public function validateCountry(string $country): void
    {
        $this->validate('country', 'Land', $country)->notEmpty();
        if (!$this->hasFieldError('country') && !preg_match('/^[A-Z]{2}$/', $country)) {
            $this->addFieldError('country', 'Land ist der zweistellige ISO-Code in Grossbuchstaben (z.B. CH).');
        }
    }

    public function validateEmail(string $email): void
    {
        if ($email === '') {
            return;
        }
        $this->validate('email', 'E-Mail', $email)->maxLength(Mandator::EMAIL_LENGTH)->isEmail();
    }

    public function validatePhone(string $phone): void
    {
        $this->validate('phone', 'Telefon', $phone)->maxLength(Mandator::PHONE_LENGTH);
    }

    public function validateWebsite(string $website): void
    {
        $this->validate('website', 'Website', $website)->maxLength(Mandator::WEBSITE_LENGTH);
        if ($website !== '' && !$this->hasFieldError('website') && preg_match('/\s/u', $website)) {
            $this->addFieldError('website', 'Website: eine Adresse ohne Leerzeichen (z.B. www.firma.ch).');
        }
    }

    /** The note a document without VAT prints (owner 2026-10-22): optional, at most 255 characters. */
    public function validateVatExemptNote(string $note): void
    {
        $this->validate('vat_exempt_note', 'Hinweis ohne MWST', $note)->maxLength(Mandator::VAT_EXEMPT_NOTE_LENGTH);
    }

    /** The default «Betreffname» of new documents (owner 2026-10-21): optional, at most 80 characters. */
    public function validateDefaultSubjectLabel(string $label): void
    {
        $this->validate('default_subject_label', 'Betreffname', $label)->maxLength(Mandator::SUBJECT_LABEL_LENGTH);
    }

    /**
     * UID: required while the mandator is liable to VAT (owner 2026-11-24, MANDATOR-PDF-UID-001 — an
     * invoice of a liable supplier must carry the number, Art. 26 para. 2 lit. a MWSTG), optional
     * otherwise. A stored liable record without a UID keeps loading and printing; the rule bites on
     * its next save. When set: the canonical shape, then the check digit.
     */
    public function validateUid(string $uid): void
    {
        if ($uid === '') {
            if ($this->entity->isLiableToVat()) {
                $this->addFieldError('uid', 'Für einen MWST-pflichtigen Mandanten ist die UID erforderlich (CHE-123.456.789).');
            }
            return;
        }
        $this->validate('uid', 'UID', $uid)->maxLength(Mandator::UID_LENGTH);
        if ($this->hasFieldError('uid')) {
            return;
        }
        if (!Uid::isWellFormed($uid)) {
            $this->addFieldError('uid', 'UID: CHE und neun Ziffern, geschrieben CHE-123.456.789.');
            return;
        }
        if (!Uid::hasValidCheckDigit($uid)) {
            $this->addFieldError('uid', 'Die Prüfziffer der UID stimmt nicht — bitte Ziffer für Ziffer vergleichen.');
        }
    }

    public function validateAccountReceivable(string $number): void       { $this->validateAccount('receivable', $number); }
    public function validateAccountDiscount(string $number): void         { $this->validateAccount('discount', $number); }
    public function validateAccountLoss(string $number): void             { $this->validateAccount('loss', $number); }
    public function validateAccountRounding(string $number): void         { $this->validateAccount('rounding', $number); }
    public function validateAccountDunningFee(string $number): void       { $this->validateAccount('dunning-fee', $number); }
    public function validateAccountVatInputMaterial(string $number): void { $this->validateAccount('vat-input-material', $number); $this->validateVatAccountNotShared('vat-input-material', $number); }
    public function validateAccountVatInputOther(string $number): void    { $this->validateAccount('vat-input-other', $number); $this->validateVatAccountNotShared('vat-input-other', $number); }
    public function validateAccountVatOwed(string $number): void          { $this->validateAccount('vat-owed', $number); $this->validateVatAccountNotShared('vat-owed', $number); }
    public function validateAccountPrivate(string $number): void          { $this->validateAccount('private', $number); }
    public function validateAccountBankTransfer(string $number): void     { $this->validateAccount('bank-transfer', $number); }
    public function validateAccountVatSettlement(string $number): void    { $this->validateAccount('vat-settlement', $number); }
    public function validateAccountVatRounding(string $number): void      { $this->validateAccount('vat-rounding', $number); }
    public function validateAccountPaymentCharges(string $number): void   { $this->validateAccount('payment-charges', $number); }
    public function validateAccountVatInputPending(string $number): void  { $this->validateAccount('vat-input-pending', $number); }
    public function validateAccountVatCorrectionPending(string $number): void { $this->validateAccount('vat-correction-pending', $number); $this->validateVatCorrectionAccountOwn($number); }

    // ── the VAT return's settings (ADR-041 addendum 2026-11-24) ─────────
    //
    // NOT required on save, liable or not: «not set» is refused by the return and asked by the setup
    // check while the mandator is liable — an existing liable installation keeps saving its letterhead.
    // What IS checked: a known value per select, a readable date, «bis» not before «ab». «Abrechnung
    // ab» may be ANY day (a period start or the day the liability began — the grid is the return's
    // period calculator's). «Rhythmus gilt ab» (2026-11-25, ADR-041 addendum point 1) is checked for its
    // SHAPE here — a 1 January, after «Abrechnung ab», with an earlier rhythm to keep; whether every
    // period before it is posted is for the posted returns to say ({@see VatSettingsGuard}, asked in
    // executeValidation()), which also decide what else may change once a return is posted.

    public function validateVatMethod(?string $method): void
    {
        if ($method !== null && VatMethod::tryFrom($method) === null) {
            $this->addFieldError('vat_method', 'Abrechnungsmethode: bitte aus der Liste wählen.');
        }
    }

    public function validateVatConsideration(?string $consideration): void
    {
        if ($consideration !== null && VatConsideration::tryFrom($consideration) === null) {
            $this->addFieldError('vat_consideration', 'Abrechnungsart: bitte aus der Liste wählen.');
        }
    }

    public function validateVatSettlementRhythm(?string $rhythm): void
    {
        if ($rhythm !== null && VatRhythm::tryFrom($rhythm) === null) {
            $this->addFieldError('vat_settlement_rhythm', 'Abrechnungsrhythmus: bitte Quartal, Monat oder Jahr wählen.');
        }
    }

    /** «Abrechnung ab»: what the form posted and the entity could not read as a date (`Mandator::setVatSettlementFrom()`). */
    public function validateVatSettlementFromInput(string $raw): void
    {
        if ($raw !== '') {
            $this->addFieldError('vat_settlement_from', 'Abrechnung ab: «' . $raw . '» ist kein Datum (TT.MM.JJJJ).');
        }
    }

    public function validateVatSettlementUntilInput(string $raw): void
    {
        if ($raw !== '') {
            $this->addFieldError('vat_settlement_until', 'Abrechnung bis: «' . $raw . '» ist kein Datum (TT.MM.JJJJ).');
        }
    }

    public function validateVatRhythmFromInput(string $raw): void
    {
        if ($raw !== '') {
            $this->addFieldError('vat_rhythm_from', 'Rhythmus gilt ab: «' . $raw . '» ist kein Datum (TT.MM.JJJJ).');
        }
    }

    /**
     * «Rhythmus gilt ab» (ADR-041 addendum 2026-11-25, point 1): a change of the rhythm takes effect on
     * a 1 January only, after «Abrechnung ab», and only together with another rhythm — the record
     * keeps the one before for the earlier years ({@see Mandator::recordRhythmChange()}, run by the
     * service before this; no history left = nothing to keep). Empty = the rhythm since «Abrechnung ab».
     */
    public function validateVatRhythmFrom(?\DateTimeImmutable $day): void
    {
        if ($day === null || $this->entity->getVatRhythmFromInput() !== '') {
            return;
        }
        $from = $this->entity->getVatSettlementFrom();
        if ($day->format('m-d') !== '01-01') {
            $this->addFieldError('vat_rhythm_from', 'Rhythmus gilt ab: ein Wechsel gilt nur ab einem 1. Januar, z.B. 01.01.' . ((int) $day->format('Y') + 1) . '.');
        } elseif ($from === null || $day <= $from) {
            $this->addFieldError('vat_rhythm_from', 'Rhythmus gilt ab muss nach «Abrechnung ab» liegen — leer lassen, wenn der Rhythmus seit «Abrechnung ab» gilt.');
        } elseif ($this->entity->earlierVatRhythms() === []) {
            $this->addFieldError('vat_rhythm_from', 'Rhythmus gilt ab nur zusammen mit einem neuen Abrechnungsrhythmus — der bisherige gilt dann für die Jahre davor.');
        }
    }

    /** «Abrechnung bis» never before «Abrechnung ab» (the same day is a one-day liability, allowed). */
    public function validateVatSettlementUntil(?\DateTimeImmutable $until): void
    {
        $from = $this->entity->getVatSettlementFrom();
        if ($until !== null && $from !== null && $until < $from) {
            $this->addFieldError('vat_settlement_until', 'Abrechnung bis liegt vor «Abrechnung ab» (' . $from->format('d.m.Y') . ').');
        }
    }

    /**
     * Owed VAT and input tax on ONE account is refused (owner decision 2026-11-25, financial.md
     * FIN-VAT-ACCOUNTS-001): the VAT return reconciles each VAT account against the tax of its
     * categories with the sign of its role (owed: credit − debit, input: debit − credit), so a shared
     * account can never agree and «MWST buchen» would stay blocked for good. Netting per account was
     * not chosen. The two INPUT accounts may be the same — same role, same sign, the return sums
     * both categories on the one account. Each of the three fields checks itself against the other
     * role, so the sentence stands at every field involved whatever order the fields run in. Bites
     * on EVERY save, not only for a changed key: the conflict lies inside the record and the one
     * form corrects it; a stored record keeps loading, and the return says the same (blocking).
     * Empty stays allowed (see the class docblock).
     */
    private function validateVatAccountNotShared(string $key, string $number): void
    {
        $number = trim($number);
        $field  = Mandator::accountField($key);
        if ($number === '' || $this->hasFieldError($field)) {
            return;
        }
        $others = $key === 'vat-owed' ? ['vat-input-material', 'vat-input-other'] : ['vat-owed'];
        foreach ($others as $other) {
            if (trim($this->entity->account($other)) === $number) {
                $this->addFieldError($field, 'Konto ' . $number . ' ist zugleich für die geschuldete MWST und für die Vorsteuer hinterlegt — die beiden brauchen je ein eigenes Konto (z.B. 2200 und 1170).');
                return;
            }
        }
    }

    /**
     * The transit account of the year-end VAT correction (KMU 2209, ADR-042 addendum 3, point 4) is an
     * account of its OWN: never one of the three VAT accounts (the VAT return reconciles those and the
     * lock guards them — a hand-posted, uncoded line on 2209 would be refused there), never the
     * settlement or the rounding account, and never 1179 «Vorsteuer, noch nicht abgerechnet» (that one
     * stays exclusively for catching up input tax). Checked at the 2209 field on every save, like
     * {@see validateVatAccountNotShared()}: the conflict lies inside the record. Empty stays allowed.
     */
    private function validateVatCorrectionAccountOwn(string $number): void
    {
        $number = trim($number);
        $field  = Mandator::accountField('vat-correction-pending');
        if ($number === '' || $this->hasFieldError($field)) {
            return;
        }
        foreach (['vat-owed', 'vat-input-material', 'vat-input-other', 'vat-settlement', 'vat-rounding', 'vat-input-pending'] as $other) {
            if (trim($this->entity->account($other)) === $number) {
                $this->addFieldError($field, 'Konto ' . $number . ' ist bereits als «' . MandatorAccounts::LABELS[$other] . '» hinterlegt — die MWST-Korrektur braucht ein eigenes Konto (z.B. 2209).');
                return;
            }
        }
    }

    /** One rule for every account field — see the class docblock. */
    private function validateAccount(string $key, string $number): void
    {
        $number = trim($number);
        if ($number === '') {
            return;
        }
        $field = Mandator::accountField($key);
        $label = MandatorAccounts::LABELS[$key];
        $this->validate($field, $label, $number)->maxLength(Mandator::ACCOUNT_NUMBER_LENGTH);
        if ($this->hasFieldError($field)) {
            return;
        }
        if (!preg_match('/^[0-9]+$/', $number)) {
            $this->addFieldError($field, $label . ': nur Ziffern' . (isset(MandatorAccounts::DEFAULTS[$key]) ? ' (z.B. ' . MandatorAccounts::DEFAULTS[$key] . ')' : '') . '.');
            return;
        }
        if ($this->checkAccounts !== null && !in_array($key, $this->checkAccounts, true)) {
            return;   // an unchanged number is left alone (ADR-043 decision 19)
        }
        // Soft by construction: null = module-financial is not registered here.
        if ($this->ledger?->isPostable($number) === false) {
            $this->addFieldError($field, 'Konto ' . $number . ' für «' . $label . '» gibt es in der Buchhaltung nicht, es ist eine Gruppe oder inaktiv — leer lassen oder ein bebuchbares Konto wählen.');
        }
    }
}
