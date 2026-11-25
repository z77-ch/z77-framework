<?php

namespace Z77\Module\Financial\VatReturn;

use Z77\Module\Financial\Entities\FiscalYear;
use Z77\Module\Financial\Entities\FiscalYearCloseLog;
use Z77\Module\Financial\Entities\StoredVatYearOverview;
use Z77\Module\Financial\Pdf\VatYearArchive;
use Z77\Module\Financial\Pdf\VatYearPdf;
use Z77\Module\Financial\Repositories\FiscalYearCloseLogRepository;
use Z77\Module\Financial\Repositories\FiscalYearRepository;
use Z77\Module\Financial\Repositories\StoredVatYearOverviewRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Financial\Services\LedgerService;
use Z77\Module\Financial\Ui\VatYearLayout;
use Z77\Module\Mandator\Entities\Mandator;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Module\Mandator\Services\VatSettings;
use Z77\Persistence\Doctrine\Entities\NumberRange;
use Z77\Persistence\Doctrine\Repositories\NumberRangeRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Interface\TransactionInterface;

/**
 * «Ablegen …» of the yearly overview of the VAT return (stage S7 slice 7.5; owner S7-1 option b,
 * B-4, B-5, C-D of 2026-11-25): freezes the overview of a tax year as an unchangeable PDF in the
 * archive ({@see VatYearArchive}) with one {@see StoredVatYearOverview} row — which the protocol
 * «Abschlüsse und MWST» shows as «Jahresübersicht abgelegt». It POSTS NOTHING: no entry, no
 * `vat_return` row, nothing of the books changes. Nothing is replaced or lifted; every storing stays.
 *
 * Refused (a {@see VatReturnRefusedException}, nothing written):
 *
 *   - {@see REFUSAL_PROVISIONAL} — the overview is «vorläufig» (a period not posted, the fiscal year
 *     holding 31.12. not closed — B-4: only what does not change any more goes into the archive);
 *   - {@see REFUSAL_CHANGED} — the overview is not the one the window showed (its fingerprint);
 *   - {@see REFUSAL_UNCHANGED} — nothing changed since the newest storing (B-5: storing again only
 *     when it shows something new);
 *   - the read model's own refusals (the settings gate, a year without a VAT period).
 *
 * Lock order (the VAT return writers' step 0 and 3, so it never deadlocks with them): the writer row
 * `NumberRangeRepository::lock('vat-return')` (X) — serialises against «MWST buchen», «Aufheben» and
 * another storing —, then every fiscal-year row (S) — no year closed or reopened under it; then the
 * mandator re-read and a FRESH {@see VatYearService} computes the overview.
 */
final class YearOverviewStore
{
    public const REFUSAL_PROVISIONAL = 'year-provisional';
    public const REFUSAL_CHANGED     = 'year-changed';
    public const REFUSAL_UNCHANGED   = 'year-unchanged';

    /**
     * @param string|null      $actor    who stores; null = the session identity ({@see Actor::current()})
     * @param string|null      $basePath the installation root of the archive; null = `ABS_BASE_PATH`
     * @param VatSettings|null $settings the mandator's VAT settings; null = read from the record (a harness passes its own)
     */
    public function __construct(
        private readonly UnifiedEntityManager $em,
        private readonly ?string $actor = null,
        private readonly ?string $basePath = null,
        private readonly ?VatSettings $settings = null,
    ) {}

    /**
     * Stores the overview of $taxYear — see the class docblock.
     *
     * @param string $shownFingerprint the fingerprint of the overview the window showed
     * @throws VatReturnRefusedException see the class docblock
     * @throws \LogicException a unit of work is open, or nobody is named as the actor
     */
    public function store(int $taxYear, string $shownFingerprint): StoredVatYearOverview
    {
        $transaction = $this->ownUnitOfWork();
        $actor       = $this->actor !== null ? Actor::normalize($this->actor) : Actor::current();
        $archive     = new VatYearArchive($this->basePath);

        try {
            return $transaction->run(function () use ($taxYear, $shownFingerprint, $actor, $archive): StoredVatYearOverview {
                /** @var NumberRangeRepository $ranges */
                $ranges = $this->em->getRepository(NumberRange::class);
                $ranges->lock(VatReturnPostingService::WRITER_LOCK);   // 0 — like every return writer
                $this->fiscalYears()->shareLockAll();                  // 3 — no year closed or reopened under it
                try {
                    (new CurrentMandator($this->em))->fresh();
                } catch (MandatorUnavailableException) {
                    // the settings gate of the read model names it
                }

                $service     = new VatYearService($this->em, $this->settings);
                $overview    = $service->overview($taxYear);
                $fingerprint = $service->fingerprint($taxYear);
                if (!$overview->final) {
                    throw self::refused(self::REFUSAL_PROVISIONAL, 'Die ' . VatYearLayout::title($taxYear) . ' ist vorläufig (' . implode(' · ', VatYearLayout::provisionalReasons($overview))
                        . ') — abgelegt wird sie, wenn alle Perioden des Jahres gebucht sind und das Geschäftsjahr abgeschlossen ist.');
                }
                if (!hash_equals($fingerprint, $shownFingerprint)) {
                    throw self::refused(self::REFUSAL_CHANGED, 'Die ' . VatYearLayout::title($taxYear) . ' hat sich eben geändert — das Fenster schliessen und die Übersicht neu ansehen.');
                }
                $newest = $this->stored()->newest($taxYear);
                if ($newest !== null && hash_equals($newest->getFingerprint(), $fingerprint)) {
                    throw self::refused(self::REFUSAL_UNCHANGED, 'Die ' . VatYearLayout::title($taxYear) . ' hat sich seit der Ablage vom ' . $newest->getStandAt()->format('d.m.Y')
                        . ' nicht geändert — es gibt nichts Neues abzulegen.');
                }

                $stand = new \DateTimeImmutable(date('Y-m-d H:i:s'));   // to the second, as the column keeps it
                $pdf   = VatYearPdf::of($overview, $this->mandator(), $stand, ['at' => $stand, 'by' => $actor], $this->roundingAccount(), $this->closedAt($overview->yearEndFiscalYear))->output();
                $sha   = hash('sha256', $pdf);
                $path  = $archive->store($pdf, $sha, $taxYear, $stand);
                $currency = LedgerService::baseCurrency();
                $payable  = $overview->field($this->payableField($overview) ?? '')?->corrections->rounded();

                $row = new StoredVatYearOverview($taxYear, $stand, $fingerprint, $path, $sha, VatYearArchive::fileName($taxYear, $stand), $actor, $stand, [
                    'corrections'        => count($overview->corrections),
                    'payable_difference' => ($payable ?? \Z77\Shared\Money\Money::zero($currency))->toDecimal(),
                ]);
                $this->em->persist($row);
                $this->em->flush();

                return $row;
            });
        } catch (\Throwable $e) {
            $archive->discardWritten();   // no file may lie there that no row names
            throw $e;
        }
    }

    /** The payable field of the overview (500 in the CH form) — by its kind, never by number. */
    private function payableField(VatYearOverview $overview): ?string
    {
        foreach ($overview->fields as $f) {
            if ($f->kind === \Z77\Module\Vat\CountryPack\FieldKind::Payable) {
                return $f->field;
            }
        }

        return null;
    }

    private function closedAt(?FiscalYear $year): ?\DateTimeImmutable
    {
        if ($year === null || $year->getId() === null) {
            return null;
        }
        /** @var FiscalYearCloseLogRepository $log */
        $log = $this->em->getRepository(FiscalYearCloseLog::class);

        return $log->lastClosedAt((int) $year->getId());
    }

    private function roundingAccount(): ?string
    {
        try {
            return (new LedgerService($this->em))->vatReturnAccount(LedgerService::VAT_ROUNDING_ACCOUNT);
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    private function mandator(): ?Mandator
    {
        try {
            return (new CurrentMandator($this->em))->find();
        } catch (MandatorUnavailableException) {
            return null;
        }
    }

    private static function refused(string $reason, string $message): VatReturnRefusedException
    {
        return new VatReturnRefusedException(new VatReturnRefusal($reason, $message));
    }

    private function ownUnitOfWork(): TransactionInterface
    {
        $transaction = $this->em->getTransaction(StoredVatYearOverview::class);
        if ($transaction->isOpen()) {
            throw new \LogicException('YearOverviewStore::store() owns its unit of work — call it outside getTransaction()->run(), so its locks come before its first read');
        }

        return $transaction;
    }

    private function fiscalYears(): FiscalYearRepository
    {
        return $this->em->getRepository(FiscalYear::class);
    }

    private function stored(): StoredVatYearOverviewRepository
    {
        return $this->em->getRepository(StoredVatYearOverview::class);
    }
}
