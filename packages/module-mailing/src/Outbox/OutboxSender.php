<?php

namespace Z77\Module\Mailing\Outbox;

use Z77\Module\Mailing\Attachments\AttachmentProviders;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Shared\Mail\HtmlToText;
use Z77\Shared\Mail\Mailer;
use Z77\Shared\Mail\Message;

/**
 * Sends ONE outbox mail — the job's inner step, kept apart so the job reads as
 * the batch loop it is and a test can hand in a `Mailer` with a fake transport.
 *
 * Per mail: claim it (`queued → sending`, conditional — a mail cancelled a
 * moment ago is skipped), build the kernel `Message` (recipient, the sender
 * identity's display name on From and its address as Reply-To — else the row's Reply-To,
 * subject, the HTML and its text half — derived with `HtmlToText` when the
 * row has none), resolve the attachment references through the providers,
 * send through the `Mailer` (transport and SENDER from the mail config — the
 * installation's identity, as for the form mails), record the outcome:
 *
 *   - delivered to the transport → `sent`;
 *   - the row can never be sent (an address or header `Message` refuses — only
 *     possible for a row written around the service) → `failed` at once;
 *   - anything else (transport refused, connection, an attachment provider
 *     threw) → one attempt more; `queued` again with `next_attempt_at` from
 *     the backoff (`retryMinutes`), or `failed` once `maxAttempts` is reached.
 */
final class OutboxSender
{
    public const SENT    = 'sent';
    public const RETRY   = 'retry';
    public const FAILED  = 'failed';
    public const SKIPPED = 'skipped';

    public function __construct(
        private OutboxMailRepository $mails,
        private AttachmentProviders $providers,
        private Mailer $mailer,
        private OutboxSettings $settings,
    ) {
    }

    /** Whether the mail config allows sending at all (`config/client/mail.inc.php` present, `enabled`). */
    public function isConfigured(): bool
    {
        return $this->mailer->isConfigured();
    }

    /** @return self::SENT|self::RETRY|self::FAILED|self::SKIPPED */
    public function send(int $id, \DateTimeImmutable $now): string
    {
        if (!$this->mails->claim($id, $now)) {
            return self::SKIPPED;   // cancelled, or no longer due, since the id was read
        }
        $mail = $this->mails->fresh($id);
        if ($mail === null) {
            return self::SKIPPED;
        }

        try {
            $message = $this->message($mail);
        } catch (\InvalidArgumentException $e) {
            $this->mails->markFailed($id, 'Nicht versendbar: ' . $e->getMessage());

            return self::FAILED;
        }

        try {
            foreach ($mail->getAttachments() as $reference) {
                $message->attach($this->providers->resolve($reference));
            }
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $error    = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            $failures = $mail->getAttempts() + 1;
            if ($failures >= $this->settings->maxAttempts) {
                $this->mails->markFailed($id, $error);

                return self::FAILED;
            }
            $this->mails->markRetry($id, $error, $now->modify('+' . $this->settings->retryDelaySeconds($failures) . ' seconds'));

            return self::RETRY;
        }

        $this->mails->markSent($id, new \DateTimeImmutable());

        return self::SENT;
    }

    /** The kernel `Message` of a row — throws InvalidArgumentException for what can never be sent. */
    private function message(OutboxMail $mail): Message
    {
        $message = (new Message())
            ->to($mail->getRecipientEmail())
            ->subject($mail->getSubject());
        if ($mail->hasSender()) {
            // The person's identity (owner 2026-10-23): his name on the From — the ADDRESS stays
            // the account's, filled in by the Mailer — and «name <email>» as Reply-To.
            $message->fromName($mail->getSenderName())->replyTo($mail->getSenderEmail(), $mail->getSenderName());
        } elseif ($mail->getReplyTo() !== '') {
            $message->replyTo($mail->getReplyTo());
        }
        $html = $mail->getHtmlBody();
        $text = $mail->getTextBody() !== '' ? $mail->getTextBody() : ($html !== '' ? (new HtmlToText())->convert($html) : '');
        $message->text($text);
        if ($html !== '') {
            $message->html($html);
        }

        return $message;
    }
}
