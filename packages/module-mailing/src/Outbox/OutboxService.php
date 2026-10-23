<?php

namespace Z77\Module\Mailing\Outbox;

use Z77\Core\DI;
use Z77\Module\Mailing\Attachments\AttachmentProviders;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Jobs\JobQueue;
use Z77\Shared\Mail\EmailMessage;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\Message;

/**
 * The outbox's write side (owner 2026-10-22) — the ONE way an e-mail gets into
 * `mail_outbox`. It NEVER sends: sending is the `mail-outbox` job's
 * ({@see \Z77\Module\Mailing\Jobs\MailOutboxJob}), in throttled batches, under
 * the job lock. A request that queues 500 mails returns in milliseconds.
 *
 *   - {@see queueBatch()} — validates EVERY draft first (nothing is written
 *     when one is refused, {@see InvalidOutboxMailException} names each),
 *     renders the templates, writes all rows in ONE transaction under one
 *     batch id, then makes sure the job is queued;
 *   - {@see queue()} — one draft, a batch of one;
 *   - {@see retry()} / {@see cancel()} — the operator's two corrections, each
 *     a conditional transition ({@see OutboxMailRepository});
 *   - {@see counts()} and {@see isJobQueued()} for the screen.
 *
 * Validation reuses the kernel `Message` rules (address syntax, no CR/LF in a
 * header) by feeding the values through a throwaway `Message` — the same
 * checks the job's real `Message` will apply, asked before anything is stored.
 */
final class OutboxService
{
    /** The job's key in the module config `jobs` — enqueued here, executed by the runner only. */
    public const JOB_KEY = 'mail-outbox';

    private const LANGUAGE_PATTERN = '/^[a-z]{2}(-[A-Z]{2})?$/';

    /** Attachments per mail — a document mail carries one or two; a list longer than this is a bug upstream. */
    public const MAX_ATTACHMENTS = 20;

    public function __construct(
        private UnifiedEntityManager $em,
        private ?AttachmentProviders $providers = null,
        private ?EmailService $emailService = null,
        private ?JobQueue $jobs = null,
    ) {
    }

    /** @throws InvalidOutboxMailException */
    public function queue(OutboxDraft $draft, string $actor): OutboxMail
    {
        return $this->queueBatch([$draft], $actor)[0];
    }

    /**
     * All drafts or none, one batch id, one transaction; then the job is queued
     * (unless it already waits or runs). The returned rows are `queued`, due now.
     *
     * @param list<OutboxDraft> $drafts
     * @return list<OutboxMail>
     * @throws InvalidOutboxMailException at least one draft is refused — nothing written
     */
    public function queueBatch(array $drafts, string $actor): array
    {
        if ($drafts === []) {
            return [];
        }
        $errors = [];
        foreach (array_values($drafts) as $index => $draft) {
            if (!$draft instanceof OutboxDraft) {
                throw new \InvalidArgumentException('queueBatch() takes OutboxDraft objects, got ' . get_debug_type($draft) . '.');
            }
            if (($problems = $this->problems($draft)) !== []) {
                $errors[$index] = $problems;
            }
        }
        if ($errors !== []) {
            throw new InvalidOutboxMailException($errors);
        }

        // Rendered BEFORE the transaction: a missing template is a programmer
        // error and throws, and nothing is half-written.
        $rows    = [];
        $batchId = bin2hex(random_bytes(16));
        $now     = new \DateTimeImmutable();
        $actor   = mb_substr(trim($actor) !== '' ? trim($actor) : 'system', 0, OutboxMail::ACTOR_LENGTH);
        foreach (array_values($drafts) as $draft) {
            $rows[] = new OutboxMail(
                trim($draft->to),
                trim($draft->toName),
                trim($draft->subject),
                $this->htmlOf($draft),
                $draft->text,
                trim($draft->replyTo),
                $draft->hasTemplate() ? $draft->templateNameSpace . ':' . $draft->template : '',
                $draft->language,
                $draft->attachments,
                trim($draft->source),
                $batchId,
                $actor,
                $now,
                $draft->sender !== null ? $draft->senderUserId : null,
                $draft->sender?->name ?? '',
                $draft->sender?->email ?? '',
            );
        }

        $this->em->getTransaction(OutboxMail::class)->run(function () use ($rows): void {
            foreach ($rows as $row) {
                $this->em->persist($row);
            }
        });
        $this->ensureJobQueued($actor);

        return $rows;
    }

    /**
     * «Erneut senden» of a failed mail: back to `queued`, due now, a full new
     * round of attempts; the job is queued when it is not waiting already.
     *
     * @throws OutboxStateException the mail is not (or no longer) failed
     */
    public function retry(int $id, string $actor): OutboxMail
    {
        if (!$this->mails()->requeue($id, new \DateTimeImmutable())) {
            throw new OutboxStateException('Nur eine fehlgeschlagene E-Mail kann erneut gesendet werden — der Zustand hat sich inzwischen geändert.');
        }
        $this->ensureJobQueued($actor);

        return $this->mails()->fresh($id) ?? throw new OutboxStateException('E-Mail nicht gefunden.');
    }

    /**
     * «Abbrechen» of a waiting mail. A mail the job has already claimed
     * (`sending`) cannot be called back — the refusal says so.
     *
     * @throws OutboxStateException the mail is not (or no longer) queued
     */
    public function cancel(int $id): OutboxMail
    {
        if (!$this->mails()->cancel($id)) {
            throw new OutboxStateException('Nur eine wartende E-Mail kann abgebrochen werden — sie wird gerade gesendet oder ist schon erledigt.');
        }

        return $this->mails()->fresh($id) ?? throw new OutboxStateException('E-Mail nicht gefunden.');
    }

    /** @return array<string, int> state => rows, every state present */
    public function counts(): array
    {
        return $this->mails()->countByState();
    }

    /** Whether the job has an open entry (queued or running) — a waiting mail without one is never sent. */
    public function isJobQueued(): bool
    {
        return $this->jobs()->hasOpenEntry(self::JOB_KEY);
    }

    /**
     * Queues the job unless it already waits or runs. Called after every write
     * that adds work, and by the screen's «Versand starten» when mails wait
     * without a job (an entry removed by hand, a job that failed three times).
     *
     * @return bool whether an entry was added
     */
    public function ensureJobQueued(string $actor): bool
    {
        $jobs = $this->jobs();
        if ($jobs->hasOpenEntry(self::JOB_KEY)) {
            return false;
        }
        $jobs->enqueue(self::JOB_KEY, [], $actor !== '' ? $actor : 'system');

        return true;
    }

    /**
     * Every reason this draft cannot be queued, German, empty when it can.
     *
     * @return list<string>
     */
    public function problems(OutboxDraft $draft): array
    {
        $problems = [];
        $probe    = new Message();
        $to       = trim($draft->to);
        try {
            $probe->to($to);
            if (strlen($to) > OutboxMail::EMAIL_LENGTH) {
                $problems[] = 'Empfängeradresse ist zu lang.';
            }
        } catch (\InvalidArgumentException) {
            $problems[] = $to === '' ? 'Empfängeradresse fehlt.' : "Ungültige Empfängeradresse «{$to}».";
        }
        if (preg_match('/[\r\n]/', $draft->toName) === 1) {
            $problems[] = 'Empfängername darf keinen Zeilenumbruch enthalten.';
        } elseif (mb_strlen(trim($draft->toName)) > OutboxMail::NAME_LENGTH) {
            $problems[] = 'Empfängername ist zu lang.';
        }

        $subject = trim($draft->subject);
        try {
            $probe->subject($draft->subject);
            if ($subject === '') {
                $problems[] = 'Betreff fehlt.';
            } elseif (mb_strlen($subject) > OutboxMail::SUBJECT_LENGTH) {
                $problems[] = 'Betreff ist zu lang.';
            }
        } catch (\InvalidArgumentException) {
            $problems[] = 'Betreff darf keinen Zeilenumbruch enthalten.';
        }

        if (trim($draft->replyTo) !== '') {
            try {
                $probe->replyTo($draft->replyTo);
            } catch (\InvalidArgumentException) {
                $problems[] = 'Ungültige Antwortadresse (Reply-To) «' . trim($draft->replyTo) . '».';
            }
        }

        if ($draft->sender !== null) {
            // The person's identity (owner 2026-10-23): the user form's rule, then the header
            // rules of the Message the job will build — and it replaces Reply-To, never both.
            foreach ($draft->sender->problems() as $problem) {
                $problems[] = 'Absender: ' . $problem;
            }
            if ($draft->sender->problems() === []) {
                try {
                    (new Message())->fromName($draft->sender->name)->replyTo($draft->sender->email, $draft->sender->name);
                } catch (\InvalidArgumentException) {
                    $problems[] = 'Absender: ungültig.';
                }
            }
            if (trim($draft->replyTo) !== '') {
                $problems[] = 'Absender und Antwortadresse (Reply-To) schliessen sich aus — die Antwortadresse ist die des Absenders.';
            }
            if ($draft->senderUserId !== null && $draft->senderUserId <= 0) {
                $problems[] = 'Absender: ungültige Benutzer-Id.';
            }
        }

        if ($draft->hasTemplate()) {
            if ($draft->templateNameSpace === '') {
                $problems[] = 'Vorlage ohne Namensraum.';
            }
        } elseif (trim($draft->html) === '' && trim($draft->text) === '') {
            $problems[] = 'Die E-Mail hat keinen Inhalt.';
        }
        if (strlen($draft->html) > OutboxMail::HTML_LENGTH) {
            $problems[] = 'HTML-Inhalt ist zu gross.';
        }
        if (strlen($draft->text) > OutboxMail::TEXT_LENGTH) {
            $problems[] = 'Text-Inhalt ist zu gross.';
        }
        if ($draft->language !== '' && preg_match(self::LANGUAGE_PATTERN, $draft->language) !== 1) {
            $problems[] = "Ungültige Sprache «{$draft->language}».";
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $draft->source) === 1 || mb_strlen(trim($draft->source)) > OutboxMail::SOURCE_LENGTH) {
            $problems[] = 'Ungültige Quellenangabe.';
        }

        if (count($draft->attachments) > self::MAX_ATTACHMENTS) {
            $problems[] = 'Zu viele Anhänge (höchstens ' . self::MAX_ATTACHMENTS . ').';
        } else {
            foreach ($draft->attachments as $reference) {
                $refusal = is_string($reference) ? $this->providers()->refusal($reference) : 'Anhang: keine gültige Referenz.';
                if ($refusal !== null) {
                    $problems[] = $refusal;
                }
            }
        }

        return $problems;
    }

    private function htmlOf(OutboxDraft $draft): string
    {
        if (!$draft->hasTemplate()) {
            return $draft->html;
        }

        return (string) $this->emailService()->renderBody(
            (new EmailMessage())->subject(trim($draft->subject))->template($draft->template, $draft->templateNameSpace, $draft->context)
        );
    }

    private function mails(): OutboxMailRepository
    {
        return $this->em->getRepository(OutboxMail::class);
    }

    private function providers(): AttachmentProviders
    {
        return $this->providers ??= AttachmentProviders::fromModules(DI::getModuleManager());
    }

    private function emailService(): EmailService
    {
        return $this->emailService ??= DI::getEmailService();
    }

    private function jobs(): JobQueue
    {
        return $this->jobs ??= new JobQueue($this->em);
    }
}
