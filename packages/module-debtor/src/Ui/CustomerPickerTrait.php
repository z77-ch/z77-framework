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
    Z77\Module\Debtor\Repositories\DebtorProfileRepository,
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
 *            `contact=<id>`.
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
                'html'   => CustomerPicker::fieldHtml($this->em(), (string) $contact->getId(), $this->customerPickBase() . '/customer-pick', $back),
                'origin' => $origin,
            ]);
        $this->customerChosenCommands($response, $contact, $origin);

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
