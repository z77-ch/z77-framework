<?php

namespace Z77\Module\Mailing\Outbox;

use Z77\Shared\Mail\SenderIdentity;

/**
 * What a caller hands the outbox for ONE recipient — intent only, validated by
 * {@see OutboxService} (never by the caller). Immutable; the fluent `with…()`
 * methods return a copy, so a newsletter builds one draft and derives one per
 * recipient:
 *
 * ```php
 * $base   = OutboxDraft::html('', 'Herbstangebot', $html)->withSource('newsletter:5');
 * $drafts = array_map(fn($c) => $base->to($c->getEmail(), $c->getName()), $contacts);
 * $outbox->queueBatch($drafts, $actor);
 * ```
 *
 * The body is EITHER a finished HTML (`html()` — sent as given, the caller owns
 * the markup and escaped what it put in) OR a body template (`template()` —
 * rendered at queue time inside the shared `emails/layout`, like
 * `EmailService::send()`), or plain text only (`text()`). The sending ACCOUNT is
 * never part of a draft: it is the installation's identity from the mail config
 * (`config/client/mail.inc.php`, mail.md), filled in by the `Mailer`. A mail a
 * staff member triggers carries his SENDER IDENTITY (`withSender()`, owner
 * 2026-10-23): its display name becomes the From's name, «name <email>» the
 * Reply-To — the From address stays the account's.
 */
final class OutboxDraft
{
    /**
     * @param array<string, mixed> $context template context (escaped by the template via e())
     * @param list<string>         $attachments `type:id` references
     */
    private function __construct(
        public readonly string $to,
        public readonly string $toName,
        public readonly string $subject,
        public readonly string $html,
        public readonly string $text,
        public readonly string $template,
        public readonly string $templateNameSpace,
        public readonly array $context,
        public readonly string $replyTo = '',
        public readonly string $language = '',
        public readonly array $attachments = [],
        public readonly string $source = '',
        public readonly ?SenderIdentity $sender = null,
        public readonly ?int $senderUserId = null,
    ) {
    }

    /** A finished HTML body; the text half is derived at send time unless `withText()` sets one. */
    public static function html(string $to, string $subject, string $html, string $toName = ''): self
    {
        return new self($to, $toName, $subject, $html, '', '', '', []);
    }

    /** A body template, rendered at queue time (override-first via FileFinder) inside `emails/layout`. */
    public static function template(string $to, string $subject, string $template, string $nameSpace, array $context = [], string $toName = ''): self
    {
        return new self($to, $toName, $subject, '', '', $template, $nameSpace, $context);
    }

    /** A plain-text mail (a single text/plain part). */
    public static function text(string $to, string $subject, string $text, string $toName = ''): self
    {
        return new self($to, $toName, $subject, '', $text, '', '', []);
    }

    /** The same mail to another recipient. */
    public function to(string $to, string $toName = ''): self
    {
        return $this->copy(['to' => $to, 'toName' => $toName]);
    }

    public function withText(string $text): self
    {
        return $this->copy(['text' => $text]);
    }

    public function withReplyTo(string $replyTo): self
    {
        return $this->copy(['replyTo' => $replyTo]);
    }

    public function withLanguage(string $language): self
    {
        return $this->copy(['language' => $language]);
    }

    /** @param list<string> $references `type:id`, e.g. `invoice-pdf:123` */
    public function withAttachments(array $references): self
    {
        return $this->copy(['attachments' => array_values($references)]);
    }

    /** Opaque origin, e.g. `invoice:123`, `newsletter:5` — the screen shows and searches it. */
    public function withSource(string $source): self
    {
        return $this->copy(['source' => $source]);
    }

    /**
     * Signed by a staff member's sender identity: From «{name} <account>», Reply-To «{name}
     * <{email}>»; `$userId` = the backend user who triggered the mail («Ausgelöst von»). Excludes
     * `withReplyTo()` — the identity IS the answer address (a draft with both is refused).
     */
    public function withSender(SenderIdentity $sender, ?int $userId = null): self
    {
        return $this->copy(['sender' => $sender, 'senderUserId' => $userId]);
    }

    public function hasTemplate(): bool
    {
        return $this->template !== '';
    }

    /** @param array<string, mixed> $changes */
    private function copy(array $changes): self
    {
        $v = $changes + get_object_vars($this);

        return new self(
            $v['to'], $v['toName'], $v['subject'], $v['html'], $v['text'], $v['template'],
            $v['templateNameSpace'], $v['context'], $v['replyTo'], $v['language'], $v['attachments'], $v['source'],
            $v['sender'], $v['senderUserId'],
        );
    }
}
