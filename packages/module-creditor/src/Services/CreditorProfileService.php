<?php

namespace Z77\Module\Creditor\Services;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Creditor\Entities\CreditorIban;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Repositories\CreditorIbanRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Creditor\Validators\CreditorProfileValidator;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
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
 *     `defaults_set_at` / `defaults_set_by` (they are decided now — the first complete invoice will
 *     not overwrite them); emptying every default clears the stamp (the next complete invoice
 *     decides them again — stage 1c);
 *   - {@see setActive()} — the list's switch; {@see delete()} — the role «Kreditor» off: the
 *     profile and its IBANs go. In 1a nothing can reference a profile yet; the creditor invoice of
 *     1b adds the guard «only while no invoice references it, else deactivate» (the debtor rule of
 *     2026-10-12) — creditor.md pending.
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
        unset($values['id'], $values['contact'], $values['contact_id'], $values['active'], $values['version'], $values['defaults_set_at'], $values['defaults_set_by']);
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
     * DELETE a profile with its IBANs — the undo of «Kreditor» switched on by mistake (the contact
     * editor's role off). One unit of work, the profile row locked first.
     */
    public function delete(CreditorProfile $profile): void
    {
        $id = $profile->getId() ?? throw new \LogicException('delete() takes an existing creditor profile');
        $this->em->getTransaction(CreditorProfile::class)->run(function () use ($id): void {
            if ($this->profiles()->lockVersion($id) === null) {
                return;   // deleted since — nothing left to do
            }
            $current = $this->profiles()->refreshed($id);
            foreach ($this->ibans()->forProfile($current) as $row) {
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

    /** The mandator's VAT liability; a mandator not saved or not readable answers «liable» (the tax code is then judged as usual). */
    public function liableToVat(): bool
    {
        try {
            return (new CurrentMandator($this->em))->find()?->isLiableToVat() ?? true;
        } catch (MandatorUnavailableException) {
            return true;
        }
    }

    /** The IBANs of $profile in the order they were added. */
    public function ibansOf(CreditorProfile $profile): array
    {
        return $this->ibans()->forProfile($profile);
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
