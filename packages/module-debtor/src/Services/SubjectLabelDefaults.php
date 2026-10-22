<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Contact\Entities\Contact;
use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Entities\Invoice;
use Z77\Module\Debtor\Invoicing\SubjectLabel;
use Z77\Module\Debtor\Repositories\DebtorProfileRepository;
use Z77\Module\Debtor\Repositories\InvoiceRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;

/**
 * Where the «Betreffname» of a NEW document comes from (owner decision 2026-10-21): the
 * debtor's own (`debtor_profile.default_subject_label`), else the installation's (the
 * mandator's `default_subject_label` — the one record of installation-wide document defaults,
 * like its letterhead), else none — {@see SubjectLabel::resolve()}. The ONE reader of both
 * settings (Rule 2): debtor's invoice editor and module-order's window pre-fill through it, and
 * the customer picker re-fills on a customer change (`CustomerPickerTrait`).
 *
 * Reads only. A mandator that cannot be read (module not registered, table missing) or was
 * never saved reads as «no global default» — a label is a convenience, never a refusal.
 */
final class SubjectLabelDefaults
{
    public function __construct(private readonly UnifiedEntityManager $em) {}

    /** The pre-fill of a new document for $contact (id or entity; null / unknown = no debtor → the global default). */
    public function defaultFor(Contact|int|null $contact): ?string
    {
        $profile = null;
        if ($contact !== null && $contact !== 0) {
            /** @var DebtorProfileRepository $profiles */
            $profiles = $this->em->getRepository(DebtorProfile::class);
            $profile  = $profiles->findByContact($contact);
        }

        return SubjectLabel::resolve($profile?->getDefaultSubjectLabel(), $this->global());
    }

    /** The installation's default — the mandator's; null when none is set or the mandator cannot be read. */
    public function global(): ?string
    {
        return SubjectLabel::normalize(Creditor::mandator($this->em)?->getDefaultSubjectLabel());
    }

    /**
     * The suggestions of a screen's `<datalist>`: the labels the documents carry, the debtors'
     * defaults and the global one, plus $more (module-order adds its orders' labels).
     *
     * @param iterable<?string> $more
     * @return list<string>
     */
    public function suggestions(iterable $more = []): array
    {
        /** @var InvoiceRepository $invoices */
        $invoices = $this->em->getRepository(Invoice::class);
        /** @var DebtorProfileRepository $profiles */
        $profiles = $this->em->getRepository(DebtorProfile::class);

        return SubjectLabel::suggestions(
            [$this->global()],
            $profiles->defaultSubjectLabels(SubjectLabel::SUGGESTIONS),
            $invoices->subjectLabelsInUse(SubjectLabel::SUGGESTIONS),
            $more,
        );
    }
}
