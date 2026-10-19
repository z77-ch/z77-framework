<?php
namespace Z77\Module\Debtor\Ui;

use Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Contact\Entities\Contact,
    Z77\Module\Contact\Entities\ContactAddress,
    Z77\Module\Contact\Repositories\ContactAddressRepository,
    Z77\Module\Debtor\Entities\DebtorProfile,
    Z77\Module\Debtor\Entities\PaymentTerms,
    Z77\Module\Debtor\Repositories\DebtorProfileRepository,
    Z77\Module\Debtor\Repositories\PaymentTermsRepository,
    Z77\Module\Debtor\Services\DebtorProfileService,
    Z77\Module\Debtor\Services\InvalidDebtorProfileException,
    Z77\Persistence\Validation\EntityValidator,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Listing\ListDefinition
;

/**
 * «Kunde wählen …» — the customer picker of a document editor ({@see CustomerPicker}), used by
 * the editor's own trait (`use CustomerPickerTrait;` inside `InvoiceControllerTrait`,
 * `OrderControllerTrait`), so the action `customer-pick` lives at the editor's URL root.
 *
 *   - GET  — the picker window: a standard list of the choosable customers, searched, sorted
 *            and paged in the database; inside the window a fetch region (core.js keeps the
 *            page's address and the editor untouched). Every link and the search form carry
 *            `_origin` (the editor window) and `back`, so a reload keeps both;
 *   - POST `contact=<id>` — the choice: judged again ({@see CustomerPicker::isChoosable()}),
 *            then {@see customerChosenAnswer()}; without the script a redirect to `back` with
 *            `contact=<id>`. A contact WITHOUT a debtor profile is not taken as it is (owner
 *            2026-10-18): the window shows «Debitor anlegen» ({@see customerDebtorAction()},
 *            price mode and payment terms) and only the created debtor becomes the customer.
 *
 * The using trait provides `customerPickBase()` (its URL root) and
 * `customerChosenCommands()` (what else the editor needs after a choice — the order's
 * delivery addresses), plus the host's `html()`, `fetch()`, `fetchError()`, `redirect()`,
 * `em()`, `$layoutManager`, `$messageService`.
 */
trait CustomerPickerTrait
{
    /** The editor's URL root — `customer-pick` and `new-contact` live there. */
    abstract protected function customerPickBase(): string;

    /** What the editor needs beyond the field after a choice (the origin window's further parts). */
    abstract protected function customerChosenCommands(FetchResponse $response, Contact $contact, string $origin): void;

    /**
     * Whether the field shows «Kundennr. …» under the name — true by default (the invoice editor);
     * a host that shows the number elsewhere returns false (the order window: in its block title).
     * Must match the host's own `CustomerPicker::fieldHtml()` call, so a choice renders the same field.
     */
    protected function customerFieldShowsNumber(): bool
    {
        return true;
    }

    #[Csrf]
    protected function customerPickAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $fetch   = ListDefinition::isFetch($request);
        if ($request->isPost()) {
            $post    = $request->getPostParameters();
            $back    = $this->customerPickBack((string) ($post['back'] ?? ''));
            $id      = (int) ($post['contact'] ?? 0);
            $contact = $id > 0 ? $this->em()->getRepository(Contact::class)->find($id) : null;
            $profile = $contact !== null ? $this->customerProfiles()->findByContact($contact) : null;
            if ($contact === null || !CustomerPicker::isChoosable($contact, $profile)) {
                $message = 'Diesen Kunden gibt es nicht mehr, oder er ist inaktiv — bitte neu suchen.';
                if ($fetch) {
                    return $this->fetchError($message);
                }
                $this->messageService->pushFlashAfterRedirect('error', $message);

                return $this->redirect($back, 303);
            }
            // No document for a contact without a debtor profile (owner 2026-10-18): it becomes a
            // debtor first — «Debitor anlegen» in the same window; cancel chooses nobody.
            if ($profile === null) {
                return $this->customerDebtorPage($contact, WindowOrigin::of($request), $back);
            }
            if (!$fetch) {
                return $this->redirect($back . (str_contains($back, '?') ? '&' : '?') . 'contact=' . $id, 303);
            }

            return $this->customerChosenAnswer($contact, WindowOrigin::of($request), $back);
        }

        $definition = CustomerPicker::definition();
        $state      = $definition->readRequest($request);
        $search     = CustomerPicker::search($state);
        $profiles   = $this->customerProfiles();
        $paging     = $state->paging($profiles->countCustomers($search));
        $rows       = $profiles->searchCustomers($search, $paging->offset(), $paging->pageSize);
        $origin     = WindowOrigin::of($request);
        $back       = $this->customerPickBack((string) $request->getGetParameter('back'));

        $response = $this->html([
            'window'        => $fetch,
            'definition'    => $definition,
            'state'         => $state,
            'paging'        => $paging,
            'rows'          => $rows,
            'cities'        => $this->customerCities(array_map(static fn(array $row) => $row['contact'], $rows)),
            'pickUrl'       => $this->customerPickBase() . '/customer-pick',
            'newContactUrl' => $this->customerPickBase() . '/new-contact',
            // What every link of the list and the search form carry: the editor window and the way back.
            'keep'          => array_filter(['_origin' => $origin === WindowOrigin::PAGE ? '' : $origin, 'back' => $back]),
            'origin'        => $origin,
            'back'          => $back,
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('pick', 'Backend/CustomerPicker', CustomerPicker::NS);

        return $response;
    }

    /**
     * «Debitor anlegen» (owner decision 2026-10-18 — no document for a contact without a debtor
     * profile; replaces plan D9 for the order window and the invoice editor): GET / the picker's
     * choice / the save of «+ Kontakt» show {@see customerDebtorPage()}; POST `contact`, `terms`,
     * `price_mode` opens the profile through `DebtorProfileService::open()` — the one opening path
     * (the validator: an active contact, not a debtor yet, active terms, net | gross; the customer
     * number drawn in its own unit of work) — and only THEN takes the contact as the customer
     * ({@see customerChosenAnswer()}). A refusal comes back as the dialog with its errors. A
     * contact that became a debtor in between (a double submit, another user) is simply taken.
     * Without the script: a redirect to `back` with `contact=<id>`.
     */
    #[Csrf]
    protected function customerDebtorAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $fetch   = ListDefinition::isFetch($request);
        $params  = $request->isPost() ? $request->getPostParameters() : ['contact' => $request->getGetParameter('contact'), 'back' => $request->getGetParameter('back')];
        $back    = $this->customerPickBack((string) ($params['back'] ?? ''));
        $id      = (int) ($params['contact'] ?? 0);
        $contact = $id > 0 ? $this->em()->getRepository(Contact::class)->find($id) : null;
        $profile = $contact !== null ? $this->customerProfiles()->findByContact($contact) : null;
        if ($contact === null || !CustomerPicker::isChoosable($contact, $profile)) {
            $message = 'Diesen Kunden gibt es nicht mehr, oder er ist inaktiv — bitte neu suchen.';
            if ($fetch) {
                return $this->fetchError($message);
            }
            $this->messageService->pushFlashAfterRedirect('error', $message);

            return $this->redirect($back, 303);
        }
        if ($profile === null && !$request->isPost()) {
            return $this->customerDebtorPage($contact, WindowOrigin::of($request), $back);
        }
        if ($profile === null) {
            $terms = trim((string) ($params['terms'] ?? ''));
            $mode  = trim((string) ($params['price_mode'] ?? ''));
            try {
                $profile = (new DebtorProfileService($this->em()))->open($contact, $terms, $mode);
            } catch (InvalidDebtorProfileException $e) {
                $profile = $this->customerProfiles()->findByContact($contact);   // opened in between — what was wanted
                if ($profile === null) {
                    return $this->customerDebtorPage($contact, WindowOrigin::of($request), $back, $terms, $mode, $e->validator);
                }
            }
            $text = '«' . $contact->displayName() . '» ist neu Debitor mit Kundennummer ' . $profile->getCustomerNumber();
            $fetch ? $this->messageService->pushFlash('success', $text) : $this->messageService->pushFlashAfterRedirect('success', $text);
        }
        if (!$fetch) {
            return $this->redirect($back . (str_contains($back, '?') ? '&' : '?') . 'contact=' . $id, 303);
        }

        return $this->customerChosenAnswer($contact, WindowOrigin::of($request), $back);
    }

    /**
     * The «Debitor anlegen» dialog for $contact (`Backend/CustomerPicker/debtor`) — as the content
     * of the window the request came from (the picker, «+ Kontakt»), or a page without the script.
     * Preset: net and the terms a new debtor gets (`defaultForNewDebtor()`); the offered terms are
     * the active ones (a new reference needs an active row, ADR-043 decision 19). A host calls it
     * after its «+ Kontakt» saved a contact.
     */
    protected function customerDebtorPage(Contact $contact, string $origin, string $back, ?string $terms = null, string $priceMode = 'net', ?EntityValidator $validator = null): HtmlResponse
    {
        /** @var PaymentTermsRepository $repo */
        $repo     = $this->em()->getRepository(PaymentTerms::class);
        $response = $this->html([
            'window'    => ListDefinition::isFetch(DI::getRequest()),
            'contact'   => $contact,
            'terms'     => array_values(array_filter($repo->allInOrder(), static fn(PaymentTerms $t) => $t->isActive())),
            'termsCode' => $terms ?? ($repo->defaultForNewDebtor()?->getCode() ?? ''),
            'priceMode' => $priceMode,
            'validator' => $validator,
            'debtorUrl' => $this->customerPickBase() . '/customer-debtor',
            'origin'    => $origin,
            'back'      => $this->customerPickBack($back),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('debtor', 'Backend/CustomerPicker', CustomerPicker::NS);

        return $response;
    }

    /**
     * The editor's answer to a customer chosen in the picker or captured with «+ Kontakt»: the
     * field `#invoice-contact` replaced in the origin window, the using trait's further commands,
     * then `close-modal` for the window the request came from.
     */
    protected function customerChosenAnswer(Contact $contact, string $origin, string $back): FetchResponse
    {
        $response = $this->fetch()->setStatus('success')
            ->setData(['id' => $contact->getId()])
            ->addCommand('replace-html', [
                'target' => '#invoice-contact',
                'html'   => CustomerPicker::fieldHtml($this->em(), (string) $contact->getId(), $this->customerPickBase() . '/customer-pick', $back,
                    showNumber: $this->customerFieldShowsNumber()),
                'origin' => $origin,
            ]);
        $this->customerChosenCommands($response, $contact, $origin);
        // The document's «Preise» take the debtor's price mode (owner 2026-10-18) — the editor's
        // field is named `price_mode`; `set-value` fires `change`, so whatever follows the field
        // (the order's re-conversion of its rows, core.js «price mode») runs as on a choice by hand.
        $profile = $this->customerProfiles()->findByContact($contact);
        if ($profile !== null) {
            $response->addCommand('set-value', ['target' => 'select[name="price_mode"]', 'value' => $profile->priceMode()->value, 'origin' => $origin]);
        }

        return $response->addCommand('close-modal');
    }

    /**
     * Where the picker returns without the script: an address under the editor's own root
     * (its `add` or `edit?id=`), else the new document — never a foreign target.
     */
    private function customerPickBack(string $back): string
    {
        $base = $this->customerPickBase();

        return str_starts_with($back, $base . '/') && preg_match('#^[A-Za-z0-9/_\-]+(\?[A-Za-z0-9=&_\-]*)?$#', $back) === 1 && !str_contains($back, '//')
            ? $back
            : $base . '/add';
    }

    private function customerProfiles(): DebtorProfileRepository
    {
        return $this->em()->getRepository(DebtorProfile::class);
    }

    /**
     * The city shown per customer of a page — debtor's address rule (the `invoice` link, else
     * `main`, else the first), the links of the whole page in ONE query.
     *
     * @param list<Contact> $contacts
     * @return array<int, string> contact id → city
     */
    private function customerCities(array $contacts): array
    {
        /** @var ContactAddressRepository $links */
        $links  = $this->em()->getRepository(ContactAddress::class);
        $cities = [];
        foreach ($links->findForContacts($contacts) as $contactId => $list) {
            $pick = null;
            foreach (['invoice', 'main'] as $type) {
                foreach ($list as $link) {
                    if ($pick === null && $link->getTypeCode() === $type) {
                        $pick = $link;
                    }
                }
            }
            $pick ??= $list[0] ?? null;
            if ($pick !== null) {
                $cities[(int) $contactId] = trim($pick->getAddress()->getZip() . ' ' . $pick->getAddress()->getCity());
            }
        }

        return $cities;
    }
}
