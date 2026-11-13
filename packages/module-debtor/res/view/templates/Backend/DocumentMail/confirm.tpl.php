<?php
/**
 * ONE business document by e-mail — the shared confirmation (Rule 9; owner 2026-11-13: offer,
 * order confirmation, delivery note, dunning notice «the way invoices are sent»). The invoice's
 * «Erneut senden …» page (`InvoiceController/confirmMail`) for a single document, without the run:
 * the recipient (the e-mail ON the document), the resolved subject and language, «Senden als»
 * (`partials/senderIdentityField` — none: the hint and NO button), what went out before (sending
 * again is allowed — the button then reads «Erneut senden»). No address → said plainly
 * («keine E-Mail-Adresse»), no button. One form posts (`#[Csrf]`) to `$action`; the caller's
 * action queues ONE outbox mail through `Mail\DocumentMailer` — the PDF is rendered at send time.
 *
 * Used by module-order (in the order window — `$window`, the order's record) and by the dunning
 * screen (a page). Action row first (ADR-049).
 *
 * @var string $documentName  «Offerte 12», «Mahnung zu Rechnung 26100012»
 * @var array{to: string, subject: string, language: string}|null $mail  null = cannot be mailed
 * @var string|null $reason   why not («keine E-Mail-Adresse», …), null when it can
 * @var string $addressNote   where the address comes from (the document's billing address …)
 * @var list<array<string, mixed>> $before  the outbox rows of this document, oldest first
 * @var array{identities: array<int, string>, selected: int, missing: string, editUrl: string, field: string} $senders
 * @var bool   $mailable   module-mailing installed
 * @var bool   $mailReady  the mail config is complete (else the mail waits in the outbox)
 * @var string $action     the POST target
 * @var array<string, string> $hidden  further hidden fields (the document's id …)
 * @var string $cancelHref
 * @var bool   $window     loaded into a window (`data-window-*` below)
 * @var string $windowMask
 * @var string $windowEntity  `sales-order:12`, '' for none
 * @var string $origin
 * @var string $csrfToken
 */
use Z77\Module\Debtor\Mail\DocumentMailer;

$window   = !empty($window);
$senders  = $senders ?? ['identities' => []];
$before   = $before ?? [];
$again    = DocumentMailer::isCovered($before);
$canSend  = !empty($mailable) && $mail !== null && $senders['identities'] !== [];
$title    = $documentName . ' · ' . ($again ? 'erneut per E-Mail senden' : 'per E-Mail senden');
$winAttr  = $window
    ? ' data-window="' . e($windowMask ?? 'document-mail') . '"' . (($windowEntity ?? '') !== '' ? ' data-window-entity="' . e($windowEntity) . '"' : '')
        . ' data-window-title="' . e($title) . '" data-window-width="44rem"'
    : '';
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($action) ?>" class="be-list__section" data-document-mail>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?php foreach ($hidden ?? [] as $name => $value): ?>
        <input type="hidden" name="<?= e((string) $name) ?>" value="<?= e((string) $value) ?>">
        <?php endforeach; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $canSend ? ($again ? 'Erneut senden' : 'E-Mail senden') : '',
            'cancel'     => 'Zurück',
            'cancelHref' => $cancelHref,
        ], 'Z77\\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>
        <?php if (empty($mailable)): ?>
        <div class="be-modal__alert be-modal__alert--error">Das Modul «Mailing» (Postausgang) ist nicht installiert — Belege können nicht per E-Mail gesendet werden.</div>
        <?php elseif ($mail === null): ?>
        <div class="be-modal__alert be-modal__alert--error" data-mail-skipped><?= e($documentName) ?>: <?= e((string) $reason) ?> — keine E-Mail möglich. <?= e($addressNote ?? '') ?></div>
        <?php elseif (empty($mailReady)): ?>
        <div class="be-modal__alert">Der E-Mail-Versand ist nicht eingerichtet (Service › E-Mail, Absender und Versandart) — die E-Mail wartet im Postausgang, bis er es ist.</div>
        <?php endif; ?>
        <?php if (!empty($mailable) && $mail !== null): ?>
        <?= $this->partial('partials/senderIdentityField', $senders, 'Z77\\Shared') ?>
        <div class="be-list__table" style="--be-list-cols: minmax(9rem, 1.2fr) minmax(12rem, 2fr) 3rem">
            <div class="be-list__item" data-mail-to="<?= e($mail['to']) ?>">
                <div class="be-list__row">
                    <span class="be-list__cell be-list__cell--wrap"><?= e($mail['to']) ?></span>
                    <span class="be-list__cell be-list__cell--wrap">«<?= e($mail['subject']) ?>»</span>
                    <span class="be-list__cell be-list__cell--muted"><?= e($mail['language']) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($before !== []): ?>
        <div class="be-modal__alert" data-mail-before>Bereits: <?= e(DocumentMailer::deliveryLine($before[count($before) - 1])) ?><?= count($before) > 1 ? ' (' . count($before) . ' E-Mails)' : '' ?>.</div>
        <?php endif; ?>
        <p class="be-form__hint">Der Beleg geht als E-Mail mit seinem PDF im Anhang — erstellt beim Versand, so wie der Beleg dann dasteht. Betreff und Text: Service › E-Mail › Beleg-Mails; Absender ist das eingerichtete Mail-Konto, mit deinem Anzeigenamen («Senden als»); Antworten gehen an die gewählte Adresse. Der Versand läuft im Hintergrund (Service › Postausgang).</p>
    </form>
</div>
