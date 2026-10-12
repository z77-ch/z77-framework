<?php
namespace Z77\Module\Contact\Ui;

use Z77\Core\DI,
    Z77\Module\Contact\Entities\Address,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Entities\ContactAddress,
    Z77\Module\Contact\Entities\ContactKind,
    Z77\Module\Contact\Services\AddressTypes,
    Z77\Module\Contact\Services\ContactService,
    Z77\Module\Contact\Services\InvalidAddressException,
    Z77\Module\Contact\Services\InvalidContactException,
    Z77\Module\Contact\Validators\AddressValidator,
    Z77\Module\Contact\Validators\ContactAddressValidator,
    Z77\Module\Contact\Validators\ContactValidator,
    Z77\Persistence\Cleaning\BodyCleaner
;

/**
 * The NEW-contact form as a building block (Rule 9, opt-in): the contact's own fields plus
 * its first typed address — posted body → a new {@see Contact} with its link → saved through
 * {@see ContactService::save()} (every rule stays the service's and the validators') → the
 * template variables of the two field partials `Backend/ContactController/_contactFields`
 * and `_addressFields` (namespace `Z77\Module\Contact`) for a re-render with the errors.
 *
 * Users: the contact screen's «Kontakt anlegen» (the address OPTIONAL — an empty block is no
 * address) and any screen of another module that needs a party it does not have yet, e.g.
 * debtor's «+ Kontakt» in the invoice window (the address REQUIRED — a document needs one).
 * The caller owns its form tag, its action row and its answer; this class knows no module.
 *
 * Address fields post under the `address_` prefix (`first_name` and `title` exist on the
 * contact / the link as well); link fields as `type_code` and `title`. The body may be the
 * JSON of a `data-fetch-post` dialog or the `$_POST` of a window form — the same keys.
 */
final class ContactForm
{
    /** Address fields arrive under this prefix (they collide with the contact's own names otherwise). */
    public const ADDRESS_PREFIX = 'address_';

    /** German display labels — PRESENTATION ONLY (the `ROLE_LABELS` pattern). The kind SET is {@see ContactKind}. */
    public const KIND_LABELS = [
        'person'       => 'Person',
        'organisation' => 'Organisation',
    ];

    /** Offered when the site's own languages do not cover a contact — the Swiss set. */
    private const LANGUAGES = ['de', 'fr', 'it', 'en'];

    public readonly Contact $contact;
    public readonly Address $address;
    public string $typeCode  = 'main';
    public string $linkTitle = '';
    public ?ContactValidator $validator = null;
    public ?ContactAddressValidator $linkValidator = null;
    public ?AddressValidator $addressValidator = null;

    /** @param bool $addressRequired true = the first address must be given (a party for a document); false = an empty block is no address */
    public function __construct(public readonly bool $addressRequired)
    {
        $this->contact = new Contact();
        $this->contact->setLanguage(DI::getI18n()->getDefaultLanguage());
        $this->address = new Address();
    }

    /** The posted fields onto the NEW contact (not managed — nothing is written before {@see save()}). */
    public function fromPost(array $body): self
    {
        $this->contact->mapFromArray(self::contactValues($body));

        $addressValues   = self::addressValues($body);
        $linkValues      = self::linkValues($body);
        $this->typeCode  = (string) ($linkValues['type_code'] ?? $this->typeCode);
        $this->linkTitle = (string) ($linkValues['title'] ?? '');
        if ($this->addressRequired || !self::addressIsEmpty($addressValues)) {
            $this->address->mapFromArray($addressValues);
            $this->contact->addAddress(new ContactAddress($this->contact, $this->typeCode, $this->address, $this->linkTitle));
        }

        return $this;
    }

    /** True = saved (the contact has its id); false = refused, the validators are set for the re-render. */
    public function save(ContactService $service): bool
    {
        try {
            $service->save($this->contact);

            return true;
        } catch (InvalidContactException $e) {
            $this->validator = $e->validator;
        } catch (InvalidAddressException $e) {
            $this->linkValidator    = $e->linkValidator;
            $this->addressValidator = $e->addressValidator;
        }

        return false;
    }

    public function hasErrors(): bool
    {
        return ($this->validator !== null && $this->validator->hasErrors())
            || ($this->linkValidator !== null && $this->linkValidator->hasErrors())
            || ($this->addressValidator !== null && $this->addressValidator->hasErrors());
    }

    /**
     * What `_contactFields` + `_addressFields` need — merge it into the caller's template
     * context. `required` follows the constructor: the browser marks the address fields
     * required only where the server requires them.
     *
     * @return array<string, mixed>
     */
    public function vars(AddressTypes $types): array
    {
        return [
            'entry'            => $this->contact,
            'validator'        => $this->validator ?? new ContactValidator($this->contact),
            'kindLabels'       => self::kindLabels(),
            'languages'        => self::languages($this->contact->getLanguage()),
            'address'          => $this->address,
            'typeCode'         => $this->typeCode,
            'linkTitle'        => $this->linkTitle,
            'types'            => $types->active(),
            'linkValidator'    => $this->linkValidator,
            'addressValidator' => $this->addressValidator,
            'required'         => $this->addressRequired,
        ];
    }

    /** @return array<string,string> kind value → German label, for selects */
    public static function kindLabels(): array
    {
        $labels = [];
        foreach (ContactKind::cases() as $kind) {
            $labels[$kind->value] = self::KIND_LABELS[$kind->value] ?? $kind->value;
        }

        return $labels;
    }

    /** @return list<string> the site's languages first, then the common set, the contact's own always included */
    public static function languages(string $current = ''): array
    {
        $languages = [...DI::getI18n()->getLanguages(), ...self::LANGUAGES];
        if ($current !== '') {
            $languages[] = $current;
        }

        return array_values(array_unique($languages));
    }

    /** The contact's own fields of a posted form, cleaned; `active` has its own switch and never comes from a form. */
    public static function contactValues(array $body): array
    {
        $values = BodyCleaner::cleanFor(Contact::class, $body);
        unset($values['active']);

        return $values;
    }

    /** The address block of a posted form: `address_first_name` → `first_name`, …, cleaned for {@see Address}. */
    public static function addressValues(array $body): array
    {
        $raw = [];
        foreach ($body as $key => $value) {
            if (is_string($key) && str_starts_with($key, self::ADDRESS_PREFIX)) {
                $raw[substr($key, strlen(self::ADDRESS_PREFIX))] = $value;
            }
        }

        return BodyCleaner::cleanFor(Address::class, $raw);
    }

    /** The link fields of a posted form (`type_code`, `title`), cleaned for {@see ContactAddress}. */
    public static function linkValues(array $body): array
    {
        return BodyCleaner::cleanFor(ContactAddress::class, ['type_code' => $body['type_code'] ?? '', 'title' => $body['title'] ?? '']);
    }

    /** True when nothing of the address block was filled in (the country alone does not count). */
    private static function addressIsEmpty(array $addressValues): bool
    {
        foreach ($addressValues as $key => $value) {
            if ($key !== 'country' && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
