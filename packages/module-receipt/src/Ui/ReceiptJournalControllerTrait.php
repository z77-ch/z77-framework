<?php
namespace Z77\Module\Receipt\Ui;

use Doctrine\DBAL\Exception\RetryableException,
    Z77\Core\DI,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\RedirectResponse,
    Z77\Core\Http\WindowOrigin,
    Z77\Module\Dms\Services\DocumentService,
    Z77\Module\Financial\Ui\ManualEntryForm,
    Z77\Module\Receipt\Entities\PaymentMethod,
    Z77\Module\Receipt\Entities\Receipt,
    Z77\Module\Receipt\Entities\ReceiptTemplate,
    Z77\Module\Receipt\Repositories\ReceiptRepository,
    Z77\Module\Receipt\Services\InvalidReceiptException,
    Z77\Module\Receipt\Services\PaymentMethodService,
    Z77\Module\Receipt\Services\ReceiptCorrection,
    Z77\Module\Receipt\Services\ReceiptException,
    Z77\Module\Receipt\Services\ReceiptFields,
    Z77\Module\Receipt\Services\ReceiptJournalPostingService,
    Z77\Module\Receipt\Services\ReceiptJournalService,
    Z77\Module\Receipt\Services\ReceiptPostingBuilder,
    Z77\Module\Receipt\Services\ReceiptRefusedException,
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
 *   - action cell (hc1): «Journal verbuchen …» — the entry's most frequent action, a green confirm;
 *     disabled with its reason while the journal is empty;
 *   - toolbar (hc2): the tabs «Im Journal» · «Verbuchte Journale»; «Hilfe» at its right end (HELP-003);
 *   - the pending step at the top: «n Belege im Journal · noch nicht verbucht · Total … · ältester
 *     vom …», or — red — what keeps receipts from being posted, each with a link to its window;
 *   - the work area: a STANDARD LIST of the captured receipts ({@see ReceiptJournalListing}) — the
 *     state icon (✓ complete / ✎ incomplete) opens the receipt WINDOW (ADR-047); the photo as a
 *     thumbnail; under it the totals per VAT code (`ReceiptJournalPostingService::preview()` — the
 *     builder that posts, never a VAT computation here). Tab «Verbuchte Journale»: the protocols.
 *
 * Actions:
 *
 *   - `edit` — the receipt WINDOW: the photo large beside the fields (date, amount, text, Soll, Haben,
 *     the VAT decision), the template and «Bezahlt mit» as fill helpers (`data-fill-url` → `template-fill`
 *     / `payment-fill`, core.js «fill» — the server answers the values, no script of its own, Rule 7);
 *     the save is `ReceiptJournalService::update()` with the version the window showed;
 *   - `split` — «Aufteilen …» (E12): the amount of the new part → `split()`;
 *   - `confirm-delete` — «Löschen …»: `delete()`; when it answers the photo's document id (no split
 *     part names it any more), the switch «Foto auch löschen» moves it to the DMS trash through
 *     `DocumentService::delete()` — the session's own DMS right (`manage`) decides, as in the Drive;
 *   - `confirm-post` — «Journal verbuchen …»: a PAGE with the cut-off (E11, default
 *     {@see ReceiptJournalPostingService::defaultCutOff()}), the run, what blocks it, the VAT per code
 *     and the entries per account; the form carries every receipt's `{id}:{version}`;
 *   - `post` (POST, `#[Csrf]`) — `ReceiptJournalPostingService::post()`, all or nothing.
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

    /** The photos of the shown receipts (DMS, read-gated). A harness host overrides it — the DMS gate needs a session. */
    private function receiptPhotos(array $documentIds): array
    {
        return ReceiptPhoto::of($documentIds);
    }

    /** Moves a photo to the DMS trash with the session's right. A harness host overrides it. */
    private function receiptDeletePhoto(int $documentId): void
    {
        DocumentService::create()->delete($documentId);
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
        ];
        if ($view === ReceiptJournalListing::VIEW_POSTED) {
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
            $vat    = $values['tax_code'];
            try {
                $saved = $this->receiptJournal()->update((int) $receipt->getId(), (int) ($post['version'] ?? 0), new ReceiptCorrection(
                    $values['date'], $values['amount'], $values['text'], $values['debit'], $values['credit'],
                    $vat === ReceiptChoices::WITHOUT_VAT ? '' : $vat, $vat === ReceiptChoices::WITHOUT_VAT,
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

        return $this->receiptWindow('edit', $receipt, [
            'values'    => $values,
            'errors'    => $errors,
            'error'     => $error,
            'photo'     => $this->receiptPhotos([$receipt->getDocumentId()])[$receipt->getDocumentId()] ?? ReceiptPhoto::missing($receipt->getDocumentId()),
            'templates' => (new ReceiptTemplateService($this->em()))->all(),
            'methods'   => (new PaymentMethodService($this->em()))->all(),
            'choices'   => new ReceiptChoices($this->em()),
        ]);
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
     * «Löschen …», in the receipt window: the last look and — only when the photo would be left
     * without a receipt (no split part names it) and the session may manage it — the switch «Foto
     * auch löschen» (off by default: the receipt journal keeps photos, receipt.md). POST deletes the
     * receipt with the version shown; the photo goes to the DMS trash only when the switch is on and
     * `delete()` answered its id.
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
            if ($documentId !== null && ($post['delete_photo'] ?? '') === '1') {
                try {
                    $this->receiptDeletePhoto($documentId);
                    $text .= ' Das Foto liegt im Papierkorb der Dokumente.';
                } catch (\Throwable $e) {
                    error_log('receipt photo delete: ' . get_class($e) . ': ' . $e->getMessage());
                    $text .= ' Das Foto konnte nicht gelöscht werden — es bleibt unter «Belege» in den Dokumenten.';
                }
            } elseif ($documentId !== null) {
                $text .= ' Das Foto bleibt unter «Belege» in den Dokumenten.';
            }

            return $this->receiptSaved($text);
        }

        return $this->receiptWindow('confirmDelete', $receipt, [
            'photo'          => $photo,
            'offerPhoto'     => !$shared && $photo['state'] === 'doc' && $photo['canDelete'],
            'sharedPhoto'    => $shared,
        ]);
    }

    // ── fill helpers of the window (core.js «fill») ─────────────────────

    /** The template's Soll and VAT decision for the receipt window — reads only; the save decides. */
    protected function templateFillAction(): FetchResponse
    {
        $template = (new ReceiptTemplateService($this->em()))->find(trim((string) DI::getRequest()->getGetParameter('value')));
        if ($template === null) {
            return $this->fetch()->setStatus('success')->setData(['fill' => []]);
        }

        return $this->fetch()->setStatus('success')->setData(['fill' => [
            'debit'    => $template->getDebitAccount(),
            'tax_code' => $template->isWithoutVat() ? ReceiptChoices::WITHOUT_VAT : $template->getTaxCode(),
        ]]);
    }

    /** The payment method's Haben for the receipt window — reads only; the save decides. */
    protected function paymentFillAction(): FetchResponse
    {
        $method = (new PaymentMethodService($this->em()))->find(trim((string) DI::getRequest()->getGetParameter('value')));

        return $this->fetch()->setStatus('success')->setData(['fill' => $method === null ? [] : ['credit' => $method->getCreditAccount()]]);
    }

    // ── «Journal verbuchen» ──────────────────────────────────────────────

    /**
     * «Journal verbuchen …» — the last look (a PAGE, the Faktura-Journal's model) for the run up to
     * the CUT-OFF (`?cutoff=YYYY-MM-DD`, E11; default the last day of the previous month): how many
     * receipts, their total, what blocks the run, the VAT per code and the entries per account — all
     * from `preview()`, the builder the posting calls. The form carries the cut-off and every
     * receipt's `{id}:{version}`; the posting refuses when the run changed since.
     */
    protected function confirmPostAction(): HtmlResponse
    {
        $raw     = trim((string) DI::getRequest()->getGetParameter('cutoff'));
        $cutOff  = $raw === '' ? null : ManualEntryForm::parseDate($raw);
        $note    = $raw !== '' && $cutOff === null ? 'Der Stichtag «' . $raw . '» ist kein Datum — es gilt der vorgeschlagene.' : '';
        $cutOff ??= ReceiptJournalPostingService::defaultCutOff();
        $preview = $this->receiptPosting()->preview($cutOff);
        $pending = $this->receiptJournal()->pending();
        $choices = new ReceiptChoices($this->em());

        // The journal's help explains the run and the cut-off (section `cutoff`); on this page it
        // stands in the top bar (not a list).
        $this->help->attach('Backend/ReceiptJournalController/list.help', self::RECEIPT_JOURNAL_NS, [], 'Hilfe: Belegjournal');
        $response = $this->html([
            'preview'      => $preview,
            'cutOff'       => $cutOff,
            'note'         => $note,
            'blockers'     => self::receiptBlockers($preview['blocked'], $pending),
            'later'        => count($pending) - count($preview['seen']),
            'taxLabels'    => $this->receiptTaxLabels($preview['vat']),
            'accountNames' => array_combine(array_map(static fn(array $r) => $r['account'], $preview['rows']), array_map(static fn(array $r) => $choices->accountName($r['account']), $preview['rows'])) ?: [],
            'currency'     => ReceiptFields::currency(),
            'actionBase'   => $this->receiptJournalBase(),
            'fmt'          => static fn(?Money $m) => AmountFormat::of($m),
        ]);
        $this->layoutManager->removeSection('main');
        $this->layoutManager->addPartials('confirmPost', 'Backend/ReceiptJournalController', self::RECEIPT_JOURNAL_NS);

        return $response;
    }

    /**
     * The POST of «Journal verbuchen» (`#[Csrf]`): the cut-off and the pairs the confirmation carried
     * to `ReceiptJournalPostingService::post()` — ONE unit of work, all or nothing — and a 303 to the
     * tab «Verbuchte Journale» with the flash; a refusal (empty, changed, incomplete, the ledger's)
     * answers the confirmation page again with the German sentence — nothing was posted.
     */
    #[Csrf]
    protected function postAction(): RedirectResponse
    {
        $request = DI::getRequest();
        $post    = $request->isPost() ? $request->getPostParameters() : [];
        $raw     = trim((string) ($post['cutoff'] ?? ''));
        $cutOff  = ManualEntryForm::parseDate($raw);
        $back    = $this->receiptJournalBase() . '/confirm-post' . ($cutOff !== null ? '?cutoff=' . $cutOff->format('Y-m-d') : '');
        if ($cutOff === null) {
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts wurde verbucht: ohne gültigen Stichtag.');

            return $this->redirect($back, 303);
        }
        try {
            $protocol = $this->receiptPosting()->post($cutOff, self::receiptPairs($post['rec'] ?? null));
        } catch (ReceiptException $e) {
            $this->messageService->pushFlashAfterRedirect('error', $e instanceof ReceiptRefusedException ? $e->getMessage() : 'Nichts wurde verbucht: ' . $e->getMessage());

            return $this->redirect($back, 303);
        } catch (\Doctrine\DBAL\Exception $e) {
            error_log('receipt journal posting: ' . get_class($e) . ': ' . $e->getMessage());
            $this->messageService->pushFlashAfterRedirect('error', 'Nichts wurde verbucht: ' . (self::receiptLockConflict($e) ? self::RECEIPT_LOCK_MESSAGE : 'die Datenbank hat das Verbuchen abgelehnt — nochmals versuchen; bleibt es dabei, steht die Ursache im Fehlerprotokoll.'));

            return $this->redirect($back, 303);
        }
        $count = $protocol->getReceiptCount();
        $this->messageService->pushFlashAfterRedirect('success', 'Belegjournal verbucht bis ' . $cutOff->format('d.m.Y') . ': ' . ($count === 1 ? '1 Beleg' : $count . ' Belege') . ', Total ' . AmountFormat::of($protocol->getGrossTotal()) . '.');

        return $this->redirect($this->receiptJournalBase() . '/list?view=' . ReceiptJournalListing::VIEW_POSTED, 303);
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

    /** Close the window and reload the list region it came from; without the script: flash + list. */
    private function receiptSaved(string $flash): FetchResponse|RedirectResponse
    {
        $request = DI::getRequest();
        if (!ListDefinition::isFetch($request)) {
            $this->messageService->pushFlashAfterRedirect('success', $flash);

            return $this->redirect($this->receiptJournalBase() . '/list', 303);
        }
        $this->messageService->pushFlash('success', $flash);
        $response = $this->fetch()->setStatus('success')->addCommand('close-window');
        $origin   = WindowOrigin::of($request);
        if (str_starts_with($origin, 'region:')) {
            $response->addCommand('refresh-region', ['name' => substr($origin, strlen('region:'))]);
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
     * is the code, «-» for «ohne MWST», '' for not decided.
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
            'tax_code' => $receipt->isWithoutVat() ? ReceiptChoices::WITHOUT_VAT : (string) $receipt->getTaxCode(),
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
