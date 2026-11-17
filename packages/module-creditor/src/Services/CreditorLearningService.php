<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Creditor\Entities\CreditorInvoice;
use Z77\Module\Creditor\Entities\CreditorInvoiceText;
use Z77\Module\Creditor\Entities\CreditorLearned;
use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Recognition\InvoiceFacts;
use Z77\Module\Creditor\Recognition\LearnedLabels;
use Z77\Module\Creditor\Recognition\OwnParty;
use Z77\Module\Creditor\Recognition\SenderFeatures;
use Z77\Module\Creditor\Repositories\CreditorInvoiceTextRepository;
use Z77\Module\Creditor\Repositories\CreditorLearnedRepository;
use Z77\Module\Creditor\Repositories\CreditorProfileRepository;
use Z77\Module\Financial\Services\Actor;
use Z77\Module\Mandator\Services\CurrentMandator;
use Z77\Module\Mandator\Services\MandatorUnavailableException;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Money\Money;

/**
 * LEARNING PER CREDITOR (owner 2026-11-16, «ja starten» — the plan of 2026-11-15: «learn per
 * creditor so the AI runs only the first time»). Three things, all from the TEXT LAYER the job read
 * ({@see CreditorInvoiceText} — pdftotext runs only in the CLI job, CREDITOR-RECOG-WEB-001, so the
 * job keeps the text and a web request learns from it):
 *
 *   1. **Who sent it** — when the OWNER settles the creditor of an invoice (a save with a creditor,
 *      «Kreditor übernehmen …» and its carry-over), the creditor learns the stable features of that
 *      invoice's text ({@see SenderFeatures}: foreign VAT number, domain, sender line). The next
 *      invoice without a known IBAN or Swiss UID is matched by them ({@see match()}) — a feature
 *      counts only when ONE creditor holds it, a VAT number decides alone, a domain or a sender line
 *      only together with a second one; several creditors → candidates, never a guess. An owner who
 *      corrects a creditor the features chose takes those features away from the wrong one.
 *   2. **Where its values stand** — the LABEL before invoice number, invoice date, due date and
 *      amount ({@see LearnedLabels}), learned from the AI's answer in the job ({@see afterAi()}) and
 *      from the owner's saved values ({@see learnFromInvoice()}); the next invoice of that creditor
 *      is read at those labels ({@see read()}). The amount in the currency printed (stage A0: a
 *      foreign invoice's original — never the CHF the owner typed).
 *   3. **When the AI is no longer needed** — every label counts how often PHP's reading and the AI's
 *      answer agreed (`agreed`); once the required labels ({@see LearnedLabels::REQUIRED}) agreed
 *      {@see TRUST_AFTER} times and read a value, PHP alone reads the creditor's invoices and the
 *      AI step is left out. A disagreement, a value the owner corrects, or a label that reads
 *      nothing learns the label again and the trust starts over.
 *   4. **The line text** (owner 2026-11-17) — the column header above the text the owner kept on
 *      the first line ({@see LearnedLabels::LINE_TEXT}, learned on save, read by {@see lineTextAt()}).
 *      Optional: it never counts for the trust phase and is never compared with the AI.
 *
 * Every write runs in a unit of work (`run()` joins the caller's — an invoice save learns in its
 * own unit of work). Nothing here throws for a text it cannot use: no text, no value, no label =
 * nothing learned.
 */
final class CreditorLearningService
{
    /** How often PHP and the AI must agree on a label before PHP reads alone (owner: «die ersten 2–3 Rechnungen»). */
    public const TRUST_AFTER = 2;

    /** `recognition.match` — the creditor was decided by its learned features. */
    public const BY_LEARNED = 'learned';
    /** `recognition.match` — learned features point to several creditors (or only weakly to one): the owner chooses. */
    public const BY_CHOOSE = 'learned-choose';
    /** `recognition.match` — the IBAN / UID names one creditor, the learned features another: the owner chooses. */
    public const BY_CONFLICT = 'learned-conflict';

    private ?string $actor;
    private ?OwnParty $own = null;

    public function __construct(private readonly UnifiedEntityManager $em, ?string $actor = null)
    {
        $this->actor = $actor !== null ? Actor::normalize($actor) : null;
    }

    // ── the text layer ───────────────────────────────────────────────────

    /** Keep the text the job read for invoice $invoiceId (replaces an earlier reading) — inside the caller's unit of work. */
    public function keepText(int $invoiceId, string $text): void
    {
        if ($invoiceId <= 0 || trim($text) === '') {
            return;
        }
        $row = $this->texts()->forInvoice($invoiceId);
        if ($row === null) {
            $this->em->persist(new CreditorInvoiceText($invoiceId, $text, new \DateTimeImmutable()));
        } else {
            $row->replace($text, new \DateTimeImmutable());
            $this->em->persist($row);
        }
    }

    public function textOf(int $invoiceId): ?string
    {
        return $this->texts()->forInvoice($invoiceId)?->getText();
    }

    /** The invoice is deleted — its text goes with it (inside the caller's unit of work). */
    public function dropText(int $invoiceId): void
    {
        $row = $this->texts()->forInvoice($invoiceId);
        if ($row !== null) {
            $this->em->remove($row);
        }
    }

    // ── 1. who sent it ───────────────────────────────────────────────────

    /**
     * The creditor whose learned features $text carries.
     *
     * @return array{creditor: ?CreditorProfile, by: string, candidates: list<array{id: int, name: string}>, features: list<string>}
     *         by: {@see BY_LEARNED} (decided; `features` = «kind:value» that decided), {@see BY_CHOOSE} (the candidates), `none`
     */
    public function match(string $text): array
    {
        $none = ['creditor' => null, 'by' => 'none', 'candidates' => [], 'features' => []];
        if (trim($text) === '') {
            return $none;
        }
        $holders = [];
        foreach (SenderFeatures::inText($text, $this->own()) as $kind => $values) {
            foreach ($this->learned()->holders($kind, $values) as [$profileId, $value]) {
                $holders[$kind . ':' . $value][$profileId] = true;
            }
        }
        $score = [];
        $why   = [];
        foreach ($holders as $key => $profiles) {
            if (count($profiles) !== 1) {
                continue;   // a feature two creditors share decides nothing
            }
            $profileId          = (int) array_key_first($profiles);
            $score[$profileId]  = ($score[$profileId] ?? 0) + SenderFeatures::WEIGHT[explode(':', $key, 2)[0]];
            $why[$profileId][]  = $key;
        }
        if ($score === []) {
            return $none;
        }
        $decided = array_keys(array_filter($score, static fn(int $s): bool => $s >= SenderFeatures::DECIDES));
        if (count($decided) === 1) {
            $profile = $this->profiles()->find($decided[0]);
            if ($profile instanceof CreditorProfile && $profile->isActive()) {
                return ['creditor' => $profile, 'by' => self::BY_LEARNED, 'candidates' => [], 'features' => $why[$decided[0]]];
            }
        }
        $ids = $decided !== [] ? $decided : array_keys($score);
        sort($ids);

        return ['creditor' => null, 'by' => self::BY_CHOOSE, 'candidates' => $this->candidates($ids), 'features' => []];
    }

    /**
     * The creditor's ACTIVE features no longer point to it — the owner put another creditor on an
     * invoice they had chosen (`kind:value` as {@see match()} named them). Inside the caller's unit of work.
     *
     * @param list<string> $features
     */
    public function forgetFeatures(int $profileId, array $features): void
    {
        if ($profileId <= 0 || $features === []) {
            return;
        }
        foreach ($this->learned()->forProfile($profileId) as $row) {
            if (!$row->isLabel() && in_array($row->getKind() . ':' . $row->getValue(), $features, true)) {
                $this->em->remove($row);
            }
        }
    }

    // ── 2. + 3. the labels, the trust ────────────────────────────────────

    /**
     * The values $creditor's learned labels read in $text, and whether PHP may read alone.
     *
     * @return array{values: array<string, string|\DateTimeImmutable|Money>, trusted: bool, labels: int}
     *         trusted = every required label agreed {@see TRUST_AFTER} times AND read its value now
     */
    public function read(CreditorProfile $creditor, string $text): array
    {
        $labels = $this->learned()->labelsOf((int) $creditor->getId());
        $values = [];
        foreach ($labels as $field => $row) {
            $value = LearnedLabels::read($text, $field, $row->getValue(), $row->getCurrency(), $row->getDateOrder());
            if ($value !== null) {
                $values[$field] = $value;
            }
        }
        if (isset($values[LearnedLabels::DATE], $values[LearnedLabels::DUE]) && $values[LearnedLabels::DUE] < $values[LearnedLabels::DATE]) {
            unset($values[LearnedLabels::DUE]);   // implausible: due before the invoice date
        }
        $trusted = true;
        foreach (LearnedLabels::REQUIRED as $field) {
            if (!isset($labels[$field], $values[$field]) || $labels[$field]->getAgreed() < self::TRUST_AFTER) {
                $trusted = false;
            }
        }

        return ['values' => $values, 'trusted' => $trusted, 'labels' => count($labels)];
    }

    /**
     * The AI answered for an invoice of $creditor (the job): per field, PHP's reading at the learned
     * label is compared with the AI's value — equal = one more agreement; different, or nothing read
     * = the label learned again from the AI's value (the trust starts over; a value the text does
     * not print removes the label); no label yet = learned from the AI's value.
     *
     * @return array{agreed: list<string>, relearned: list<string>, learned: list<string>}
     */
    public function afterAi(CreditorProfile $creditor, string $text, InvoiceFacts $ai, int $invoiceId): array
    {
        $out = ['agreed' => [], 'relearned' => [], 'learned' => []];
        if (trim($text) === '') {
            return $out;
        }
        $values = self::valuesOf($ai->invoiceNumber, $ai->invoiceDate, $ai->dueDate, $ai->amount !== null && ($ai->currency ?? $ai->amount->currency) === $ai->amount->currency ? $ai->amount : null);

        return $this->em->getTransaction(CreditorLearned::class)->run(function () use ($creditor, $text, $values, $invoiceId, $out): array {
            $profile = $this->profiles()->find((int) $creditor->getId());
            if (!$profile instanceof CreditorProfile) {
                return $out;
            }
            $labels = $this->learned()->labelsOf((int) $profile->getId());
            foreach ($values as $field => $value) {
                $row = $labels[$field] ?? null;
                if ($row !== null) {
                    $php = LearnedLabels::read($text, $field, $row->getValue(), $row->getCurrency(), $row->getDateOrder());
                    if ($php !== null && self::same($php, $value)) {
                        $row->agree();
                        $this->em->persist($row);
                        $out['agreed'][] = $field;
                        continue;
                    }
                    $this->relearn($row, $text, $field, $value, $invoiceId);
                    $out['relearned'][] = $field;
                    continue;
                }
                if ($this->learnLabel($profile, $text, $field, $value, $invoiceId)) {
                    $out['learned'][] = $field;
                }
            }

            return $out;
        });
    }

    /**
     * The OWNER settled the creditor of $invoice (a save with a creditor, «Kreditor übernehmen …»):
     * $creditor learns the features of its text and the labels of its values — a label that reads
     * another value than the invoice now has is learned again (the owner corrected it, or the AI's
     * value differs; the trust starts over), one that reads the same stays. $wrong names a creditor
     * the learned features had chosen for this invoice although it is another one — those features
     * are taken away from it. Joins the caller's unit of work; nothing without a stored text.
     *
     * $lineText — the text the owner KEPT on the first line (owner 2026-11-17): the column header
     * above it is learned ({@see LearnedLabels::learnLineHeader()}, field {@see LearnedLabels::LINE_TEXT});
     * a header that already reads it stays; a text the invoice does not print changes nothing (the
     * owner's own words — the header learned before stays).
     *
     * @return array{features: int, labels: list<string>}
     */
    public function learnFromInvoice(CreditorInvoice $invoice, CreditorProfile $creditor, ?int $wrong = null, ?string $lineText = null): array
    {
        $out  = ['features' => 0, 'labels' => []];
        $id   = (int) $invoice->getId();
        $text = $this->textOf($id);
        if ($text === null || trim($text) === '' || $creditor->getId() === null) {
            return $out;
        }
        $values = self::valuesOf($invoice->getInvoiceNumber(), $invoice->getInvoiceDate(), $invoice->getDueDate(), $invoice->getOriginal() ?? ($invoice->isForeign() ? null : $invoice->getAmount()));
        $record = $invoice->recognition();

        return $this->em->getTransaction(CreditorLearned::class)->run(function () use ($invoice, $creditor, $text, $values, $id, $wrong, $record, $lineText, $out): array {
            if ($wrong !== null && $wrong !== (int) $creditor->getId() && ($record['match'] ?? '') === self::BY_LEARNED) {
                $this->forgetFeatures($wrong, array_map('strval', (array) ($record['steps']['text']['learned_by'] ?? [])));
            }
            $contact = $creditor->getContact();
            $names   = array_values(array_filter([(string) $contact?->getCompany(), (string) $contact?->displayName()]));
            foreach (SenderFeatures::toLearn($text, $names, $this->own()) as $kind => $features) {
                $have = $this->learned()->valuesOf($creditor, $kind);
                foreach (array_values(array_unique($features)) as $feature) {
                    if ($feature === '' || mb_strlen($feature) > CreditorLearned::VALUE_LENGTH || in_array($feature, $have, true)) {
                        continue;
                    }
                    $this->em->persist(new CreditorLearned($creditor, $kind, '', $feature, $id, $this->actor(), new \DateTimeImmutable()));
                    $have[] = $feature;
                    $out['features']++;
                }
            }
            $labels = $this->learned()->labelsOf((int) $creditor->getId());
            foreach ($values as $field => $value) {
                $row = $labels[$field] ?? null;
                if ($row === null) {
                    if ($this->learnLabel($creditor, $text, $field, $value, $id)) {
                        $out['labels'][] = $field;
                    }
                    continue;
                }
                $php = LearnedLabels::read($text, $field, $row->getValue(), $row->getCurrency(), $row->getDateOrder());
                if ($php === null || !self::same($php, $value)) {
                    $this->relearn($row, $text, $field, $value, $id);
                    $out['labels'][] = $field;
                }
            }
            if ($lineText !== null && trim($lineText) !== '' && $this->learnLineHeader($creditor, $labels[LearnedLabels::LINE_TEXT] ?? null, $text, $lineText, $id)) {
                $out['labels'][] = LearnedLabels::LINE_TEXT;
            }

            return $out;
        });
    }

    /**
     * The line text $creditor's learned column header reads in $text (owner 2026-11-17) — null
     * without a header or a row under it. Optional for the trust phase: never part of {@see read()}.
     */
    public function lineTextAt(CreditorProfile $creditor, ?string $text): ?string
    {
        if ($text === null || trim($text) === '' || $creditor->getId() === null) {
            return null;
        }
        $row = $this->learned()->labelsOf((int) $creditor->getId())[LearnedLabels::LINE_TEXT] ?? null;

        return $row !== null ? LearnedLabels::readLineText($text, $row->getValue()) : null;
    }

    /** «Gelerntes vergessen» — everything $creditor learned; the next invoices teach it again. @return int rows removed */
    public function forget(CreditorProfile $creditor): int
    {
        $id = (int) $creditor->getId();

        return $this->em->getTransaction(CreditorLearned::class)->run(function () use ($id): int {
            $rows = $this->learned()->forProfile($id);
            foreach ($rows as $row) {
                $this->em->remove($row);
            }

            return count($rows);
        });
    }

    /**
     * What $creditor learned, for its window: the features by kind, the labels per field with their
     * agreements, and whether PHP reads its invoices alone.
     *
     * @return array{features: array<string, list<string>>, labels: array<string, array{label: string, currency: ?string, agreed: int}>, trusted: bool, empty: bool}
     */
    public function overview(CreditorProfile $creditor): array
    {
        $features = [];
        $labels   = [];
        foreach ($this->learned()->forProfile((int) $creditor->getId()) as $row) {
            if ($row->isLabel()) {
                $labels[$row->getField()] = ['label' => $row->getValue(), 'currency' => $row->getCurrency(), 'agreed' => $row->getAgreed()];
            } else {
                $features[$row->getKind()][] = $row->getValue();
            }
        }
        $trusted = true;
        foreach (LearnedLabels::REQUIRED as $field) {
            if (($labels[$field]['agreed'] ?? -1) < self::TRUST_AFTER) {
                $trusted = false;
            }
        }
        $ordered = [];
        foreach ([...LearnedLabels::FIELDS, LearnedLabels::LINE_TEXT] as $field) {
            if (isset($labels[$field])) {
                $ordered[$field] = $labels[$field];
            }
        }

        return ['features' => $features, 'labels' => $ordered, 'trusted' => $trusted, 'empty' => $features === [] && $labels === []];
    }

    /** The installation's own company — never a feature of a supplier. */
    public function own(): OwnParty
    {
        if ($this->own === null) {
            try {
                $this->own = OwnParty::fromMandator((new CurrentMandator($this->em))->find());
            } catch (MandatorUnavailableException) {
                $this->own = OwnParty::none();
            }
        }

        return $this->own;
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * The fields with a value, as {@see LearnedLabels} learns them.
     *
     * @return array<string, string|\DateTimeImmutable|Money>
     */
    private static function valuesOf(?string $number, ?\DateTimeImmutable $date, ?\DateTimeImmutable $due, ?Money $amount): array
    {
        return array_filter([
            LearnedLabels::NUMBER => $number !== null && trim($number) !== '' ? trim($number) : null,
            LearnedLabels::DATE   => $date,
            LearnedLabels::DUE    => $due,
            LearnedLabels::AMOUNT => $amount !== null && $amount->isPositive() ? $amount : null,
        ], static fn($v): bool => $v !== null);
    }

    private function learnLabel(CreditorProfile $creditor, string $text, string $field, string|\DateTimeImmutable|Money $value, int $invoiceId): bool
    {
        $found = LearnedLabels::learn($text, $field, $value);
        if ($found === null) {
            return false;
        }
        $this->em->persist(new CreditorLearned(
            $creditor, CreditorLearned::KIND_LABEL, $field, $found['label'], $invoiceId > 0 ? $invoiceId : null, $this->actor(), new \DateTimeImmutable(),
            $value instanceof Money ? $value->currency : null, $found['order']
        ));

        return true;
    }

    /**
     * The column header above the kept line text — learned, or learned again when the stored one
     * reads another first part; kept as it is when the text is not printed under a header.
     */
    private function learnLineHeader(CreditorProfile $creditor, ?CreditorLearned $row, string $text, string $lineText, int $invoiceId): bool
    {
        $first = static fn(string $value): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', explode(trim(LearnedLabels::LINE_TEXT_JOIN), $value)[0])));
        $reads = $row !== null ? LearnedLabels::readLineText($text, $row->getValue()) : null;
        if ($reads !== null && $first($reads) === $first($lineText)) {
            return false;   // the header already reads it
        }
        $header = LearnedLabels::learnLineHeader($text, $lineText);
        if ($header === null || ($row !== null && $row->getValue() === $header)) {
            return false;
        }
        if ($row !== null) {
            $row->relabel($header, null, null, $invoiceId > 0 ? $invoiceId : null, $this->actor(), new \DateTimeImmutable());
            $this->em->persist($row);
        } else {
            $this->em->persist(new CreditorLearned($creditor, CreditorLearned::KIND_LABEL, LearnedLabels::LINE_TEXT, $header, $invoiceId > 0 ? $invoiceId : null, $this->actor(), new \DateTimeImmutable()));
        }

        return true;
    }

    /** Learn $row's label again from $value; the text does not print it → the label goes. */
    private function relearn(CreditorLearned $row, string $text, string $field, string|\DateTimeImmutable|Money $value, int $invoiceId): void
    {
        $found = LearnedLabels::learn($text, $field, $value);
        if ($found === null) {
            $this->em->remove($row);

            return;
        }
        $row->relabel($found['label'], $value instanceof Money ? $value->currency : null, $found['order'], $invoiceId > 0 ? $invoiceId : null, $this->actor(), new \DateTimeImmutable());
        $this->em->persist($row);
    }

    private static function same(string|\DateTimeImmutable|Money $a, string|\DateTimeImmutable|Money $b): bool
    {
        return match (true) {
            $a instanceof Money && $b instanceof Money                           => $a->toDecimal() === $b->toDecimal() && $a->currency === $b->currency,
            $a instanceof \DateTimeImmutable && $b instanceof \DateTimeImmutable => $a->format('Y-m-d') === $b->format('Y-m-d'),
            is_string($a) && is_string($b)                                       => strcasecmp(trim($a), trim($b)) === 0,
            default                                                              => false,
        };
    }

    /**
     * @param list<int> $ids
     * @return list<array{id: int, name: string}>
     */
    private function candidates(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $profile = $this->profiles()->find($id);
            if ($profile instanceof CreditorProfile) {
                $out[] = ['id' => $id, 'name' => (string) $profile->getContact()?->displayName()];
            }
        }

        return $out;
    }

    private function actor(): string
    {
        return $this->actor ??= Actor::current();
    }

    private function learned(): CreditorLearnedRepository
    {
        return $this->em->getRepository(CreditorLearned::class);
    }

    private function texts(): CreditorInvoiceTextRepository
    {
        return $this->em->getRepository(CreditorInvoiceText::class);
    }

    private function profiles(): CreditorProfileRepository
    {
        return $this->em->getRepository(CreditorProfile::class);
    }
}
