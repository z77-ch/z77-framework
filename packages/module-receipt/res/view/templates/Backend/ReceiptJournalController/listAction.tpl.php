<?php
/**
 * The «Belegjournal» (board «Belegjournal», owner-approved 2026-11-12) — one tab at a time (toolbar):
 *
 *   - «Im Journal»: the pending step at the top (backend-screen.md §4): count, total and the oldest
 *     date — or, red, the receipts that cannot be posted as they are (incomplete, no fiscal year, a
 *     closed period), each a link to its window; then a STANDARD LIST (listing.md) of the captured
 *     receipts — the state icon opens the receipt WINDOW (ADR-047), the photo is a thumbnail, every
 *     searchable cell a `<label for>` of its magnifier; under the list the totals per VAT code
 *     (`preview()` — what the posting books; «fehlt» = the blocked receipts);
 *   - «Verbuchte Journale»: the posting protocols, newest first.
 *
 * The whole list is a FETCH REGION (sort / page / search reload only it; a save in a window opened
 * from here reloads it — the row, the band and the totals together). No JavaScript of its own.
 *
 * @var string $view
 * @var array{count: int, gross: \Z77\Shared\Money\Money, oldest: ?\DateTimeImmutable} $totals
 * @var array<string, mixed>|null $preview  ReceiptJournalPostingService::preview() up to today
 * @var list<array{id: int, label: string, reason: string}> $blockers
 * @var array<string, string> $taxLabels
 * @var list<\Z77\Module\Receipt\Entities\Receipt> $receipts
 * @var array<int, array<string, mixed>> $photos  by document id (ReceiptPhoto)
 * @var array<string, string> $templates  code → label
 * @var array<string, string> $methods    code → label
 * @var list<\Z77\Module\Receipt\Entities\ReceiptJournalPosting> $protocols
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var callable $fmt
 * @var string $actionBase
 */
use Z77\Module\Receipt\Ui\ReceiptJournalListing;
use Z77\Module\Vat\Entities\TaxRate;

$actionBase = $actionBase ?? \Z77\Module\Receipt\Ui\ReceiptLayout::JOURNAL_BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$posted     = $view === ReceiptJournalListing::VIEW_POSTED;
$region     = $definition->region();
$count      = (int) $totals['count'];
?>
<div class="be-list" data-fetch-region="<?= e($region) ?>" data-receipt-journal>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\Shared') ?>
            <h2 class="be-list__section-title"><?= $posted ? 'Verbuchte Journale' : 'Im Belegjournal' ?></h2>
            <span class="be-list__section-badge" title="<?= $posted ? 'Verbuchte Journale' : 'Belege' ?>"><?= $paging->total ?></span>
        </div>

        <?php if (!$posted): ?>
        <?php if ($blockers !== []): ?>
        <div class="be-modal__alert be-modal__alert--error" role="status" data-journal-blocked="<?= count($blockers) ?>">
            <strong><?= count($blockers) === 1 ? '1 Beleg kann' : count($blockers) . ' Belege können' ?> so nicht verbucht werden — sie sperren jedes «Journal verbuchen», dessen Stichtag sie umfasst:</strong>
            <?php foreach ($blockers as $i => $blocker): $url = $actionBase . '/edit?id=' . (int) $blocker['id']; ?><?= $i > 0 ? ' · ' : ' ' ?><a href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>"><?= e($blocker['label']) ?></a> <small>(<?= e($blocker['reason']) ?>)</small><?php endforeach; ?>
        </div>
        <?php elseif ($count > 0): ?>
        <div class="be-list__bar" role="status" data-journal-pending="<?= $count ?>">
            <span><strong><?= $count ?> <?= $count === 1 ? 'Beleg' : 'Belege' ?> im Journal · noch nicht verbucht</strong> · Total <?= e($fmt($totals['gross'])) ?><?= $totals['oldest'] !== null ? ' · ältester vom ' . e($totals['oldest']->format('d.m.Y')) : '' ?></span>
            <small class="be-list__cell--muted">«Journal verbuchen …» übernimmt alle bis zum Stichtag in die Buchhaltung</small>
        </div>
        <?php endif; ?>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
        <?php endif; ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php if (!$posted): ?>
                <?php foreach ($receipts as $receipt): ?>
                <?php
                $id       = (int) $receipt->getId();
                $url      = $actionBase . '/edit?id=' . $id;
                $complete = $receipt->isComplete();
                $missing  = $receipt->missing();
                $accounts = array_intersect($missing, ['Soll', 'Haben', 'MWST']) !== [];
                $title    = $complete ? 'Beleg öffnen — vollständig' : 'Beleg öffnen — es fehlt: ' . implode(', ', $missing);
                $photo    = $photos[$receipt->getDocumentId()] ?? null;
                $template = $receipt->getTemplateCode();
                $method   = $receipt->getPaymentMethodCode();
                ?>
                <div class="be-list__item" data-receipt-id="<?= $id ?>">
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state be-list__state--<?= $complete ? 'editable' : 'closed' ?>" href="<?= e($url) ?>" data-window-open="<?= e($url) ?>" data-origin="region:<?= e($region) ?>" aria-label="<?= e($title) ?>" title="<?= e($title) ?>"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-<?= $complete ? 'check' : 'edit' ?>"/></svg></a>
                        <span class="be-list__cell"><?php if ($photo !== null && $photo['thumbUrl'] !== null): ?><img class="be-list__thumb" src="<?= e($photo['thumbUrl']) ?>" alt="" loading="lazy"><?php else: ?><span class="be-list__thumb" aria-hidden="true"></span><?php endif; ?></span>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_date')) ?>"><?= e($receipt->getReceiptDate()->format('d.m.Y')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_text')) ?>"><?= $receipt->getText() !== '' ? e($receipt->getText()) : '<span class="be-list__cell--muted">ohne Text</span>' ?></label>
                        <label class="be-list__cell be-list__cell--num" for="<?= e($definition->inputId('f_amount')) ?>"><?= e($fmt($receipt->getAmount())) ?></label>
                        <span class="be-list__cell be-list__cell--mono" data-priority="2"><?= e($receipt->getDebitAccount() ?? '—') ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= e($receipt->getCreditAccount() ?? '—') ?></span>
                        <span class="be-list__cell be-list__cell--mono" data-priority="3"><?= $receipt->isWithoutVat() ? '<span class="be-list__cell--muted">ohne</span>' : e($receipt->getTaxCode() ?? '—') ?></span>
                        <span class="be-list__cell" data-priority="3"><?= $template !== null ? e($templates[$template] ?? $template) : '<span class="be-list__cell--muted">ohne Vorlage</span>' ?></span>
                        <span class="be-list__cell" data-priority="2"><?= $method !== null ? e($methods[$method] ?? $method) : '<span class="be-list__cell--muted">—</span>' ?></span>
                        <label class="be-list__cell be-list__cell--muted" for="<?= e($definition->inputId('f_by')) ?>" data-priority="2"><?= e($receipt->capturedByLabel()) ?></label>
                        <span class="be-list__cell"><?php if ($complete): ?><span class="badge badge--success">vollständig</span><?php else: ?><span class="badge badge--warning" title="<?= e('es fehlt: ' . implode(', ', $missing)) ?>"><?= $accounts ? 'Konten fehlen' : 'Text fehlt' ?></span><?php endif; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <?php foreach ($protocols as $protocol): ?>
                <div class="be-list__item" data-journal-posting="<?= (int) $protocol->getId() ?>">
                    <div class="be-list__row">
                        <span class="be-list__cell"><?= e($protocol->getPostedAt()->format('d.m.Y H:i')) ?></span>
                        <span class="be-list__cell"><?= e($protocol->getPostedBy()) ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= (int) $protocol->getReceiptCount() ?></span>
                        <span class="be-list__cell be-list__cell--num"><?= e($fmt($protocol->getGrossTotal())) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$posted && $receipts === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Beleg gefunden.' : 'Das Belegjournal ist leer — ein Beleg, der mit dem Handy erfasst wird, erscheint hier, bis das Journal verbucht ist.' ?></p>
        <?php elseif ($posted && $protocols === []): ?>
        <p class="be-list__empty">Noch kein Belegjournal verbucht.</p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => $posted ? 'Journale' : 'Belege',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>

    <?php if (!$posted && $preview !== null): ?>
    <div class="be-list__section" data-journal-totals>
        <div class="be-list__section-header">
            <h3 class="be-list__section-title">Total des Journals <small class="be-list__cell--muted">· <?= $count ?> <?= $count === 1 ? 'Beleg' : 'Belege' ?>, MWST aus dem Betrag herausgerechnet</small></h3>
        </div>
        <div class="be-list__table" style="--be-list-cols: 6rem minmax(10rem, 2fr) 6rem 9rem 9rem">
            <div class="be-list__head">
                <span class="be-list__col">MWST-Code</span>
                <span class="be-list__col">Bezeichnung</span>
                <span class="be-list__col be-list__col--num">Satz</span>
                <span class="be-list__col be-list__col--num">Basis</span>
                <span class="be-list__col be-list__col--num">MWST</span>
            </div>
            <?php $tax = \Z77\Shared\Money\Money::zero($totals['gross']->currency); foreach ($preview['vat'] as $vat): $tax = $tax->add($vat['tax']); ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono"><?= e($vat['code']) ?></span>
                <span class="be-list__cell"><?= e($taxLabels[$vat['code']] ?? '') ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e(TaxRate::formatPercent($vat['rate'])) ?> %</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($vat['base'])) ?></span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($vat['tax'])) ?></span>
            </div></div>
            <?php endforeach; ?>
            <?php if (!$preview['withoutVat']->isZero()): ?>
            <div class="be-list__item"><div class="be-list__row">
                <span class="be-list__cell be-list__cell--mono">ohne</span>
                <span class="be-list__cell">ohne MWST</span>
                <span class="be-list__cell be-list__cell--num">—</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($preview['withoutVat'])) ?></span>
                <span class="be-list__cell be-list__cell--num">—</span>
            </div></div>
            <?php endif; ?>
            <?php if ($blockers !== []): ?>
            <div class="be-list__item" data-journal-missing><div class="be-list__row">
                <span class="be-list__cell be-list__cell--muted">fehlt</span>
                <span class="be-list__cell be-list__cell--muted"><?= count($blockers) === 1 ? '1 Beleg' : count($blockers) . ' Belege' ?> noch nicht verbuchbar</span>
                <span class="be-list__cell be-list__cell--num">—</span>
                <span class="be-list__cell be-list__cell--num"><?= e($fmt($preview['blockedGross'])) ?></span>
                <span class="be-list__cell be-list__cell--num">—</span>
            </div></div>
            <?php endif; ?>
            <div class="be-list__item" data-journal-grand-total><div class="be-list__row">
                <strong class="be-list__cell">Total</strong>
                <span class="be-list__cell"></span>
                <span class="be-list__cell"></span>
                <span class="be-list__cell be-list__cell--num">MWST <?= e($fmt($tax)) ?></span>
                <strong class="be-list__cell be-list__cell--num"><?= e($fmt($totals['gross'])) ?></strong>
            </div></div>
        </div>
    </div>
    <?php endif; ?>
</div>
