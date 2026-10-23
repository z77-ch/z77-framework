<?php
/**
 * «Alle per E-Mail senden …» — the confirmation of the booking print's result list (step 3 of
 * the mail plan, owner 2026-10-22): how many documents get a mail (to the billing e-mail ON THE
 * DOCUMENT, with the resolved subject of their kind and language), which are SKIPPED and why —
 * no address on the document is a skip, not a failure — and which went out by e-mail before
 * (sending again is allowed, the line says it). One button posts the ids (`#[Csrf]`) to `mail`:
 * one outbox mail per document into ONE batch; the job sends them in the background.
 *
 * Since 2026-10-23 (owner) a document whose mail waits or went out is COVERED: listed as
 * «Bereits versendet oder im Postausgang», not sent again — the POST applies the same rule.
 * With `$resend` («Erneut senden …» of one row) the page is about that document alone and
 * posts `resend` — the deliberate second mail.
 *
 * A PAGE like the booking print's own confirmation (DEBTOR-WIN-002); the action row first
 * (ADR-049). Without JavaScript a plain form.
 *
 * @var list<array{document: \Z77\Module\Debtor\Entities\Invoice, mail: array{to: string, subject: string, language: string}}> $send
 * @var list<array{document: \Z77\Module\Debtor\Entities\Invoice, reason: string}> $skip
 * @var list<\Z77\Module\Debtor\Entities\Invoice> $done  covered — a mail waits or went out
 * @var int    $resend     the one document «Erneut senden …» is for, 0 = the whole run
 * @var string $ids
 * @var string $back
 * @var bool   $mailable   module-mailing installed
 * @var bool   $mailReady  the mail config is complete (else the mails wait in the outbox)
 * @var array<int, list<array<string, mixed>>> $deliveries
 * @var array{identities: array<int, string>, selected: int, missing: string, editUrl: string, field: string} $senders
 *      «Senden als» (owner 2026-10-23, `SenderIdentities::viewModel()`); no identity → no button
 * @var string $csrfToken
 * @var string $actionBase
 */
use Z77\Module\Debtor\Mail\InvoiceMails;

$actionBase = $actionBase ?? '/backend/finance/invoice';
$query      = http_build_query(['ids' => $ids] + ($back !== '' ? ['back' => $back] : []));
$n          = count($send);
$senders    = $senders ?? ['identities' => []];
$canSend    = $n > 0 && $senders['identities'] !== [];
$done       = $done ?? [];
$resend     = $resend ?? 0;
?>
<div class="be-list">
    <form method="post" action="<?= e($actionBase) ?>/mail" class="be-list__section">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <input type="hidden" name="ids" value="<?= e($ids) ?>">
        <?php if ($back !== ''): ?><input type="hidden" name="back" value="<?= e($back) ?>"><?php endif; ?>
        <?php if ($resend > 0): ?><input type="hidden" name="resend" value="<?= $resend ?>"><?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $canSend ? ($resend > 0 ? 'Erneut senden' : ($n === 1 ? '1 E-Mail senden' : $n . ' E-Mails senden')) : '',
            'cancel'     => 'Zurück zur Liste',
            'cancelHref' => $actionBase . '/booking-result?' . $query,
        ], 'Z77\\Shared') ?>
        <div class="be-list__section-header">
            <?php if ($resend > 0): ?>
            <h2 class="be-list__section-title">Erneut per E-Mail senden <small class="be-list__cell--muted">· ein Dokument</small></h2>
            <?php else: ?>
            <h2 class="be-list__section-title">Per E-Mail senden <small class="be-list__cell--muted">· <?= $n ?> von <?= $n + count($skip) + count($done) ?> Dokumenten</small></h2>
            <?php endif; ?>
        </div>
        <?php if (!$mailable): ?>
        <div class="be-modal__alert be-modal__alert--error">Das Modul «Mailing» (Postausgang) ist nicht installiert — Belege können nicht per E-Mail gesendet werden.</div>
        <?php elseif (!$mailReady): ?>
        <div class="be-modal__alert">Der E-Mail-Versand ist nicht eingerichtet (Service › E-Mail, Absender und Versandart) — die E-Mails warten im Postausgang, bis er es ist.</div>
        <?php endif; ?>
        <?php if ($send !== []): ?>
        <?= $this->partial('partials/senderIdentityField', $senders, 'Z77\\Shared') ?>
        <?php endif; ?>
        <?php if ($send !== []): ?>
        <div class="be-list__table" style="--be-list-cols: 9.5rem minmax(9rem, 1.4fr) minmax(12rem, 2fr) 3rem">
            <?php foreach ($send as $item): ?>
            <?php $document = $item['document']; $before = $deliveries[(int) $document->getId()] ?? []; ?>
            <div class="be-list__item" data-mail-to="<?= e($item['mail']['to']) ?>">
                <div class="be-list__row">
                    <span class="be-list__cell"><?= e($document->documentName()) ?></span>
                    <span class="be-list__cell be-list__cell--wrap"><?= e($item['mail']['to']) ?></span>
                    <span class="be-list__cell be-list__cell--wrap">«<?= e($item['mail']['subject']) ?>»<?php if ($before !== []): ?><br><small class="be-list__cell--muted">bereits: <?= e(InvoiceMails::deliveryLine($before[count($before) - 1])) ?></small><?php endif; ?></span>
                    <span class="be-list__cell be-list__cell--muted"><?= e($item['mail']['language']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($done !== []): ?>
        <div class="be-modal__alert" data-mail-done>Bereits versendet oder im Postausgang — nicht nochmals: <?= e(implode(', ', array_map(static fn($d) => $d->documentName(), $done))) ?>.<?= $n === 0 ? ' Alle Dokumente sind erledigt.' : '' ?> Einen Beleg nochmals senden: «Erneut senden …» in seiner Zeile der Liste.</div>
        <?php endif; ?>
        <?php if ($skip !== []): ?>
        <div class="be-modal__alert" data-mail-skipped>Übersprungen (keine E-Mail): <?= e(implode(', ', array_map(static fn(array $s) => $s['document']->documentName() . ($s['reason'] !== '' ? ' — ' . $s['reason'] : ''), $skip))) ?>. Die Adresse steht auf dem Beleg (Rechnungsadresse); ein verbuchter Beleg ändert sie nicht mehr.</div>
        <?php endif; ?>
        <p class="be-form__hint">Jeder Beleg geht als eigene E-Mail mit seinem PDF im Anhang an die E-Mail-Adresse seiner Rechnungsadresse, in der Sprache des Belegs. Betreff und Text: Service › E-Mail › Beleg-Mails; Absender ist das eingerichtete Mail-Konto, mit deinem Anzeigenamen («Senden als»); Antworten gehen an die gewählte Adresse. Der Versand läuft im Hintergrund, in Paketen (Service › Postausgang).</p>
    </form>
</div>
