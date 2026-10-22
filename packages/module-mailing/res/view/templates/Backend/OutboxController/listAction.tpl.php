<?php
/**
 * Service › Postausgang — the outbox (owner 2026-10-22). A STANDARD LIST
 * (listing.md): head and search form are the kernel's partials over
 * {@see \Z77\Module\Mailing\Ui\OutboxListing}, the rows are this template's;
 * no CSS and no JavaScript of its own:
 *
 *   - the BANDS first, inside the region (a reload after «Versand starten»
 *     updates them): why waiting mails do not move — sending not configured,
 *     the job not queued (with the green «Versand starten», the pending step
 *     made visible, backend-screen.md §4), the cron not running (heartbeat);
 *   - every row starts with its state icon, which opens the mail as a WINDOW
 *     (ADR-047, `data-window-open`; the href stays for a ctrl-click), then the
 *     ⋮ that opens the row's CONTEXT MENU (`data-menu-open`, MENU-001);
 *   - every searchable cell is a `<label for>` of its column's search field;
 *   - nothing of a mail's HTML is rendered here — subject, addresses and the
 *     error are text, escaped (Rule 7);
 *   - the whole list is a FETCH REGION: tabs, sort, page, search, retry and
 *     cancel reload only this part.
 *
 * @var list<\Z77\Module\Mailing\Entities\OutboxMail> $mails
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var array<string, int> $counts   state => rows
 * @var int $waiting                 queued + sending
 * @var array<string, string> $labels state => German label
 * @var array<string, string> $views  view => tab label
 * @var bool $mailReady   sending is configured (or nothing waits)
 * @var bool $jobQueued   the job has an open entry (or nothing waits)
 * @var array{at:string}|null $heartbeat
 * @var bool $cronStale
 * @var string $actionBase
 */
$actionBase = $actionBase ?? '/backend/service/outbox';
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
$view       = $state->extra('view');
$icons      = [
    'queued'    => ['icon-mail', ''],
    'sending'   => ['icon-zap', ' be-list__state--generated'],
    'sent'      => ['icon-check', ' be-list__state--editable'],
    'failed'    => ['icon-x', ' be-list__state--closed'],
    'cancelled' => ['icon-x', ''],
];
$badges     = ['queued' => 'badge--info', 'sending' => 'badge--primary', 'sent' => 'badge--success', 'failed' => 'badge--danger', 'cancelled' => 'badge--muted'];
$titles     = ['queued' => 'Wartende E-Mails', 'sent' => 'Gesendete E-Mails', 'failed' => 'Fehlgeschlagene E-Mails', 'all' => 'Alle E-Mails'];
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <?php if ($waiting > 0 && !$mailReady): ?>
    <div class="be-modal__alert be-modal__alert--error" role="status">
        <strong>E-Mail-Versand ist nicht eingerichtet.</strong>
        <?= e((string) $waiting) ?> E-Mail(s) warten. Absender und Versandweg stehen in <code>config/client/mail.inc.php</code> (Service › E-Mail) — bis dahin bleiben die E-Mails unverändert im Postausgang.
    </div>
    <?php endif; ?>
    <?php if ($waiting > 0 && $mailReady && !$jobQueued): ?>
    <div class="be-list__bar" role="status">
        <span><?= e((string) $waiting) ?> E-Mail(s) warten — der Versand ist nicht eingereiht.</span>
        <form data-fetch-post="<?= e($actionBase) ?>/start">
            <button type="submit" class="be-btn be-btn--confirm be-btn--sm"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-play"/></svg> Versand starten</button>
        </form>
    </div>
    <?php endif; ?>
    <?php if ($cronStale): ?>
    <div class="be-modal__alert be-modal__alert--error" role="status">
        <strong>Der Cron-Lauf ist <?= $heartbeat === null ? 'noch nie gelaufen' : 'seit ' . e(date('d.m.Y H:i', (int) strtotime($heartbeat['at']))) . ' nicht mehr gelaufen' ?>.</strong>
        Wartende E-Mails werden erst versendet, wenn <code>vendor/bin/z77-run</code> wieder jede Minute läuft (Service › Jobs).
    </div>
    <?php endif; ?>

    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
            <h2 class="be-list__section-title"><?= e($titles[$view] ?? 'E-Mails') ?></h2>
            <span class="be-list__section-badge" title="E-Mails"><?= $paging->total ?></span>
        </div>

        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>

        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($mails as $mail): ?>
                <?php
                $id      = (string) $mail->getId();
                $mState  = $mail->getState();
                $detail  = $actionBase . '/detail?id=' . $id;
                [$glyph, $tone] = $icons[$mState] ?? ['icon-mail', ''];
                ?>
                <div class="be-list__item" data-outbox-id="<?= e($id) ?>">
                    <div class="be-list__row">
                        <a class="be-list__cell be-list__state<?= $tone ?>" href="<?= e($detail) ?>" data-window-open="<?= e($detail) ?>" aria-label="E-Mail anzeigen" title="<?= e(ucfirst($labels[$mState] ?? $mState)) ?> — anzeigen"><svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#<?= $glyph ?>"/></svg></a>
                        <span class="be-list__cell"><button type="button" class="be-tree__menu" title="Aktionen" aria-label="Aktionen — E-Mail an <?= e($mail->getRecipientEmail()) ?>" aria-haspopup="menu" aria-expanded="false" data-menu-open="<?= e($actionBase) ?>/actions?id=<?= e($id) ?>">⋮</button></span>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_created')) ?>" data-field="created"><?= e($mail->getCreatedAt()->format('d.m.Y H:i')) ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_recipient')) ?>" data-field="recipient" title="<?= e($mail->recipientLabel()) ?>"><?= e($mail->getRecipientName() !== '' ? $mail->getRecipientName() : $mail->getRecipientEmail()) ?><?php if ($mail->getRecipientName() !== ''): ?> <small class="be-list__cell--muted"><?= e($mail->getRecipientEmail()) ?></small><?php endif; ?></label>
                        <label class="be-list__cell" for="<?= e($definition->inputId('f_subject')) ?>" data-field="subject"><?= e($mail->getSubject()) ?></label>
                        <label class="be-list__cell be-list__cell--mono" for="<?= e($definition->inputId('f_source')) ?>" data-priority="3" data-field="source"><?= e($mail->getSource()) ?></label>
                        <span class="be-list__cell" data-field="state"><span class="badge <?= $badges[$mState] ?? 'badge--muted' ?>"><?= e($labels[$mState] ?? $mState) ?></span></span>
                        <span class="be-list__cell be-list__cell--num" data-priority="2" data-field="attempts"><?= e((string) $mail->getAttempts()) ?></span>
                        <span class="be-list__cell be-list__cell--muted" data-priority="3" data-field="error" title="<?= e($mail->getLastError()) ?>"><?= e($mail->getLastError()) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($mails === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Keine E-Mail gefunden.' : match ($view) {
            'queued' => 'Keine E-Mail wartet auf den Versand.',
            'sent'   => 'Noch keine E-Mail versendet.',
            'failed' => 'Keine fehlgeschlagene E-Mail.',
            default  => 'Der Postausgang ist leer.',
        } ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'E-Mails',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
