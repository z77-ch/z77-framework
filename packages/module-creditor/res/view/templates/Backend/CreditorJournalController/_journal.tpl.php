<?php
/**
 * The list region of the «Kreditorenjournal» — one tab at a time (toolbar):
 *
 *   - «Im Journal» / «Gesperrt»: the pending step at the top (backend-screen.md §4) — count,
 *     «n unbezahlt · n bezahlt, nicht verbucht» and total, red while invoices are blocked as
 *     suspected duplicates; in «Im Journal» the SELECTION BAR «Bezahlt am …» above the list
 *     (backend-screen.md §4: a selection-bound action, green, above the list) — a GET form
 *     `#creditor-paid` (`data-window-form`: the answer opens as a window, the ticked rows travel by
 *     `form=`), each row's checkbox `inv[]` = `{id}:{version}` (the version THIS row shows); then a
 *     STANDARD LIST (listing.md): the state icon opens the invoice WINDOW (ADR-047), the PDF as a
 *     thumbnail, every searchable cell a `<label for>` of its magnifier. Konto / MWST show the first
 *     line, or «n Zeilen»; the state says «vollständig», what is missing, or «Doppel?»;
 *   - «Verbuchte Journale»: the posting protocols, newest first;
 *   - «Nicht eingelesen» (stage 2, `?view=intake`): the files of the intake folder the job `creditor-intake` refused,
 *     waiting in `failed/` with their reason — «Erneut versuchen» (back into the folder) and
 *     «Entfernen» as small `data-fetch-post` forms (the CSRF in the header) whose answer reloads
 *     this region. While any waits, the other tabs show the band «n Dateien nicht eingelesen».
 *
 * The column «Erkannt» says where an invoice's values came from (Text / KI / von Hand), its
 * warnings in the title — or «ausstehend» while the recognition waits for the job
 * `creditor-intake` (it never runs in a request, CREDITOR-RECOG-WEB-001).
 *
 * A FETCH REGION (sort / page / search reload only it; a save in a window opened from here
 * reloads it; the upload answer replaces it — `[data-creditor-journal]`). No JavaScript of its own.
 *
 * @var string $view
 * @var array{open: int, gross: \Z77\Shared\Money\Money, blocked: int, unpaid: int, paid: int} $totals
 * @var list<\Z77\Module\Creditor\Entities\CreditorInvoice> $invoices
 * @var array<int, list<\Z77\Module\Creditor\Entities\CreditorInvoiceLine>> $lines  by invoice id
 * @var array<int, array<string, mixed>> $pdfs  by document id (ReceiptPhoto)
 * @var list<\Z77\Module\Creditor\Entities\CreditorJournalPosting> $protocols
 * @var list<\Z77\Module\Creditor\Entities\CreditorIntake> $failedRows  tab «Nicht eingelesen»
 * @var callable|null $fileThere  CreditorIntake → whether its file is still in failed/
 * @var string|null $folderNote  the intake folder could not be created (German)
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var callable $fmt
 * @var string $actionBase
 */
use Z77\Module\Creditor\Ui\CreditorJournalListing;

$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::JOURNAL_BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$posted     = $view === CreditorJournalListing::VIEW_POSTED;
$intakeTab  = $view === CreditorJournalListing::VIEW_INTAKE;
$failedN    = (int) ($totals['failed'] ?? 0);
$failedRows = $failedRows ?? [];
$blockedTab = $view === CreditorJournalListing::VIEW_BLOCKED;
$region     = $definition->region();
$open       = (int) $totals['open'];
$blocked    = (int) $totals['blocked'];
$unpaid     = (int) ($totals['unpaid'] ?? 0);
$paid       = (int) ($totals['paid'] ?? 0);
$selectable = $view === CreditorJournalListing::VIEW_OPEN;
$titles     = [CreditorJournalListing::VIEW_OPEN => 'Im Kreditorenjournal', CreditorJournalListing::VIEW_BLOCKED => 'Gesperrt — mögliche Doppel', CreditorJournalListing::VIEW_POSTED => 'Verbuchte Journale', CreditorJournalListing::VIEW_INTAKE => 'Nicht eingelesen — Dateien aus dem Eingangsordner'];
?>
<div class="be-list__section" data-fetch-region="<?= e($region) ?>" data-creditor-journal>
    <div class="be-list__section-header">
        <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
        <h2 class="be-list__section-title"><?= e($titles[$view] ?? $titles[CreditorJournalListing::VIEW_OPEN]) ?></h2>
        <span class="be-list__section-badge" title="<?= $posted ? 'Verbuchte Journale' : ($intakeTab ? 'Dateien' : 'Rechnungen') ?>"><?= $paging->total ?></span>
    </div>

    <?php if ($failedN > 0 && !$intakeTab): ?>
    <div class="be-modal__alert be-modal__alert--error" role="status" data-intake-failed="<?= $failedN ?>">
        <strong><?= $failedN === 1 ? '1 Datei' : $failedN . ' Dateien' ?> nicht eingelesen</strong> —
        <a href="<?= e($listUrl . '?view=' . CreditorJournalListing::VIEW_INTAKE) ?>">Nicht eingelesen</a>: erneut versuchen oder entfernen.
    </div>
    <?php endif; ?>
    <?php if ($intakeTab && !empty($folderNote)): ?>
    <div class="be-modal__alert be-modal__alert--error" role="status" data-intake-folder><?= e($folderNote) ?></div>
    <?php endif; ?>

    <?php if (!$posted && !$intakeTab): ?>
    <?php if ($blocked > 0 && !$blockedTab): ?>
    <div class="be-modal__alert be-modal__alert--error" role="status" data-journal-blocked="<?= $blocked ?>">
        <strong><?= $blocked === 1 ? '1 Rechnung ist' : $blocked . ' Rechnungen sind' ?> gesperrt — mögliches Doppel einer anderen Rechnung.</strong>
        <a href="<?= e($listUrl . '?view=' . CreditorJournalListing::VIEW_BLOCKED) ?>">Im Tab «Gesperrt» entscheiden</a>: kein Doppel — übernehmen, oder verwerfen.
    </div>
    <?php endif; ?>
    <?php if ($open + $blocked > 0 && !$blockedTab): ?>
    <div class="be-list__bar" role="status" data-journal-pending="<?= $open ?>">
        <span><strong><?= $open ?> <?= $open === 1 ? 'Rechnung' : 'Rechnungen' ?> im Journal · noch nicht verbucht</strong> · <?= $unpaid ?> unbezahlt · <?= $paid ?> bezahlt · Total <?= e($fmt($totals['gross'])) ?></span>
        <small class="be-list__cell--muted"><?= $paid > 0 ? 'die bezahlten mit «Journal verbuchen …» buchen (Datum = bezahlt am)' : 'bezahlte ankreuzen und «Bezahlt am …» — gebucht wird mit dem Zahlungsdatum' ?></small>
    </div>
    <?php endif; ?>
    <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
    <?php if ($selectable && $invoices !== []): ?>
    <form id="creditor-paid" method="get" action="<?= e($actionBase . '/confirm-paid') ?>" data-window-form></form>
    <div class="z77-form-actions" data-creditor-selection>
        <button type="submit" form="creditor-paid" class="be-btn be-btn--confirm be-btn--sm">
            <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-check"/></svg>
            <span class="be-btn__label">Bezahlt am …</span>
        </button>
        <small class="be-list__cell--muted">die angekreuzten Rechnungen als bezahlt markieren — Datum und «Bezahlt mit» im nächsten Schritt</small>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="be-list__frame">
        <div class="be-list__table" style="<?= e($definition->style()) ?>">
            <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
            <?php if ($intakeTab): ?>
            <?php foreach ($failedRows as $row): ?>
            <?php $there = isset($fileThere) ? (bool) $fileThere($row) : true; ?>
            <div class="be-list__item" data-intake-row="<?= (int) $row->getId() ?>">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--mono"><?= e($row->getReceivedAt()->format('d.m.Y H:i')) ?></span>
                    <span class="be-list__cell"><?= e($row->getOriginalName()) ?><?php if ($row->getFailedName() !== $row->getOriginalName()): ?> <small class="be-list__cell--muted">(in failed: <?= e((string) $row->getFailedName()) ?>)</small><?php endif; ?></span>
                    <span class="be-list__cell"><?= e($row->getReason()) ?><?php if (!$there): ?> <small class="be-list__cell--muted">— die Datei fehlt in failed</small><?php endif; ?></span>
                    <span class="be-list__cell">
                        <?php if ($there): ?>
                        <form class="be-list__form" data-fetch-post="<?= e($actionBase . '/intake-retry') ?>" method="post" action="<?= e($actionBase . '/intake-retry') ?>">
                            <input type="hidden" name="id" value="<?= (int) $row->getId() ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                            <button type="submit" class="be-btn be-btn--ghost be-btn--sm" title="Zurück in den Eingangsordner — der nächste Lauf liest die Datei nochmals">Erneut versuchen</button>
                        </form>
                        <?php endif; ?>
                        <form class="be-list__form" data-fetch-post="<?= e($actionBase . '/intake-remove') ?>" method="post" action="<?= e($actionBase . '/intake-remove') ?>">
                            <input type="hidden" name="id" value="<?= (int) $row->getId() ?>">
                            <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                            <button type="submit" class="be-btn be-btn--danger be-btn--sm" title="Die Datei löschen und den Eintrag entfernen">Entfernen</button>
                        </form>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php elseif (!$posted): ?>
            <?php foreach ($invoices as $invoice): ?>
            <?php
            $id        = (int) $invoice->getId();
            $url       = $actionBase . '/edit?id=' . $id;
            $own       = $lines[$id] ?? [];
            $missing   = $invoice->missing($own);
            $complete  = $missing === [] && !$invoice->isBlocked();
            $title     = $invoice->isBlocked() ? 'Rechnung öffnen — gesperrt: ' . $invoice->getDuplicateReason()
                : ($complete ? 'Rechnung öffnen — vollständig' : 'Rechnung öffnen — es fehlt: ' . implode(', ', $missing));
            $pdf       = $pdfs[$invoice->getDocumentId()] ?? null;
            $creditor  = $invoice->getCreditor()?->getContact()?->displayName();
            $proposal  = $invoice->proposal()['name'] ?? '';
            $first     = $own[0] ?? null;
            $many      = count($own) > 1;
            $recog     = $invoice->recognition();
            $source    = $invoice->recognitionSource();
            $hints     = array_merge((array) ($recog['warnings'] ?? []), (array) ($recog['notes'] ?? []));
            $choose    = (array) ($recog['candidates'] ?? []);
            ?>
            <div class="be-list__item" data-creditor-invoice="<?= $id ?>">
                <div class="be-list__row">
                    <?php if ($selectable): ?>
                    <span class="be-list__cell"><label class="be-choice"><input type="checkbox" class="be-choice__input" name="inv[]" form="creditor-paid" value="<?= e($id . ':' . $invoice->getVersion()) ?>" aria-label="<?= e($invoice->label()) ?> auswählen"></label></span>
                    <?php endif; ?>
                    <a class="be-list__cell be-list__state be-list__state--<?= $complete ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($title) ?>" title="<?= e($title) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= $complete ? 'check' : 'edit' ?>"/></svg></a>
                    <span class="be-list__cell"><?php if ($pdf !== null && $pdf['thumbUrl'] !== null): ?><img class="be-list__thumb" src="<?= e($pdf['thumbUrl']) ?>" alt="" loading="lazy"><?php else: ?><span class="be-list__thumb" aria-hidden="true"></span><?php endif; ?></span>
                    <label class="be-list__cell" for="<?= e($definition->inputId('f_creditor')) ?>"><?php if ($creditor !== null): ?><?= e($creditor) ?><?php elseif ($choose !== []): ?><span class="be-list__cell--muted">Kreditor wählen (gemeinsame IBAN: <?= e(implode(', ', array_column($choose, 'name'))) ?>)</span><?php elseif ($proposal !== ''): ?><span class="be-list__cell--muted">Vorschlag: <?= e($proposal) ?></span><?php else: ?><span class="be-list__cell--muted">Kreditor wählen</span><?php endif; ?></label>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_number')) ?>"><?= e($invoice->getInvoiceNumber() ?? '—') ?></label>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_date')) ?>"><?= $invoice->getInvoiceDate() !== null ? e($invoice->getInvoiceDate()->format('d.m.Y')) : '<span class="be-list__cell--muted">—</span>' ?></label>
                    <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= $invoice->getDueDate() !== null ? e($invoice->getDueDate()->format('d.m.Y')) : '—' ?></span>
                    <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= $invoice->getAmount() !== null ? e($fmt($invoice->getAmount())) : '<span class="be-list__cell--muted">—</span>' ?></label>
                    <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= $many ? e(count($own) . ' Zeilen') : e($first?->getAccount() ?? '—') ?></span>
                    <span class="be-list__cell be-list__cell--mono" data-priority="3"><?php if ($many): ?>…<?php elseif ($first?->isWithoutVat()): ?><span class="be-list__cell--muted">ohne</span><?php else: ?><?= e($first?->getTaxCode() ?? '—') ?><?php endif; ?></span>
                    <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= $invoice->getPaidOn() !== null ? e($invoice->getPaidOn()->format('d.m.Y')) : '<span class="be-list__cell--muted">unbezahlt</span>' ?></span>
                    <?php if ($invoice->isRecognitionPending()): ?>
                    <span class="be-list__cell" data-priority="3" data-recognition="pending" title="Erkennung ausstehend — der Job «Kreditoren-Eingang» liest die Rechnung beim nächsten Lauf"><span class="be-list__cell--muted">ausstehend</span></span>
                    <?php else: ?>
                    <span class="be-list__cell" data-priority="3" data-recognition="<?= e($source) ?>"<?= $hints !== [] ? ' title="' . e(implode(' · ', $hints)) . '"' : '' ?>><?= $source === 'von Hand' ? '<span class="be-list__cell--muted">von Hand</span>' : e($source) ?><?= $hints !== [] ? ' <span class="badge badge--warning" aria-label="' . e(count($hints) . ' Hinweise') . '">!</span>' : '' ?></span>
                    <?php endif; ?>
                    <span class="be-list__cell"><?php if ($invoice->isBlocked()): ?><span class="badge badge--danger" title="<?= e($invoice->getDuplicateReason()) ?>">Doppel?</span><?php elseif ($complete): ?><span class="badge badge--success">vollständig</span><?php else: ?><span class="badge badge--warning" title="<?= e('es fehlt: ' . implode(', ', $missing)) ?>">zu prüfen</span><?php endif; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <?php foreach ($protocols as $protocol): ?>
            <div class="be-list__item" data-journal-posting="<?= (int) $protocol->getId() ?>">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($protocol->getPostedAt()->format('d.m.Y H:i')) ?></span>
                    <span class="be-list__cell"><?= e($protocol->getPostedBy()) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= (int) $protocol->getInvoiceCount() ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($protocol->getGrossTotal())) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($intakeTab && $failedRows === []): ?>
    <p class="be-list__empty">Keine Datei wartet — was der Job «Kreditoren-Eingang» nicht einlesen kann, erscheint hier mit dem Grund.</p>
    <?php elseif ($intakeTab): ?>
    <?php elseif (!$posted && $invoices === []): ?>
    <p class="be-list__empty"><?= $state->isActive() ? 'Keine Rechnung gefunden.' : ($blockedTab ? 'Keine gesperrte Rechnung — kein mögliches Doppel wartet auf deinen Entscheid.' : 'Das Kreditorenjournal ist leer — eine hochgeladene PDF-Rechnung erscheint hier.') ?></p>
    <?php elseif ($posted && $protocols === []): ?>
    <p class="be-list__empty">Noch kein Kreditorenjournal verbucht — bezahlte Rechnungen bucht «Journal verbuchen …».</p>

    <?php endif; ?>
    <?= $this->partial('partials/pager', [
        'paging'      => $paging,
        'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
        'unit'        => $posted ? 'Journale' : ($intakeTab ? 'Dateien' : 'Rechnungen'),
        'regionLinks' => true,
    ], 'Z77\Shared') ?>
</div>
