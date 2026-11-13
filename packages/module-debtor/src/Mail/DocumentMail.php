<?php

namespace Z77\Module\Debtor\Mail;

/**
 * One business document as an outbox mail, before it is composed (owner 2026-11-13: offer, order
 * confirmation, delivery note and dunning notice by e-mail «the way invoices are sent») — what a
 * sending module knows about its document, handed to {@see DocumentMailer}:
 *
 *   - `kind` — the document mail kind (`documentMails`, Service › E-Mail › Beleg-Mails): subject
 *     and text come from there, never from the sending code;
 *   - `to` — the recipient as it stands on the document ('' or an invalid address → the document
 *     is skipped, {@see DocumentMailer::addressProblem()});
 *   - `values` — the kind's placeholders WITHOUT `{anzeigename}` (the mailer fills it with the
 *     chosen sender identity);
 *   - `attachment` — the outbox reference `type:id` whose provider renders the PDF at SEND time
 *     (never bytes, never a path);
 *   - `source` — `prefix:<id>`, how the outbox finds what went out for the document.
 */
final class DocumentMail
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $to,
        public readonly string $recipientName,
        public readonly string $language,
        public readonly array $values,
        public readonly string $attachment,
        public readonly string $source,
    ) {
    }
}
