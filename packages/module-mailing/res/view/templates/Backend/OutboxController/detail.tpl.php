<?php
/**
 * One outbox mail — as it goes (went) out. READ-ONLY; the corrections are the
 * row's ⋮. Fetched from the list's state icon or «Anzeigen» it is a WINDOW
 * (ADR-047): the root declares mask, entity and title.
 *
 * How the mail's own content is shown (Rule 7, ADR-053) — never as markup of
 * the backend page:
 *   - headers, addresses, subject, attachments, the error: text, escaped;
 *   - the text half: escaped inside `<pre>`;
 *   - the HTML half: an `<iframe sandbox srcdoc="…">`. The `srcdoc` attribute
 *     is escaped like any attribute, and the EMPTY `sandbox` gives the frame an
 *     opaque origin with no scripts, no forms, no popups and no navigation of
 *     the backend — a mail that carried a script could not run it, and the
 *     mail's styles cannot reach the backend's. `referrerpolicy` keeps the
 *     backend URL from a remote image's server.
 *
 * @var \Z77\Module\Mailing\Entities\OutboxMail $mail
 * @var string $text          the text half (stored, or derived from the HTML)
 * @var bool $textDerived     the text half is derived (the row stores none)
 * @var array<string, string> $labels
 * @var bool $window
 * @var string $windowWidth
 * @var string $actionBase
 */
$window  = !empty($window);
$id      = (int) $mail->getId();
$winAttr = $window
    ? ' data-window="outbox-mail" data-window-entity="outbox-mail:' . $id . '" data-window-title="' . e('E-Mail an ' . $mail->getRecipientEmail()) . '"'
        . (!empty($windowWidth) ? ' data-window-width="' . e($windowWidth) . '"' : '')
    : '';
$state   = $mail->getState();
$badges  = ['queued' => 'badge--info', 'sending' => 'badge--primary', 'sent' => 'badge--success', 'failed' => 'badge--danger', 'cancelled' => 'badge--muted'];
$at      = static fn(?\DateTimeImmutable $d): string => $d === null ? '—' : $d->format('d.m.Y H:i');
$rows    = [
    'An'           => $mail->recipientLabel(),
    'Antwort an'   => $mail->hasSender() ? $mail->senderLabel() : ($mail->getReplyTo() !== '' ? $mail->getReplyTo() : '— (keine)'),
    'Absender'     => $mail->hasSender()
        ? '«' . $mail->getSenderName() . '» mit der Adresse aus der E-Mail-Konfiguration der Installation'
        : 'aus der E-Mail-Konfiguration der Installation',
    'Ausgelöst von' => $mail->hasSender()
        ? $mail->getCreatedBy() . ' als ' . $mail->senderLabel() . ($mail->getSenderUserId() !== null ? ' (Benutzer-Id ' . $mail->getSenderUserId() . ')' : '')
        : $mail->getCreatedBy(),
    'Betreff'      => $mail->getSubject(),
    'Sprache'      => $mail->getLanguage() !== '' ? $mail->getLanguage() : '—',
    'Quelle'       => $mail->getSource() !== '' ? $mail->getSource() : '—',
    'Vorlage'      => $mail->getTemplate() !== '' ? $mail->getTemplate() : '—',
    'Sendung'      => $mail->getBatchId(),
    'Erstellt'     => $at($mail->getCreatedAt()) . ' von ' . $mail->getCreatedBy(),
    'Versuche'     => (string) $mail->getAttempts(),
    'Nächster Versuch' => $at($mail->getNextAttemptAt()),
    'Gesendet'     => $at($mail->getSentAt()),
];
?>
<div class="be-list"<?= $winAttr ?>>
    <div class="be-list__section">
        <div class="be-list__section-header">
            <h2 class="be-list__section-title"><?= e($mail->getSubject()) ?></h2>
            <span class="badge <?= $badges[$state] ?? 'badge--muted' ?>"><?= e($labels[$state] ?? $state) ?></span>
        </div>

        <?php if ($mail->getLastError() !== ''): ?>
        <div class="be-modal__alert be-modal__alert--error" role="status"><strong>Letzter Fehler:</strong> <?= e($mail->getLastError()) ?></div>
        <?php endif; ?>

        <div class="be-list__facts">
            <?php foreach ($rows as $label => $value): ?>
            <div><span class="be-list__cell--muted"><?= e($label) ?>:</span> <?= e($value) ?></div>
            <?php endforeach; ?>
            <div><span class="be-list__cell--muted">Anhänge:</span>
                <?php if ($mail->getAttachments() === []): ?>— (keine)<?php else: ?>
                <?php foreach ($mail->getAttachments() as $reference): ?><code><?= e($reference) ?></code> <?php endforeach; ?>
                <small class="be-list__cell--muted">— beim Versand erzeugt</small>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($mail->getHtmlBody() !== ''): ?>
        <div class="be-form__section">HTML-Ansicht</div>
        <iframe title="HTML-Ansicht der E-Mail" sandbox="" referrerpolicy="no-referrer" loading="lazy" style="display:block;width:100%;height:28rem;border:1px solid var(--be-line);border-radius:var(--radius-sm);background:#fff" srcdoc="<?= e($mail->getHtmlBody()) ?>"></iframe>
        <?php endif; ?>

        <div class="be-form__section">Text<?= $textDerived ? ' <small class="be-list__cell--muted">(aus dem HTML abgeleitet)</small>' : '' ?></div>
        <pre class="be-list__note" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= e($text) ?></pre>

        <?php if (!$window): ?>
        <p class="be-form__hint"><a class="be-btn be-btn--ghost be-btn--sm" href="<?= e($actionBase) ?>/list">Zurück zum Postausgang</a></p>
        <?php endif; ?>
    </div>
</div>
