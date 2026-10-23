<?php

namespace Z77\Module\Mailing\Entities;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Z77\Shared\Attributes\Entity;

/**
 * One e-mail in the outbox (Service › Postausgang, owner 2026-10-22: «wir
 * brauchen einen Job, der den E-Mail-Versand überwacht»). Written ONCE by
 * {@see \Z77\Module\Mailing\Outbox\OutboxService::queueBatch()} — the content
 * never changes after that — and sent by the `mail-outbox` job in throttled
 * batches ({@see \Z77\Module\Mailing\Jobs\MailOutboxJob}).
 *
 * What a row carries:
 *
 *   - the RECIPIENT (one address per row — a mailing to 500 contacts is 500
 *     rows of one batch) and an optional display name kept for the screen and
 *     the newsletter (the kernel `Message` takes bare addresses only);
 *   - the CONTENT as it will go out: the subject, the HTML body (MEDIUMTEXT —
 *     a template is rendered at queue time, so «Anzeigen» shows exactly what
 *     is sent; `template` keeps the reference for traceability), an optional
 *     text body (derived with `HtmlToText` at send time when empty), an
 *     optional Reply-To and the language it was written in;
 *   - the ATTACHMENTS as REFERENCES `type:id` (`invoice-pdf:123`), resolved at
 *     send time by the provider a module registered for the type — the outbox
 *     never stores a PDF and knows no document module
 *     ({@see \Z77\Module\Mailing\Attachments\AttachmentProviders});
 *   - WHO triggered it (owner 2026-10-23): the backend user's id and a SNAPSHOT
 *     of the sender identity he sent as (`sender_name`, `sender_email`) — the
 *     From's display name and the Reply-To at send time, and «Ausgelöst von» on
 *     the screen; all empty for a mail without a person (the account alone);
 *   - WHERE it came from: `source` (opaque, `invoice:123`, `newsletter:5`) and
 *     `batchId` (one «send» action = one batch) — for grouping on the screen
 *     and for the later document and newsletter steps;
 *   - the DELIVERY state: `queued` → `sending` → `sent`, or back to `queued`
 *     with `nextAttemptAt` after a failure, `failed` after the last attempt,
 *     `cancelled` by the operator. Every state change is a CONDITIONAL update
 *     in {@see \Z77\Module\Mailing\Repositories\OutboxMailRepository} (the row's
 *     state is the lock): a cancel and a send racing for the same row cannot
 *     both win.
 *
 * No setters for the content: a mail in the outbox is a record of what was
 * asked for. Table `mail_outbox`; the mapping IS the table definition, the
 * module's migration creates it identically (`z77-db diff` stays quiet).
 */
#[Entity('doctrine')]
#[ORM\Entity, ORM\Table(name: 'mail_outbox')]
#[ORM\Index(name: 'idx_mail_outbox_state_due', columns: ['state', 'next_attempt_at'])]
#[ORM\Index(name: 'idx_mail_outbox_batch', columns: ['batch_id'])]
#[ORM\Index(name: 'idx_mail_outbox_source', columns: ['source'])]
class OutboxMail
{
    public const STATE_QUEUED    = 'queued';
    public const STATE_SENDING   = 'sending';
    public const STATE_SENT      = 'sent';
    public const STATE_FAILED    = 'failed';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [self::STATE_QUEUED, self::STATE_SENDING, self::STATE_SENT, self::STATE_FAILED, self::STATE_CANCELLED];

    /** RFC 5321: a forward path is at most 256 octets including the brackets — 254 for the address. */
    public const EMAIL_LENGTH    = 254;
    public const NAME_LENGTH     = 190;
    public const SUBJECT_LENGTH  = 255;
    public const ACTOR_LENGTH    = 80;
    public const TEMPLATE_LENGTH = 190;
    public const LANGUAGE_LENGTH = 5;
    public const SOURCE_LENGTH   = 128;
    public const BATCH_LENGTH    = 32;
    public const ERROR_LENGTH    = 500;

    /** MEDIUMTEXT on MySQL / MariaDB (16 MiB) — a rendered mail with inline styles fits with room. */
    public const HTML_LENGTH = 16777215;

    /** TEXT (64 KiB) — the plain-text half is far shorter than the HTML. */
    public const TEXT_LENGTH = 65535;

    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'created_by', length: self::ACTOR_LENGTH)]
    private string $createdBy;

    #[ORM\Column(name: 'recipient_email', length: self::EMAIL_LENGTH)]
    private string $recipientEmail;

    #[ORM\Column(name: 'recipient_name', length: self::NAME_LENGTH)]
    private string $recipientName;

    #[ORM\Column(name: 'reply_to', length: self::EMAIL_LENGTH)]
    private string $replyTo;

    /** The backend user who triggered the mail; null for a mail without a person. No foreign key: a user is a file record and may be deleted. */
    #[ORM\Column(name: 'sender_user_id', nullable: true)]
    private ?int $senderUserId = null;

    /** The sender identity's display name (the From's name, the Reply-To's name); '' = the account's own. */
    #[ORM\Column(name: 'sender_name', length: self::NAME_LENGTH, options: ['default' => ''])]
    private string $senderName = '';

    /** The sender identity's address (the Reply-To); '' = no identity. */
    #[ORM\Column(name: 'sender_email', length: self::EMAIL_LENGTH, options: ['default' => ''])]
    private string $senderEmail = '';

    #[ORM\Column(length: self::SUBJECT_LENGTH)]
    private string $subject;

    #[ORM\Column(name: 'html_body', type: Types::TEXT, length: self::HTML_LENGTH)]
    private string $htmlBody;

    /** '' = derive from the HTML at send time. */
    #[ORM\Column(name: 'text_body', type: Types::TEXT, length: self::TEXT_LENGTH)]
    private string $textBody;

    /** `Namespace:path` of the body template the HTML was rendered from; '' for a hand-made body. */
    #[ORM\Column(length: self::TEMPLATE_LENGTH)]
    private string $template;

    #[ORM\Column(length: self::LANGUAGE_LENGTH)]
    private string $language;

    /** JSON list of `type:id` references, `[]` when none. */
    #[ORM\Column(type: Types::TEXT, length: self::TEXT_LENGTH)]
    private string $attachments;

    /** Opaque origin, e.g. `invoice:123`, `newsletter:5`; '' when none. */
    #[ORM\Column(length: self::SOURCE_LENGTH)]
    private string $source;

    #[ORM\Column(name: 'batch_id', length: self::BATCH_LENGTH)]
    private string $batchId;

    #[ORM\Column(length: 12)]
    private string $state = self::STATE_QUEUED;

    #[ORM\Column]
    private int $attempts = 0;

    #[ORM\Column(name: 'last_error', length: self::ERROR_LENGTH)]
    private string $lastError = '';

    #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    /** When the job may (next) pick the mail up; null once it left the queue (sent, failed, cancelled). */
    #[ORM\Column(name: 'next_attempt_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $nextAttemptAt;

    /**
     * Built by {@see \Z77\Module\Mailing\Outbox\OutboxService} only, from a
     * validated draft — the values are taken as given.
     *
     * @param list<string> $attachments `type:id` references
     */
    public function __construct(
        string $recipientEmail,
        string $recipientName,
        string $subject,
        string $htmlBody,
        string $textBody,
        string $replyTo,
        string $template,
        string $language,
        array $attachments,
        string $source,
        string $batchId,
        string $createdBy,
        \DateTimeImmutable $createdAt,
        ?int $senderUserId = null,
        string $senderName = '',
        string $senderEmail = '',
    ) {
        $this->senderUserId   = $senderUserId;
        $this->senderName     = $senderName;
        $this->senderEmail    = $senderEmail;
        $this->recipientEmail = $recipientEmail;
        $this->recipientName  = $recipientName;
        $this->subject        = $subject;
        $this->htmlBody       = $htmlBody;
        $this->textBody       = $textBody;
        $this->replyTo        = $replyTo;
        $this->template       = $template;
        $this->language       = $language;
        $this->attachments    = json_encode(array_values($attachments), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->source         = $source;
        $this->batchId        = $batchId;
        $this->createdBy      = $createdBy;
        $this->createdAt      = $createdAt;
        $this->nextAttemptAt  = $createdAt;
    }

    public function getId(): ?int { return $this->id; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCreatedBy(): string { return $this->createdBy; }
    public function getRecipientEmail(): string { return $this->recipientEmail; }
    public function getRecipientName(): string { return $this->recipientName; }
    public function getReplyTo(): string { return $this->replyTo; }
    public function getSenderUserId(): ?int { return $this->senderUserId; }
    public function getSenderName(): string { return $this->senderName; }
    public function getSenderEmail(): string { return $this->senderEmail; }
    public function getSubject(): string { return $this->subject; }
    public function getHtmlBody(): string { return $this->htmlBody; }
    public function getTextBody(): string { return $this->textBody; }
    public function getTemplate(): string { return $this->template; }
    public function getLanguage(): string { return $this->language; }
    public function getSource(): string { return $this->source; }
    public function getBatchId(): string { return $this->batchId; }
    public function getState(): string { return $this->state; }
    public function getAttempts(): int { return $this->attempts; }
    public function getLastError(): string { return $this->lastError; }
    public function getSentAt(): ?\DateTimeImmutable { return $this->sentAt; }
    public function getNextAttemptAt(): ?\DateTimeImmutable { return $this->nextAttemptAt; }

    /** @return list<string> the `type:id` references */
    public function getAttachments(): array
    {
        $list = json_decode($this->attachments, true);

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /** Whether a person's sender identity signs the mail. */
    public function hasSender(): bool
    {
        return $this->senderEmail !== '';
    }

    /** «Max Muster <max.muster@example.ch>» — the identity the mail was sent as; '' without one. */
    public function senderLabel(): string
    {
        if ($this->senderEmail === '') {
            return '';
        }

        return $this->senderName === '' ? $this->senderEmail : $this->senderName . ' <' . $this->senderEmail . '>';
    }

    /** «Max Muster <max@example.ch>» or the bare address — for the screen. */
    public function recipientLabel(): string
    {
        return $this->recipientName === '' ? $this->recipientEmail : $this->recipientName . ' <' . $this->recipientEmail . '>';
    }
}
