<?php

namespace Z77\Module\Creditor\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorLearned;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Repositories\CreditorIbanRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Creditor\Validators\CreditorProfileValidator;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Payment\Iban;

/**
 * The write side of {@see CreditorProfile} and its {@see CreditorIban} rows — the rules that hold no
 * matter which screen or script writes (creditor plan §2.2, §5; ADR-039 decision 9):
 *
 *   - {@see open()} — THE way a contact becomes a creditor (the contact editor's role «Kreditor»;
 *     later «Kreditor übernehmen …» of the creditor journal, stage 1c/2): an active contact that is
 *     no creditor yet; no defaults, no IBAN;
 *   - {@see update()} — the creditor window: UID, the defaults, the IBANs, with the VERSION the
 *     window showed. Validated on a detached draft FIRST (a refused change never reaches a flush),
 *     then one unit of work: the row locked `FOR UPDATE`, the version compared, the profile read
 *     fresh, the values applied, the IBAN rows synchronised (kept by IBAN, added, removed), the
 *     version bumped;
 *   - the DEFAULTS STAMP (plan §5): a change of the defaults through the window stamps
 *     `defaults_set_at` / `defaults_set_by` (they are decided now — an invoice never overwrites
 *     them); emptying every default clears the stamp (the next saved invoice fills them again);
 *   - {@see setActive()} — the list's switch; {@see delete()} — the role «Kreditor» off: the
 *     profile and its IBANs go — only while no invoice names the creditor, else refused («nur
 *     deaktivieren», the debtor rule of 2026-10-12);
 *   - {@see learnDefaults()} — what the creditor does not have yet (account + VAT, «Bezahlt mit»)
 *     learned from a saved invoice (owner 2026-11-16); {@see takeDefaults()} — «als Standard
 *     übernehmen» overwrites them on purpose (plan §5).
 *
 * Who: the actor (`defaults_set_by`) is the caller's, or the session's ({@see Actor::current()}) —
 * resolved only when a stamp is written.
 */
final class CreditorProfileService
{
    private ?string $actor;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    /**
     * Makes $contact a CREDITOR: a new, empty profile (no defaults, no IBAN).
     *
     * @throws InvalidCreditorProfileException inactive contact, already a creditor
     */
    public function open(Contact $contact): CreditorProfile
    {
        $profile = new CreditorProfile();
        $profile->setContact($contact);
        $this->assertValid($profile, null, null);

        $this->refuseOnRace($profile, [], function () use ($profile): void {
            $this->em->getTransaction(CreditorProfile::class)->run(function () use ($profile): void {
                $this->em->persist($profile);
            });
        });

        return $profile;
    }

    /**
     * Change an EXISTING profile: $values (snake_case keys as the body cleaner produces them — uid,
     * default_account, default_tax_code, default_without_vat, default_payment_method_code) and the
     * complete list of its IBAN rows ({@see ibanRows()}). The contact and `active` are not part of it.
     *
     * @param array<string, mixed> $values
     * @param list<array{iban: string, hint: string, active: bool}> $ibanRows
     * @throws InvalidCreditorProfileException carries the validator, the draft and the rows (for the window)
     * @throws CreditorRefusedException CHANGED — gone, or another version than $version
     */
    public function update(CreditorProfile $profile, int $version, array $values, array $ibanRows): void
    {
        $id = $profile->getId();
        if ($id === null) {
            throw new \LogicException('update() takes an existing creditor profile — a new one goes through open()');
        }
        unset($values['id'], $values['contact'], $values['contact_id'], $values['active'], $values['version'], $values['defaults_set_at'], $values['defaults_set_by'], $values['last_line_text']);
        $ibanRows = self::ibanRows($ibanRows);

        if ($profile->getVersion() !== $version) {
            throw self::changed();
        }
        $draft = clone $profile;
        $draft->mapFromArray($values);
        $this->assertValid($draft, $profile, $ibanRows);

        $this->refuseOnRace($draft, $ibanRows, function () use ($id, $version, $values, $ibanRows): void {
            $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $version, $values, $ibanRows): void {
                $current = $this->lockAt($id, $version);
                $before  = $current->defaultsKey();
                $current->mapFromArray($values);
                if ($current->defaultsKey() !== $before) {
                    $current->hasDefaultValues() ? $current->stampDefaults($this->actor(), new \DateTimeImmutable()) : $current->clearDefaultsStamp();
                }
                $this->syncIbans($current, $ibanRows);
                $current->bumpVersion();
            });
        });
    }

    /** The list's «Aktiv» switch — one write, the version bumped. */
    public function setActive(CreditorProfile $profile, bool $active): void
    {
        $id = $profile->getId() ?? throw new \LogicException('setActive() takes an existing creditor profile');
        $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $active): void {
            $current = $this->lockAt($id, null);
            if ($current->isActive() !== $active) {
                $current->setActive($active);
                $current->bumpVersion();
            }
        });
    }

    /**
     * The defaults taken from an INVOICE (plan §5) as a whole: without $overwrite only while no
     * default is decided (stamp NULL); with $overwrite («als Standard übernehmen», the owner's
     * explicit switch) they replace defaults already set. The invoice save learns the missing parts
     * through {@see learnDefaults()}; this is the switch's path. Stamped with the actor, the version
     * bumped. Called inside the invoice's unit of work (it nests); the values were judged by the
     * invoice save with the same field rules.
     *
     * $lineText (owner 2026-11-17): the first line's text becomes the STANDARD line text
     * («Kontierungstext»); null or '' leaves the standard text as it is (only the creditor window
     * empties it).
     *
     * @return bool whether the defaults were written (false: already decided and no $overwrite)
     */
    public function takeDefaults(CreditorProfile $profile, ?string $account, ?string $taxCode, bool $withoutVat, ?string $paymentMethodCode, bool $overwrite, ?string $lineText = null): bool
    {
        $id = $profile->getId() ?? throw new \LogicException('takeDefaults() takes an existing creditor profile');

        return $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $account, $taxCode, $withoutVat, $paymentMethodCode, $overwrite, $lineText): bool {
            $current = $this->lockAt($id, null);
            if ($current->getDefaultsSetAt() !== null && !$overwrite) {
                return false;   // decided before — never overwritten silently
            }
            $current->setDefaultAccount($account);
            $current->setDefaultTaxCode($taxCode);
            $current->setDefaultWithoutVat($taxCode === null && $withoutVat);
            $current->setDefaultPaymentMethodCode($paymentMethodCode);
            if ($lineText !== null && trim($lineText) !== '') {
                $current->setDefaultLineText(mb_substr($lineText, 0, CreditorProfile::LINE_TEXT_LENGTH));
            }
            $current->stampDefaults($this->actor(), new \DateTimeImmutable());
            $current->bumpVersion();

            return true;
        });
    }

    /**
     * The defaults LEARNED from a saved invoice (owner 2026-11-16: «ich möchte nicht den Umweg über
     * Kontakt → Kreditor machen müssen; wenn noch kein Konto hinterlegt ist, soll er einfach den
     * übernehmen, den ich bei der Rechnung eintrage»). Only what is still EMPTY on the creditor is
     * filled, never a default already set:
     *
     *   - the account of the invoice's first line while the creditor has no default account;
     *   - that line's VAT decision (tax code or «ohne MWST») while the creditor has none — only
     *     together with the account (learned now, or the same as the default account): they belong
     *     together;
     *   - the invoice's «Bezahlt mit» while the creditor has none.
     *
     * Each value is judged by the creditor window's own rules ({@see CreditorProfileValidator}: the
     * account exists, is postable and active; the tax code active and allowed for the mandator; the
     * payment method active) — a refused value is simply not learned. Stamped and the version
     * bumped when anything was filled. Called inside the invoice's unit of work (it nests).
     *
     * @return array{account?: string, taxCode?: ?string, withoutVat?: bool, paymentMethod?: string} what was filled
     */
    public function learnDefaults(CreditorProfile $profile, ?string $account, ?string $taxCode, bool $withoutVat, ?string $paymentMethodCode): array
    {
        $id = $profile->getId() ?? throw new \LogicException('learnDefaults() takes an existing creditor profile');

        return $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $account, $taxCode, $withoutVat, $paymentMethodCode): array {
            $current   = $this->lockAt($id, null);
            $draft     = clone $current;
            $validator = new CreditorProfileValidator($draft, $this->em, $current, null, $this->liableToVat());
            $learned   = [];
            if ($current->getDefaultAccount() === null && $account !== null && $account !== '') {
                $draft->setDefaultAccount($account);
                $validator->validateDefaultAccount($draft->getDefaultAccount());
                if (!isset($validator->getFieldErrors()['default_account'])) {
                    $learned['account'] = (string) $draft->getDefaultAccount();
                }
            }
            $vatOpen   = $current->getDefaultTaxCode() === null && !$current->isDefaultWithoutVat();
            $sameLine  = isset($learned['account']) || ($account !== null && $account === $current->getDefaultAccount());
            if ($vatOpen && $sameLine && ($taxCode !== null || $withoutVat)) {
                $draft->setDefaultWithoutVat($taxCode === null);
                $draft->setDefaultTaxCode($taxCode);
                $validator->validateDefaultTaxCode($draft->getDefaultTaxCode());
                if (!isset($validator->getFieldErrors()['default_tax'])) {
                    $learned['taxCode']    = $draft->getDefaultTaxCode();
                    $learned['withoutVat'] = $draft->getDefaultTaxCode() === null;
                }
            }
            if ($current->getDefaultPaymentMethodCode() === null && $paymentMethodCode !== null && $paymentMethodCode !== '') {
                $draft->setDefaultPaymentMethodCode($paymentMethodCode);
                $validator->validateDefaultPaymentMethodCode($draft->getDefaultPaymentMethodCode());
                if (!isset($validator->getFieldErrors()['default_payment_method_code'])) {
                    $learned['paymentMethod'] = (string) $draft->getDefaultPaymentMethodCode();
                }
            }
            if ($learned === []) {
                return [];
            }
            if (isset($learned['account'])) {
                $current->setDefaultAccount($learned['account']);
            }
            if (array_key_exists('taxCode', $learned)) {
                $current->setDefaultTaxCode($learned['taxCode']);
                $current->setDefaultWithoutVat($learned['withoutVat']);
            }
            if (isset($learned['paymentMethod'])) {
                $current->setDefaultPaymentMethodCode($learned['paymentMethod']);
            }
            $current->stampDefaults($this->actor(), new \DateTimeImmutable());
            $current->bumpVersion();

            return $learned;
        });
    }

    /**
     * The owner saved an invoice of $profile with $lineText on its first line (owner 2026-11-17) —
     * the recognition's last fallback for the line text. Not a default (no stamp) and not a window
     * field, so the version is NOT bumped: an open creditor window stays saveable. Inside the
     * invoice's unit of work, AFTER its {@see learnDefaults()} / {@see takeDefaults()} (they lock
     * and re-read the row; a nested unit of work does not flush, so a re-read after this would
     * drop it) — the managed entity is written, not read again. '' changes nothing.
     */
    public function noteLastLineText(CreditorProfile $profile, string $lineText): void
    {
        $id = $profile->getId();
        if ($id === null || trim($lineText) === '') {
            return;
        }
        $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $lineText): void {
            $current = $this->profiles()->find($id);
            if ($current instanceof CreditorProfile) {
                $current->noteLastLineText($lineText);
                $this->em->persist($current);
            }
        });
    }

    /**
     * DELETE a profile with its IBANs — the undo of «Kreditor» switched on by mistake (the contact
     * editor's role off). Refused while an invoice names the creditor: it can then only be
     * deactivated (the debtor rule of 2026-10-12). One unit of work, the profile row locked first.
     *
     * @throws CreditorRefusedException IN_USE — the creditor has invoices
     */
    public function delete(CreditorProfile $profile): void
    {
        $id = $profile->getId() ?? throw new \LogicException('delete() takes an existing creditor profile');
        $invoices = $this->invoiceCount($id);
        if ($invoices > 0) {
            // Asked first, so the usual refusal needs no rollback; asked again under the lock below.
            throw self::inUse($invoices, (string) $profile->getContact()?->displayName());
        }
        $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $profile): void {
            if ($this->profiles()->lockVersion($id) === null) {
                return;   // deleted since — nothing left to do
            }
            $invoices = $this->invoiceCount($id);
            if ($invoices > 0) {
                throw self::inUse($invoices, (string) $profile->getContact()?->displayName());
            }
            $current = $this->profiles()->refreshed($id);
            foreach ($this->ibans()->forProfile($current) as $row) {
                $this->em->remove($row);
            }
            // what it learned from its invoices (owner 2026-11-16) goes with it
            foreach ($this->em->getRepository(CreditorLearned::class)->forProfile($id) as $row) {
                $this->em->remove($row);
            }
            $this->em->remove($current);
        });
    }

    /**
     * The IBAN rows as a form or an import hands them over → the clean list the service works on:
     * IBAN normalised, hint trimmed, active a bool; a row without IBAN is dropped (the window's
     * empty «add» row, or a row the user emptied to remove it).
     *
     * @param array<mixed> $rows
     * @return list<array{iban: string, hint: string, active: bool}>
     */
    public static function ibanRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $iban = Iban::normalize((string) ($row['iban'] ?? ''));
            if ($iban === '') {
                continue;
            }
            $active = $row['active'] ?? true;
            $out[]  = [
                'iban'   => $iban,
                'hint'   => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($row['hint'] ?? ''))),
                'active' => is_bool($active) ? $active : in_array((string) $active, ['1', 'true', 'on', 'yes'], true),
            ];
        }

        return $out;
    }

    /** The mandator's VAT liability — the one answer of {@see CurrentMandator::liableToVat()} (no record or unreadable = liable). */
    public function liableToVat(): bool
    {
        return (new CurrentMandator($this->em))->liableToVat();
    }

    /** How many invoices (any state) name the creditor — the delete guard and the role's state. */
    public function invoiceCount(int $profileId): int
    {
        return $this->em->getRepository(CreditorInvoice::class)->countForProfile($profileId);
    }

    /** «… hat 3 Rechnungen — nur deaktivieren» — the refusal of a delete (the role «Kreditor» off). */
    public static function inUse(int $invoices, string $name): CreditorRefusedException
    {
        return new CreditorRefusedException(
            CreditorRefusedException::IN_USE,
            '«' . $name . '» hat ' . ($invoices === 1 ? 'eine Rechnung' : $invoices . ' Rechnungen')
            . ' im Kreditorenjournal — der Kreditor kann nicht mehr entfernt, nur deaktiviert werden (Stammdaten › Finanzen › Kreditoren, Schalter der Zeile).'
        );
    }

    /** The IBANs of $profile in the order they were added. */
    public function ibansOf(CreditorProfile $profile): array
    {
        return $this->ibans()->forProfile($profile);
    }

    /**
     * «Banktext» learned from a bank movement the owner assigned by hand (ADR-061 stage C1): only
     * while the creditor has NONE — a typed or learned text is never overwritten. Whitespace
     * collapsed, cut to the column; a text shorter than `CreditorProfile::BANK_TEXT_MIN` is not
     * learned. Runs inside the caller's unit of work (or its own); the version is bumped.
     *
     * @return ?string what was learned, null = nothing
     */
    public function learnBankText(CreditorProfile $profile, string $text): ?string
    {
        $text = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, CreditorProfile::BANK_TEXT_LENGTH);
        $id   = $profile->getId();
        if ($id === null || mb_strlen($text) < CreditorProfile::BANK_TEXT_MIN) {
            return null;
        }

        return $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id, $text): ?string {
            $current = $this->lockAt($id, null);
            if ($current->getBankText() !== null) {
                return null;
            }
            $current->setBankText($text);
            $current->bumpVersion();

            return $current->getBankText();
        });
    }

    /**
     * Brings the stored IBAN rows of $profile to $rows: a row with the same IBAN is KEPT (hint and
     * active updated), a new IBAN is added, an IBAN no longer listed is removed. Inside the unit of
     * work of {@see update()}.
     *
     * @param list<array{iban: string, hint: string, active: bool}> $rows
     */
    private function syncIbans(CreditorProfile $profile, array $rows): void
    {
        $stored = [];
        foreach ($this->ibans()->forProfile($profile) as $row) {
            $stored[$row->getIban()] = $row;
        }
        foreach ($rows as $row) {
            $existing = $stored[$row['iban']] ?? null;
            unset($stored[$row['iban']]);
            if ($existing === null) {
                $this->em->persist(new CreditorIban($profile, $row['iban'], $row['hint'], $row['active']));
                continue;
            }
            $existing->setHint($row['hint']);
            $existing->setActive($row['active']);
        }
        foreach ($stored as $gone) {
            $this->em->remove($gone);
        }
    }

    /** The row locked, the version compared (null = any), then read fresh — inside the unit of work. */
    private function lockAt(int $id, ?int $version): CreditorProfile
    {
        $current = $this->profiles()->lockVersion($id);
        if ($current === null || ($version !== null && $current !== $version)) {
            throw self::changed();
        }

        return $this->profiles()->refreshed($id) ?? throw self::changed();
    }

    private static function changed(): CreditorRefusedException
    {
        return new CreditorRefusedException(
            CreditorRefusedException::CHANGED,
            'Der Kreditor wurde inzwischen geändert oder entfernt — nichts gespeichert. Die Liste neu laden und die Änderung nochmals anbringen.'
        );
    }

    /**
     * @param list<array{iban: string, hint: string, active: bool}>|null $ibanRows
     * @throws InvalidCreditorProfileException
     */
    private function assertValid(CreditorProfile $profile, ?CreditorProfile $stored, ?array $ibanRows): void
    {
        $validator = new CreditorProfileValidator($profile, $this->em, $stored, $ibanRows, $this->liableToVat());
        if (!$validator->isValid()) {
            throw new InvalidCreditorProfileException($validator, $profile, $ibanRows ?? []);
        }
    }

    /**
     * Runs the write, with a unique index the database decided turned into the field error the
     * validator would have given (two requests can pass the validator for the same contact or UID).
     * After the failed unit of work the EntityManager has been replaced (DOCTRINE-TX-004); the
     * profile handed in serves the form only.
     *
     * @param list<array{iban: string, hint: string, active: bool}> $ibanRows
     * @throws InvalidCreditorProfileException
     */
    private function refuseOnRace(CreditorProfile $profile, array $ibanRows, callable $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = match (true) {
                str_contains($e->getMessage(), CreditorProfile::UNIQUE_CONTACT) => ['contact_id', 'Für diesen Kontakt wurde soeben ein Kreditor angelegt — Liste neu laden.'],
                str_contains($e->getMessage(), CreditorProfile::UNIQUE_UID)     => ['uid', 'Diese UID hat soeben ein anderer Kreditor erhalten — Liste neu laden.'],
                default                                                         => null,
            };
            if ($field === null) {
                throw $e;
            }
            $validator = new CreditorProfileValidator($profile);
            $validator->flagFieldError($field[0], $field[1]);
            throw new InvalidCreditorProfileException($validator, $profile, $ibanRows);
        }
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function profiles(): CreditorProfileRepository
    {
        return $this->em->getRepository(CreditorProfile::class);
    }

    private function ibans(): CreditorIbanRepository
    {
        return $this->em->getRepository(CreditorIban::class);
    }
}
