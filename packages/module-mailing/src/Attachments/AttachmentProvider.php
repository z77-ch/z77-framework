<?php

namespace Z77\Module\Mailing\Attachments;

use Z77\Shared\Mail\Attachment;

/**
 * Turns the id of an attachment REFERENCE into the file that goes out — the
 * seam through which a document module (debtor's invoice PDF, order's
 * confirmation) puts its files into an outbox mail without the outbox knowing
 * the module (owner 2026-10-22, step 3 of the mail plan; ADR-053).
 *
 * A mail stores `type:id` (`invoice-pdf:123`); at send time the job asks the
 * provider registered for `type` ({@see AttachmentProviders}) for `id`. The
 * bytes are produced THEN, not when the mail was queued: a queue of 500 mails
 * holds 500 short strings, and the attachment shows the document as it is at
 * sending.
 *
 * Registered by class name under the module config key `mailAttachments`
 * (type => class); instantiated without arguments, so a provider reaches its
 * services through DI like a dashboard contributor.
 *
 * Contract:
 *   - {@see accepts()} is asked when the mail is QUEUED — a cheap check (is
 *     `123` a number, does invoice 123 exist); `false` refuses the whole draft
 *     before anything is written;
 *   - {@see resolve()} is asked at SEND time and returns the file. A throw is a
 *     send failure of that mail: the job records the message as `last_error`
 *     and retries with the backoff like a transport error — so a PDF renderer
 *     that is down for a minute does not lose the mail.
 */
interface AttachmentProvider
{
    /** Whether `$id` names something this provider can deliver — asked at queue time. */
    public function accepts(string $id): bool;

    /**
     * The file for `$id`. The filename passes the kernel `Attachment`'s sanitiser,
     * so a provider may build it from data (`Rechnung 2026-0042.pdf`).
     *
     * @throws \Throwable anything — the job turns it into a retry of this mail
     */
    public function resolve(string $id): Attachment;
}
