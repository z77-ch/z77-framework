<?php
namespace Z77\Module\Receipt\Ui;

use Doctrine\DBAL\Exception\RetryableException,
    Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Financial\Entities\JournalEntry,
    Z77\Module\Financial\Ledger\EntryRef,
    Z77\Module\Financial\Ui\ManualEntryForm,
    Z77\Module\Receipt\Entities\PaymentMethod,
    Z77\Module\Receipt\Entities\Receipt,
    Z77\Module\Receipt\Entities\ReceiptTemplate,
    Z77\Module\Receipt\Repositories\ReceiptRepository,
    Z77\Module\Receipt\Services\InvalidReceiptException,
    Z77\Module\Receipt\Services\PaymentMethodService,
    Z77\Module\Receipt\Services\ReceiptBankMatch,
    Z77\Module\Receipt\Services\ReceiptBankMatchService,
    Z77\Module\Receipt\Services\ReceiptCaptureService,
    Z77\Module\Receipt\Services\ReceiptCorrection,
    Z77\Module\Receipt\Services\ReceiptException,
    Z77\Module\Receipt\Services\ReceiptFields,
    Z77\Module\Receipt\Services\ReceiptJournalPostingService,
    Z77\Module\Receipt\Services\ReceiptJournalService,
    Z77\Module\Receipt\Services\ReceiptPostingBuilder,
    Z77\Module\Receipt\Services\ReceiptRefusedException,
    Z77\Module\Receipt\Services\ReceiptRetention,
    Z77\Module\Receipt\Services\ReceiptTemplateService,
    Z77\Shared\Attributes\Csrf,
    Z77\Shared\Listing\ListDefinition,
    Z77\Shared\Money\AmountFormat,
    Z77\Shared\Money\Money
;

/**
 * The «BELEGJOURNAL» screen (board «Belegjournal», owner-approved 2026-11-12; owner decisions E1,
 * E8, E10–E12) — mounted by a thin host controller in the backend (ADR-018 pattern):
 * `/backend/finance/receipt-journal/…`, navigation «Finanzen › Belegjournal» right after «Journal»
 * (module-receipt's seed). The flow and the look of debtor's Faktura-Journal (ADR-054), built on its
 * pattern, not its classes.
 *
 * The recipe (backend-screen.md):
 *
 *   - action cell (hc1): «Journal verbuchen …» — the entry's most frequent action, a green confirm
 *     that opens the posting WINDOW (E16; origin: the list region); disabled with its reason while
 *     the journal is empty;
 *   - toolbar (hc2): the tabs «Im Journal» · «Verbuchte Journale»; «Hilfe» at its right end (HELP-003);
 *   - the pending step at the top: «n Belege im Journal · noch nicht verbucht · Total … · ältester
 *     vom …», or — red — what keeps receipts from being posted, each with a link to its window;
 *   - the work area: a STANDARD LIST of the captured receipts ({@see ReceiptJournalListing}) — the
 *     state icon (✓ complete / ✎ incomplete) opens the receipt WINDOW (ADR-047); the photo as a
 *     thumbnail; under it the totals per VAT code (`ReceiptJournalPostingService::preview()` — the
 *     builder that posts, never a VAT computation here). Tab «Verbuchte Journale»: the protocols;
 *     opening it runs the photo-retention backfill (`ReceiptRetention::apply()`).
 *
 * Actions:
 *
 *   - `edit` — the receipt WINDOW: the photo large beside the fields (date, amount, text, Soll, Haben,
 *     the VAT decision), the template and «Bezahlt mit» as fill helpers (`data-fill-url` → `template-fill`
 *     / `payment-fill`, core.js «fill» — the server answers the values, no script of its own, Rule 7);
 *     the save is `ReceiptJournalService::update()` with the version the window showed. The VAT
 *     select is the shared one (`ReceiptChoices::taxChoices()` with the group «Bezugsteuer», the
 *     token parsed by `ReceiptFields`); «nur Steuer» locks «Soll» on the screen through the same core.js
 *     «fill» (`tax-fill`, `ReceiptChoices::debitLock()`, display only — the server clears and
 *     derives, S56-2); a reverse-charge receipt shows base and tax under the select (read off the
 *     posting's lines); the accounts are offered for the receipt's date;
 *   - `split` — «Aufteilen …» (E12): the amount of the new part → `split()`;
 *   - `confirm-delete` — «Löschen …»: `delete()`; when it answers the photo's document id (no split
 *     part names it any more), the photo goes to the DMS trash with the receipt (owner 2026-11-20:
 *     no switch any more) through the module's own handle (`ReceiptCaptureService::trashPhoto()`,
 *     `ModuleDrive::trash()`) — restorable in the Drive, independent of the session's DMS right;
 *   - `confirm-post` — «Journal verbuchen …»: a WINDOW (E16, the shared `partials/postConfirm`;
 *     without the script a page) with the cut-off (E11, proposed TODAY —
 *     {@see ReceiptJournalPostingService::defaultCutOff()}; a GET form re-renders the window): only
 *     the errors when a receipt up to it cannot be posted, else the short summary and «Jetzt
 *     buchen»; the form carries the cut-off and every receipt's `{id}:{version}`;
 *   - `post` (POST, `#[Csrf]`) — `ReceiptJournalPostingService::post()`, all or nothing; fetched,
 *     the window closes and the list region reloads, a refusal re-renders the window;
 *   - `confirm-bank` (ADR-061 stage C5) — «Zahlungen suchen …» (toolbar, only while a bank account
 *     reads statements) and «Zahlung suchen …» (the receipt window, `?id=`): a WINDOW with the
 *     bank movements that fit each unlinked receipt paid through a bank account — a proposal
 *     ticked in advance, candidates not; POST (`#[Csrf]`) hands each ticked pair to
 *     `ReceiptBankMatchService::take()`. `release-movement` (POST from the window, `#[Csrf]`)
 *     is «Zuordnung lösen». A linked receipt shows «Bank · date · amount» and its amount and
 *     «Haben» read-only (they are the bank's).
 *
 * The using class MUST provide (via its host base): `html()`, `fetch()`, `fetchError()`,
 * `redirect()`, `em()`, `$layoutManager`, `$messageService`, `$help`.
 */
trait ReceiptJournalControllerTrait
{
    private const RECEIPT_JOURNAL_NS = 'Z77\\Module\\Receipt';

    /** URL root of THIS mount — every link and form is built from it. */
    protected function receiptJournalBase(): string
    {
        return ReceiptLayout::JOURNAL_BASE;
    }

    /** The write paths; the session actor. A harness host overrides them with a named actor. */
    private function receiptJournal(): ReceiptJournalService
    {
        return new ReceiptJournalService($this->em());
    }

    private function receiptPosting(): ReceiptJournalPostingService
    {
        return new ReceiptJournalPostingService($this->em());
    }

    /** The bank match (ADR-061 stage C5); the session actor. A harness host overrides it. */
    private function receiptBankMatch(): ReceiptBankMatchService
    {
        return new ReceiptBankMatchService($this->em());
    }

    /** The photos of the shown receipts (DMS, read-gated). A harness host overrides it — the DMS gate needs a session. */
    private function receiptPhotos(array $documentIds): array
    {
        return ReceiptPhoto::of($documentIds);
    }

    /**
     * Moves a deleted receipt's photo to the DMS trash through the module's own handle (the record's
     * delete is the gate, not the session's DMS right). Throws when the photo was not moved (outside
     * the «Belege» partition, unknown). A harness host overrides it.
     */
    private function receiptDeletePhoto(int $documentId): void
    {
        if (!(new ReceiptCaptureService($this->em()))->trashPhoto($documentId)) {
            throw new \RuntimeException("photo {$documentId} is not in the module's partition (or unknown) — left in place");
        }
    }

    private function receiptRows(): ReceiptRepository
    {
        return $this->em()->getRepository(Receipt::class);
    }

    // ── list ─────────────────────────────────────────────────────────────

    protected function listAction(): HtmlResponse
    {
        $request    = DI::getRequest();
        $fetch      = ListDefinition::isFetch($request);
        $currency   = ReceiptFields::currency();
        $definition = ReceiptJournalListing::definition($currency);
        $state      = $definition->readRequest($request);
        $view       = $state->extra('view');
        $pending    = $this->receiptJournal()->pending();
        // The whole journal is every captured receipt dated up to today — a receipt is never dated in
        // the future (E6, `ReceiptFields::date()`), so today's run IS the journal.
        $preview    = $pending === [] ? null : $this->receiptPosting()->preview(new \DateTimeImmutable('today'));
        $context    = [
            'view'       => $view,
            'totals'     => self::receiptTotals($pending, $currency),
            'preview'    => $preview,
            'blockers'   => $preview === null ? [] : self::receiptBlockers($preview['blocked'], $pending),
            'taxLabels'  => $preview === null ? [] : $this->receiptTaxLabels($preview['vat']),
            'currency'   => $currency,
            'actionBase' => $this->receiptJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
            'choices'    => new ReceiptChoices($this->em()),   // «nur Steuer»: the Soll the posting derives
            // «Zahlungen suchen …» only while a bank account reads statements (ADR-061 stage C5).
            'bankAvailable' => $this->receiptBankMatch()->available(),
            'bankLinks'     => [],
        ];
        if ($view === ReceiptJournalListing::VIEW_POSTED) {
            // The retention backfill (receipt.md): a posted receipt's photo without its 10-year
            // deadline — posted before the rule, or its write failed after a commit — gets it here.
            try {
                (new ReceiptRetention($this->em()))->apply();
            } catch (\Throwable $e) {
                error_log('receipt retention backfill: ' . get_class($e) . ': ' . $e->getMessage());
            }
            $posted      = ReceiptJournalListing::postedDefinition();
            $postedState = $posted->readRequest($request);
            $total       = $this->receiptPosting()->countProtocols();
            $paging      = $postedState->paging($total);
            $context    += [
                'definition' => $posted,
                'state'      => $postedState,
                'paging'     => $paging,
                'protocols'  => $total === 0 ? [] : $this->receiptPosting()->protocols($paging->offset(), $paging->pageSize, $postedState->descending),
                'receipts'   => [],
                'photos'     => [],
                'templates'  => [],
                'methods'    => [],
            ];
        } else {
            $search   = ReceiptJournalListing::search($state);
            $total    = $this->receiptRows()->countSearch($search);
            $paging   = $state->paging($total);
            $receipts = $total === 0 ? [] : $this->receiptRows()->search($search, $paging->offset(), $paging->pageSize);
            // Which rows found their bank movement (one read).
            $context['bankLinks'] = $receipts === [] ? [] : $this->receiptBankMatch()->linksOf($receipts);
            $context += [
                'definition' => $definition,
                'state'      => $state,
                'paging'     => $paging,
                'receipts'   => $receipts,
                'photos'     => $this->receiptPhotos(array_map(static fn(Receipt $r) => $r->getDocumentId(), $receipts)),
                'templates'  => array_column(array_map(static fn(ReceiptTemplate $t) => [$t->getCode(), $t->getLabel()], (new ReceiptTemplateService($this->em()))->all()), 1, 0),
                'methods'    => array_column(array_map(static fn(PaymentMethod $m) => [$m->getCode(), $m->getLabel()], (new PaymentMethodService($this->em()))->all()), 1, 0),
                'protocols'  => [],
            ];
        }
        if (!$fetch) {
            $this->help->attach('Backend/ReceiptJournalController/list.help', self::RECEIPT_JOURNAL_NS, [], 'Hilfe: Belegjournal', toolbar: true);
        }
        $response = $this->html($context);
        if (!$fetch) {
            $this->layoutManager->addPartials('act', 'Backend/ReceiptJournalController', self::RECEIPT_JOURNAL_NS, 'hc1');
            $this->layoutManager->addPartials('toolbar', 'Backend/ReceiptJournalController', self::RECEIPT_JOURNAL_NS, 'hc2');
        }

        return $response;
    }

    // ── the receipt window ───────────────────────────────────────────────

    /**
     * The receipt WINDOW (ADR-047; board «Beleg als Fenster»): GET renders it, POST saves the
     * correction with the version the window showed. Fetched, the save closes the window and reloads
     * the list region it was opened from (the row, the band and the totals change together); a field
     * refusal re-renders the window with the messages and what was typed. A posted receipt is shown
     * read-only. Without the script it is a page: a plain form, redirect to the list.
     */
    #[Csrf]
    protected function editAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $receipt = $this->receiptJournal()->find((int) $request->getGetParameter('id'));
        if ($receipt === null) {
            return $this->receiptRefuse('Diesen Beleg gibt es nicht mehr — das Belegjournal neu laden.');
        }
        $values = self::receiptValues($receipt);
        $errors = [];
        $error  = '';
        if ($request->isPost() && $receipt->isCaptured()) {
            $post   = $request->getPostParameters();
            $values = self::receiptPosted($post);
            // The select's token taken apart by the one grammar; the service judges it (`vatChoice()`).
            $vat    = ReceiptFields::vatTokenParts($values['tax_code']);
            try {
                $saved = $this->receiptJournal()->update((int) $receipt->getId(), (int) ($post['version'] ?? 0), new ReceiptCorrection(
                    $values['date'], $values['amount'], $values['text'], $values['debit'], $values['credit'],
                    $vat['code'], $vat['withoutVat'], $vat['taxOnly'],
                ));

                return $this->receiptSaved(ReceiptPostingBuilder::name($saved) . ' gespeichert'
                    . ($saved->isComplete() ? ' — vollständig.' : ' — noch unvollständig, es fehlt: ' . implode(', ', $saved->missing()) . '.'));
            } catch (InvalidReceiptException $e) {
                $errors = $e->errors;
            } catch (ReceiptRefusedException $e) {
                return $this->receiptRefuse($e->getMessage());
            } catch (\Doctrine\DBAL\Exception $e) {
                if (!self::receiptLockConflict($e)) {
                    throw $e;
                }
                $error = self::RECEIPT_LOCK_MESSAGE;
            }
        }

        $response = $this->receiptWindow('edit', $receipt, [
            'values'    => $values,
            'errors'    => $errors,
            'error'     => $error,
            'photo'     => $this->receiptPhotos([$receipt->getDocumentId()])[$receipt->getDocumentId()] ?? ReceiptPhoto::missing($receipt->getDocumentId()),
            'templates' => (new ReceiptTemplateService($this->em()))->all(),
            'methods'   => (new PaymentMethodService($this->em()))->all(),
            'choices'   => new ReceiptChoices($this->em()),
            'liable'    => (new ReceiptFields($this->em()))->liableToVat(),
            // A posted receipt whose input tax was caught up: its second entry and the day it is dated on.
            'taxEntryDate' => $this->receiptTaxEntryDate($receipt),
            // ADR-061 stage C5: the bank movement this receipt is linked to; «Zahlung suchen …» while
            // none, a bank account reads statements and the receipt was paid through one.
            'bankLink'   => $this->receiptBankMatch()->linkOf($receipt),
            'bankSearch' => $this->receiptBankMatch()->available() && $this->receiptBankMatch()->bankAccountsOf($receipt) !== [],
            // Reverse charge (board S6-2, part 4): base and tax of the stored receipt, from the lines the posting builds.
            'reverseChargeTax' => (new ReceiptPostingBuilder($this->em()))->reverseChargeTax($receipt),
        ]);

        return $response;
    }

    /** The date of a posted receipt's TAX entry (`tax_entry_ref`, the caught-up input tax) — null when it has none. */
    private function receiptTaxEntryDate(Receipt $receipt): ?\DateTimeImmutable
    {
        $ref = $receipt->getTaxEntryRef();
        if ($ref === null || !preg_match('~^(.+)/(\d+)$~', $ref, $m)) {
            return null;
        }

        return $this->em()->getRepository(JournalEntry::class)->findByRef(new EntryRef($m[1], (int) $m[2]))?->getDate();
    }

    // ── the bank movement (ADR-061 stage C5) ─────────────────────────────

    /**
     * «Zahlungen suchen …» / «Zahlung suchen …» (ADR-061 decision 11, stage C5) — a WINDOW: GET
     * renders, per unlinked receipt the bank holds something for (`?id=` = only that receipt), the
     * movements that fit — a proposal ticked in advance, candidates not; POST (`#[Csrf]`,
     * `take[{receipt id}]` = `{version}:{movement id}`, empty = «nicht zuordnen») takes each
     * ticked pair through `ReceiptBankMatchService::take()` — each in its own unit of work; a
     * refused one is named in the flash, the others stand. The server judges every pair again.
     */
    #[Csrf]
    protected function confirmBankAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $bank    = $this->receiptBankMatch();
        if ($request->isPost()) {
            $taken   = 0;
            $refused = [];
            foreach (self::receiptBankTakes($request->getPostParameters()['take'] ?? null) as $take) {
                try {
                    $bank->take($take['id'], $take['version'], $take['movement']);
                    $taken++;
                } catch (ReceiptRefusedException $e) {
                    $refused[] = $e->reason === ReceiptRefusedException::CHANGED ? 'Beleg ' . $take['id'] . ': inzwischen geändert oder gelöscht — nicht zugeordnet' : rtrim($e->getMessage(), '.');
                } catch (\Doctrine\DBAL\Exception $e) {
                    if (!self::receiptLockConflict($e)) {
                        throw $e;
                    }
                    $refused[] = 'Beleg ' . $take['id'] . ': gleichzeitig von jemand anderem bearbeitet — nicht zugeordnet';
                }
            }
            if ($taken === 0 && $refused === []) {
                return $this->receiptRefuse('Keine Zahlung angekreuzt — nichts zugeordnet.');
            }

            return $this->receiptSaved(
                ($taken === 1 ? '1 Bankzahlung' : $taken . ' Bankzahlungen') . ' zugeordnet.' . ($refused !== [] ? ' Nicht zugeordnet: ' . implode('; ', $refused) . '.' : ''),
                $refused === [] ? 'success' : ($taken === 0 ? 'error' : 'info')
            );
        }
        $single = null;
        $id     = (int) $request->getGetParameter('id');
        if ($id > 0) {
            $single = $this->receiptJournal()->find($id);
            if ($single === null) {
                return $this->receiptRefuse('Diesen Beleg gibt es nicht mehr — das Belegjournal neu laden.');
            }
            if ($bank->linkOf($single) !== null) {
                return $this->receiptRefuse(ReceiptPostingBuilder::name($single) . ' ist bereits einer Bankbewegung zugeordnet.');
            }
            $match   = $bank->find($single);
            $pending = ['rows' => $match->kind === ReceiptBankMatch::NONE ? [] : [['receipt' => $single, 'match' => $match]], 'more' => false];
        } else {
            $pending = $bank->pending();
        }
        $this->help->attach('Backend/ReceiptJournalController/list.help', self::RECEIPT_JOURNAL_NS, [], 'Hilfe: Belegjournal');
        $response = $this->html([
            'rows'       => $pending['rows'],
            'more'       => $pending['more'],
            'single'     => $single,
            'currency'   => ReceiptFields::currency(),
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->receiptJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmBank', 'Backend/ReceiptJournalController', self::RECEIPT_JOURNAL_NS);

        return $response;
    }

    /**
     * The ticked pairs of «Zahlungen suchen …»: `take[{receipt id}]` = `{version}:{movement id}` —
     * anything else (also the empty «nicht zuordnen») dropped.
     *
     * @return list<array{id: int, version: int, movement: int}>
     */
    private static function receiptBankTakes(mixed $values): array
    {
        $takes = [];
        foreach (is_array($values) ? $values : [] as $id => $value) {
            if ((int) $id > 0 && is_string($value) && preg_match('/^(\d{1,9}):(\d{1,9})$/', $value, $m)) {
                $takes[] = ['id' => (int) $id, 'version' => (int) $m[1], 'movement' => (int) $m[2]];
            }
        }

        return $takes;
    }

    /**
     * «Zuordnung lösen» (ADR-061 decision 20): a submit of the window's form (`formaction`), so it
     * carries the version shown. The link is gone and the movement free again; a posted receipt is
     * refused with the service's sentence.
     */
    #[Csrf]
    protected function releaseMovementAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $id      = (int) $request->getGetParameter('id');
        if (!$request->isPost() || $id <= 0) {
            return $this->receiptRefuse('Diesen Beleg gibt es nicht mehr — das Belegjournal neu laden.');
        }
        try {
            $released = $this->receiptJournal()->releaseMovement($id, (int) ($request->getPostParameters()['version'] ?? 0));
        } catch (ReceiptRefusedException $e) {
            return $this->receiptRefuse($e->getMessage());
        }

        return $this->receiptSaved(ReceiptPostingBuilder::name($released) . ': Zuordnung zur Bankbewegung gelöst.');
    }

    /**
     * «Aufteilen …» (E12), in the receipt window (`data-window-link`): the amount of the NEW part; the
     * receipt keeps the rest, the new part shares photo, date, text and «Bezahlt mit» and waits for its
     * Soll and VAT. The answer closes the window and reloads the list — the new part stands there with
     * «Konten fehlen».
     */
    #[Csrf]
    protected function splitAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $receipt = $this->receiptJournal()->find((int) $request->getGetParameter('id'));
        if ($receipt === null || !$receipt->isCaptured()) {
            return $this->receiptRefuse('Dieser Beleg ist nicht mehr im Belegjournal — neu laden.');
        }
        $amount = '';
        $errors = [];
        if ($request->isPost()) {
            $post   = $request->getPostParameters();
            $amount = trim((string) ($post['amount'] ?? ''));
            try {
                [$kept, $part] = $this->receiptJournal()->split((int) $receipt->getId(), (int) ($post['version'] ?? 0), $amount);

                return $this->receiptSaved('Beleg aufgeteilt: ' . AmountFormat::of($kept->getAmount()) . ' bleiben, ' . AmountFormat::of($part->getAmount())
                    . ' stehen als ' . ReceiptPostingBuilder::name($part) . ' im Journal — dort Soll und MWST ergänzen.');
            } catch (InvalidReceiptException $e) {
                $errors = $e->errors;
            } catch (ReceiptRefusedException $e) {
                return $this->receiptRefuse($e->getMessage());
            }
        }

        return $this->receiptWindow('split', $receipt, ['amount' => $amount, 'errors' => $errors]);
    }

    /**
     * «Löschen …», in the receipt window: the last look and what happens to the photo. POST deletes
     * the receipt with the version shown; when `delete()` answers the photo's id (no split part names
     * it any more) the photo goes to the DMS trash right after the commit (owner 2026-11-20: the
     * deleted record's document goes to the trash — the former switch «Foto auch löschen», off by
     * default, left it in «Belege»). A captured receipt has no retention deadline yet; the trash is
     * restorable. A failing move is logged and the flash says the photo stayed — the receipt is
     * deleted either way, the photo never lost.
     */
    #[Csrf]
    protected function confirmDeleteAction(): HtmlResponse|FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $receipt = $this->receiptJournal()->find((int) $request->getGetParameter('id'));
        if ($receipt === null || !$receipt->isCaptured()) {
            return $this->receiptRefuse('Dieser Beleg ist nicht mehr im Belegjournal — neu laden.');
        }
        $photo  = $this->receiptPhotos([$receipt->getDocumentId()])[$receipt->getDocumentId()] ?? ReceiptPhoto::missing($receipt->getDocumentId());
        $shared = $this->receiptRows()->countSharingDocument($receipt->getDocumentId(), (int) $receipt->getId()) > 0;
        if ($request->isPost()) {
            $post = $request->getPostParameters();
            $name = ReceiptPostingBuilder::name($receipt);
            try {
                $documentId = $this->receiptJournal()->delete((int) $receipt->getId(), (int) ($post['version'] ?? 0));
            } catch (ReceiptRefusedException $e) {
                return $this->receiptRefuse($e->getMessage());
            }
            $text = $name . ' gelöscht.';
            if ($documentId !== null) {
                try {
                    $this->receiptDeletePhoto($documentId);
                    $text .= ' Das Foto liegt im Papierkorb der Dokumente.';
                } catch (\Throwable $e) {
                    error_log('receipt photo to trash: ' . $documentId . ': ' . get_class($e) . ': ' . $e->getMessage());
                    $text .= ' Das Foto konnte nicht in den Papierkorb — es bleibt unter «Belege» in den Dokumenten.';
                }
            }

            return $this->receiptSaved($text);
        }

        return $this->receiptWindow('confirmDelete', $receipt, [
            'photo'          => $photo,
            'sharedPhoto'    => $shared,
        ]);
    }

    // ── fill helpers of the window (core.js «fill») ─────────────────────

    /**
     * The template's Soll and VAT decision for the receipt window — reads only; the save decides. A
     * Soll naming a VAT account locked on the receipt's day (`date`, the window's field riding along;
     * unreadable → today) fills empty (`ReceiptChoices::offeredAccount()`); a «nur Steuer» template
     * fills the select's token `VM:nur-steuer` and LOCKS Soll with the input account of the code (the
     * posting derives it — `ReceiptChoices::debitLock()`, the window's `debit_kept` rides along); any
     * other template frees Soll and fills its own.
     */
    protected function templateFillAction(): FetchResponse
    {
        $request  = DI::getRequest();
        $template = (new ReceiptTemplateService($this->em()))->find(trim((string) $request->getGetParameter('value')));
        if ($template === null) {
            return $this->fetch()->setStatus('success')->setData(['fill' => []]);
        }
        $choices = new ReceiptChoices($this->em());
        $date    = ManualEntryForm::parseDate(trim((string) $request->getGetParameter('date')));
        $taxOnly = $template->isTaxOnly() && !$template->isWithoutVat();
        $token   = ReceiptFields::vatToken($template->isWithoutVat() ? null : $template->getTaxCode(), $template->isWithoutVat(), $taxOnly);
        if ($taxOnly) {
            // What was typed is replaced by the template — a later release of the lock brings back an empty Soll.
            $lock = $choices->debitLock($token, '', trim((string) $request->getGetParameter('debit_kept')));

            return $this->fetch()->setStatus('success')->setData(['fill' => $lock['fill'] + ['tax_code' => $token], 'lock' => $lock['lock']]);
        }
        $debit = (string) $choices->offeredAccount($template->getDebitAccount(), $date);

        return $this->fetch()->setStatus('success')->setData([
            'fill' => ['debit' => $debit, 'tax_code' => $token, 'debit_note' => $choices->accountName($debit), 'debit_kept' => ''],
            'lock' => ['debit' => false],
        ]);
    }

    /**
     * The VAT select changed (core.js «fill», the window's form is the scope): «nur Steuer» LOCKS
     * Soll and shows the input account of the code with «automatisch»; any other choice frees it and
     * brings back what was typed before (`ReceiptChoices::debitLock()`; `debit` and `debit_kept` ride
     * along). Display only — the save clears and derives the account itself (S56-2, Rule 7).
     */
    protected function taxFillAction(): FetchResponse
    {
        $request = DI::getRequest();
        $lock    = (new ReceiptChoices($this->em()))->debitLock(
            (string) $request->getGetParameter('value'),
            (string) $request->getGetParameter('debit'),
            trim((string) $request->getGetParameter('debit_kept')),
        );

        return $this->fetch()->setStatus('success')->setData($lock);
    }

    /** The payment method's Haben for the receipt window — reads only; the save decides. */
    protected function paymentFillAction(): FetchResponse
    {
        $method = (new PaymentMethodService($this->em()))->find(trim((string) DI::getRequest()->getGetParameter('value')));

        return $this->fetch()->setStatus('success')->setData(['fill' => $method === null ? [] : ['credit' => $method->getCreditAccount()]]);
    }

    // ── «Journal verbuchen» ──────────────────────────────────────────────

    /**
     * «Journal verbuchen …» — the posting WINDOW (owner decision E16; without the script a page) for
     * the run up to the CUT-OFF (`?cutoff=YYYY-MM-DD`, E11; proposed today,
     * {@see ReceiptJournalPostingService::defaultCutOff()}). Everything from `preview()`, the builder
     * the posting calls: a blocked receipt up to the cut-off → only the errors, each a link to its
     * window; else count, total, VAT per code and «Jetzt buchen». The form carries the cut-off and
     * every receipt's `{id}:{version}`; the posting refuses when the run changed since. The date is a
     * GET form inside the window (core.js `data-window-form`) — a change re-renders this action.
     */
    protected function confirmPostAction(): HtmlResponse
    {
        $request = DI::getRequest();
        $raw     = trim((string) $request->getGetParameter('cutoff'));
        $cutOff  = $raw === '' ? null : ManualEntryForm::parseDate($raw);
        $note    = $raw !== '' && $cutOff === null ? 'Der Stichtag «' . $raw . '» ist kein Datum — es gilt heute.' : '';
        $cutOff ??= ReceiptJournalPostingService::defaultCutOff();
        $preview = $this->receiptPosting()->preview($cutOff);
        $pending = $this->receiptJournal()->pending();

        // The journal's help explains the run and the cut-off (section `cutoff`): in the window's
        // title bar, as a page in the top bar.
        $this->help->attach('Backend/ReceiptJournalController/list.help', self::RECEIPT_JOURNAL_NS, [], 'Hilfe: Belegjournal');
        $response = $this->html([
            'preview'    => $preview,
            'cutOff'     => $cutOff,
            'note'       => $note,
            'blockers'   => self::receiptBlockers($preview['blocked'], $pending),
            'later'      => count($pending) - count($preview['seen']),
            'taxLabels'  => $this->receiptTaxLabels($preview['vat']),
            'currency'   => ReceiptFields::currency(),
            'actionBase' => $this->receiptJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPost', 'Backend/ReceiptJournalController', self::RECEIPT_JOURNAL_NS);

        return $response;
    }

    /**
     * The POST of «Journal verbuchen» (`#[Csrf]`): the cut-off and the pairs the window carried to
     * `ReceiptJournalPostingService::post()` — ONE unit of work, all or nothing. Fetched (the
     * window): the flash, the window closes, the list region it was opened from reloads; a refusal
     * (empty, changed, incomplete, the ledger's) re-renders the window for THAT cut-off with the
     * German sentence — nothing was posted. As a page: a 303 to «Verbuchte Journale», a refusal a
     * 303 back to the confirmation.
     */
    #[Csrf]
    protected function postAction(): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : [];
        $raw     = trim((string) ($post['cutoff'] ?? ''));
        $cutOff  = ManualEntryForm::parseDate($raw);
        $back    = $this->receiptJournalBase() . '/confirm-post' . ($cutOff !== null ? '?cutoff=' . $cutOff->format('Y-m-d') : '');
        if ($cutOff === null) {
            return $this->receiptPostRefused('Nichts wurde verbucht: ohne gültigen Stichtag.', $back);
        }
        try {
            $protocol = $this->receiptPosting()->post($cutOff, self::receiptPairs($post['rec'] ?? null));
        } catch (ReceiptException $e) {
            return $this->receiptPostRefused($e instanceof ReceiptRefusedException ? $e->getMessage() : 'Nichts wurde verbucht: ' . $e->getMessage(), $back);
        } catch (\Doctrine\DBAL\Exception $e) {
            error_log('receipt journal posting: ' . get_class($e) . ': ' . $e->getMessage());

            return $this->receiptPostRefused('Nichts wurde verbucht: ' . (self::receiptLockConflict($e) ? self::RECEIPT_LOCK_MESSAGE : 'die Datenbank hat das Verbuchen abgelehnt — nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.'), $back);
        }
        $count = $protocol->getReceiptCount();
        $flash = 'Belegjournal verbucht bis ' . $cutOff->format('d.m.Y') . ': ' . ($count === 1 ? '1 Beleg' : $count . ' Belege') . ', Total ' . AmountFormat::of($protocol->getGrossTotal()) . '.';
        if (ListDefinition::isFetch($request)) {
            return $this->receiptSaved($flash);
        }
        $this->messageService->pushFlashAfterRedirect('success', $flash);

        return $this->redirect($this->receiptJournalBase() . '/list?view=' . ReceiptJournalListing::VIEW_POSTED, 303);
    }

    /** Nothing was posted: fetched, the window again for that cut-off with the sentence; as a page, back to the confirmation. */
    private function receiptPostRefused(string $message, string $back): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message)->addCommand('open-window', ['url' => $back, 'replace' => true]);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($back, 303);
    }

    // ── answers and helpers ──────────────────────────────────────────────

    private const RECEIPT_LOCK_MESSAGE = 'Jemand anderes hat gleichzeitig an denselben Belegen gearbeitet — nichts gespeichert. Bitte neu laden und nochmals versuchen.';

    /** A window of $template for $receipt (edit, split, confirmDelete) — the receipt help (a section per field of all three) attached before html(). */
    private function receiptWindow(string $template, Receipt $receipt, array $context): HtmlResponse
    {
        $request = DI::getRequest();
        $this->help->attach('Backend/ReceiptJournalController/edit.help', self::RECEIPT_JOURNAL_NS, [], 'Hilfe: Beleg');
        $response = $this->html($context + [
            'receipt'    => $receipt,
            'window'     => ListDefinition::isFetch($request),
            'origin'     => WindowOrigin::of($request),
            'actionBase' => $this->receiptJournalBase(),
            'fmt'        => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials($template, 'Backend/ReceiptJournalController', self::RECEIPT_JOURNAL_NS);

        return $response;
    }

    /**
     * Close the window and reload what it came from — the list region, or the window it was opened
     * from (a receipt fixed out of «Journal verbuchen»: that window's errors / summary follow);
     * without the script: flash + list.
     */
    private function receiptSaved(string $flash, string $type = 'success'): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect($type, $flash);

            return $this->redirect($this->receiptJournalBase() . '/list', 303);
        }
        $this->messageService->pushFlash($type, $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
        } elseif (str_starts_with($origin, 'window:')) {
            $response->addCommand('refresh-window', ['window' => substr($origin, strlen('window:'))]);
        }

        return $response;
    }

    private function receiptRefuse(string $message): FetchResponse|RedirectResponse
    {
        if (ListDefinition::isFetch(DI::getRequest())) {
            return $this->fetchError($message);
        }
        $this->messageService->pushFlashAfterRedirect('error', $message);

        return $this->redirect($this->receiptJournalBase() . '/list', 303);
    }

    /** code → label of the VAT rows («VI» → «Vorsteuer Investitionen …»). */
    private function receiptTaxLabels(array $vat): array
    {
        $choices = new ReceiptChoices($this->em());
        $labels  = [];
        foreach ($vat as $row) {
            $labels[$row['code']] ??= $choices->taxLabel($row['code']);
        }

        return $labels;
    }

    /**
     * Count, gross total and oldest date of the open journal — the pending step at the top.
     *
     * @param list<Receipt> $pending
     * @return array{count: int, gross: Money, oldest: ?\DateTimeImmutable}
     */
    private static function receiptTotals(array $pending, string $currency): array
    {
        $gross = Money::zero($currency);
        foreach ($pending as $receipt) {
            $gross = $gross->add($receipt->getAmount());
        }

        return ['count' => count($pending), 'gross' => $gross, 'oldest' => $pending === [] ? null : $pending[0]->getReceiptDate()];
    }

    /**
     * The blocked receipts of a preview with what the link needs: id, «11.11.2026 · Baumarkt …»,
     * the reason (German, from the service).
     *
     * @param list<array{id: int, reason: string}> $blocked
     * @param list<Receipt> $pending
     * @return list<array{id: int, label: string, reason: string}>
     */
    private static function receiptBlockers(array $blocked, array $pending): array
    {
        $byId = [];
        foreach ($pending as $receipt) {
            $byId[(int) $receipt->getId()] = $receipt;
        }
        $out = [];
        foreach ($blocked as $b) {
            $r     = $byId[$b['id']] ?? null;
            $out[] = [
                'id'     => $b['id'],
                'label'  => $r === null ? 'Beleg ' . $b['id'] : $r->getReceiptDate()->format('d.m.Y') . ' · ' . ($r->getText() !== '' ? $r->getText() : 'ohne Text'),
                'reason' => $r !== null && !$r->isComplete() ? 'es fehlt: ' . implode(', ', $r->missing()) : $b['reason'],
            ];
        }

        return $out;
    }

    /**
     * The values a window shows for $receipt — strings as the fields hold them; the VAT select's value
     * is its token (`ReceiptFields::vatToken()`): the code, `VM:nur-steuer`, «-» for «ohne MWST», ''
     * for not decided.
     *
     * @return array{date: string, amount: string, text: string, debit: string, credit: string, tax_code: string}
     */
    private static function receiptValues(Receipt $receipt): array
    {
        return [
            'date'     => $receipt->getReceiptDate()->format('Y-m-d'),
            'amount'   => $receipt->getAmount()->toDecimal(),
            'text'     => $receipt->getText(),
            'debit'    => (string) $receipt->getDebitAccount(),
            'credit'   => (string) $receipt->getCreditAccount(),
            'tax_code' => ReceiptFields::vatToken($receipt->getTaxCode(), $receipt->isWithoutVat(), $receipt->isTaxOnly()),
        ];
    }

    /** The same shape out of a POST — strings only, anything else empty (the service judges each one). */
    private static function receiptPosted(array $post): array
    {
        $values = [];
        foreach (['date', 'amount', 'text', 'debit', 'credit', 'tax_code'] as $key) {
            $values[$key] = is_string($post[$key] ?? null) ? $post[$key] : '';
        }

        return $values;
    }

    /**
     * `{id}:{version}` strings → the pairs `post()` takes; anything else is dropped (the value only
     * names receipts, the service re-checks every one of them under its lock).
     *
     * @return list<array{id: int, version: int}>
     */
    private static function receiptPairs(mixed $values): array
    {
        $pairs = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && preg_match('/^(\d{1,9}):(\d{1,9})$/', $value, $m)) {
                $pairs[(int) $m[1]] = ['id' => (int) $m[1], 'version' => (int) $m[2]];
            }
        }

        return array_values($pairs);
    }

    /** A deadlock or lock wait timeout anywhere in the chain (DOCTRINE-TX-005) — nothing was written. */
    private static function receiptLockConflict(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof RetryableException) {
                return true;
            }
        }

        return false;
    }
}
