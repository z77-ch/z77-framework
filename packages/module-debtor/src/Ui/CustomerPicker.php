<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\Services\TemplateRenderer;
use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Repositories\CustomerSearch;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\ListState;
use Z77\Shared\Listing\Parsers;

/**
 * The customer of a NEW document — a building block (Rule 9) the invoice editor and the order
 * window share (owner 2026-10-15: «bei x tausend Kontakten öffnet die Kontaktauswahl ein
 * Fenster zum Suchen, oder den Knopf zum Neuanlegen, wenn er ihn nicht findet»). Replaces the
 * select of every debtor and contact (`_debtorSelect`, retired).
 *
 *   - THE FIELD (`Backend/CustomerPicker/_field`): the chosen customer as text, its id in the
 *     hidden `contact`, and «Kunde wählen …» — one element `#invoice-contact` that a choice
 *     replaces in the editor window (`replace-html`, scoped to the origin);
 *   - THE PICKER (`CustomerPickerTrait::customerPickAction()`, `Backend/CustomerPicker/pick`): a
 *     window over the editor with a STANDARD LIST (listing.md) — Name · Ort · Kundennr. ·
 *     E-Mail, searched in the database ({@see DebtorProfileRepository::searchCustomers()}),
 *     paged, a fetch region inside the window; «Übernehmen» per row; «+ Neuer Kontakt» loads
 *     the host's new-contact form into the same window.
 *
 * Choosable = an active contact that is an active debtor or no debtor yet (D9).
 */
final class CustomerPicker
{
    public const ID = 'customer-pick-find';

    public const PAGE_SIZE = 25;

    public const NS = 'Z77\\Module\\Debtor';

    public static function definition(): ListDefinition
    {
        return new ListDefinition(self::ID, [
            Column::slot('Übernehmen', '7rem'),
            Column::search('f_name', 'Name', 'minmax(12rem, 2fr)', 'name', Parsers::text()),
            Column::search('f_city', 'Ort', 'minmax(7rem, 1fr)', null, Parsers::text(), priority: '2'),
            Column::search('f_number', 'Kundennr.', '7rem', 'number', Parsers::integer(9), numeric: true, inputMode: 'numeric'),
            Column::search('f_email', 'E-Mail', 'minmax(8rem, 1fr)', null, Parsers::text(), priority: '3'),
        ], 'name', self::PAGE_SIZE);
    }

    public static function search(ListState $state): CustomerSearch
    {
        return new CustomerSearch(
            $state->parsed('f_name'),
            $state->parsed('f_city'),
            $state->parsed('f_number'),
            $state->parsed('f_email'),
            $state->sort,
            $state->descending,
        );
    }

    /**
     * Whether $contact may be chosen for a NEW document — the rule of the list's WHERE,
     * asked again for the id a form sends back (never trust the row that was shown).
     */
    public static function isChoosable(Contact $contact, ?DebtorProfile $profile): bool
    {
        return $contact->isActive() && ($profile === null || $profile->isActive());
    }

    /** One line about a customer: name and its debtor state — the field and the list say the same. */
    public static function label(Contact $contact, ?DebtorProfile $profile): string
    {
        return $contact->displayName() . ' · ' . ($profile !== null ? 'Kundennr. ' . $profile->getCustomerNumber() : 'neu als Debitor');
    }

    /**
     * The field as HTML — the editor renders it, a choice and «+ Kontakt» answer it.
     *
     * @param string $pickUrl  the host's `customer-pick` URL
     * @param string $back     where the picker returns without the script (the editor's own URL)
     */
    public static function fieldHtml(UnifiedEntityManager $em, string $contactId, string $pickUrl, string $back, bool $invalid = false): string
    {
        $contact = ctype_digit($contactId) && (int) $contactId > 0 ? $em->getRepository(Contact::class)->find((int) $contactId) : null;
        /** @var DebtorProfileRepository $profiles */
        $profiles = $em->getRepository(DebtorProfile::class);
        $profile  = $contact !== null ? $profiles->findByContact($contact) : null;

        return (new TemplateRenderer(self::NS))->partial('Backend/CustomerPicker/_field', [
            'contact' => $contact,
            'label'   => $contact !== null ? self::label($contact, $profile) : '',
            'pickUrl' => $pickUrl,
            'back'    => $back,
            'invalid' => $invalid,
        ]);
    }
}
