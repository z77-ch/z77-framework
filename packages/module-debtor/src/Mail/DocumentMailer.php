<?php

namespace Z77\Module\Debtor\Mail;

use Z77\Core\DI;
use Z77\Module\Debtor\Services\Creditor;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Outbox\OutboxDraft;
use Z77\Module\Mailing\Outbox\OutboxService;
use Z77\Module\Mailing\Outbox\OutboxSettings;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Mail\DocumentMailKinds;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\SenderIdentity;

/**
 * The shared core of every business document sent by e-mail (Rule 9; owner 2026-11-13: offer,
 * order confirmation, delivery note and dunning notice «the way invoices are sent») — what
 * {@see InvoiceMails}, {@see DunningMails} and module-order's `Mail\OrderMails` have in common:
 *
 *   - the RECIPIENT rule: the address as it stands on the document; none → «keine E-Mail-Adresse»,
 *     not an address → «E-Mail-Adresse ungültig» — the document is SKIPPED, never a silent failure
 *     ({@see addressProblem()});
 *   - the TEXT: subject, fact line and body of the document mail kind through
 *     `EmailService::documentMail()` (Service › E-Mail › Beleg-Mails), `{absender}` = the mandator,
 *     `{anzeigename}` = the identity chosen under «Senden als», `{briefanrede}` = the greeting of the
 *     document's address in the mail's language (owner 2026-11-13, kernel `LetterSalutation`); a
 *     subject never ends in a dangling dash ({@see compose()});
 *   - the QUEUE: one outbox mail per document into ONE batch (`OutboxService::queueBatch()`), body
 *     `emails/documentMail` in `emails/layout` — the fact line on top, the text, the closing block
 *     «maschinell erstellt · Fragen beantwortet gerne:» with name, e-mail and phone of the identity
 *     (board «E-Mail-Standard») —, signed by the identity (`OutboxDraft::withSender()`), the PDF as
 *     a reference its provider renders at SEND time ({@see queue()});
 *   - the HISTORY: the outbox rows per source, «covered» = a mail waits or went out, the German line
 *     per delivery, the outbox pace ({@see deliveries()}, {@see isCovered()}, {@see deliveryLine()}).
 *
 * module-mailing is OPTIONAL for debtor and order (`composer.json` suggest): {@see available()} says
 * whether the outbox is there; nothing of module-mailing is loaded while it is not (the state values
 * are written out below — a constant naming `OutboxMail` would load it).
 */
final class DocumentMailer
{
    public const MODULE = 'mailing';

    /** The delivery states that COVER a document (owner 2026-10-23): a mail waiting or sent. Failed / cancelled = not sent. */
    public const COVERING_STATES = ['queued', 'sending', 'sent'];

    /** Of {@see COVERING_STATES}, the ones still waiting. */
    public const WAITING_STATES = ['queued', 'sending'];

    public const NO_ADDRESS      = 'keine E-Mail-Adresse';
    public const INVALID_ADDRESS = 'E-Mail-Adresse ungültig';

    public function __construct(
        private UnifiedEntityManager $em,
        private ?EmailService $emailService = null,
        private ?DocumentMailKinds $kinds = null,
    ) {
    }

    /** Whether the outbox (module-mailing) is installed and registered. */
    public function available(): bool
    {
        return class_exists(OutboxService::class) && DI::getModuleManager()->getModuleConfig(self::MODULE) !== null;
    }

    /** $email when it is a deliverable address, else ''. */
    public static function recipient(string $email): string
    {
        $email = trim($email);

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
    }

    /** Why $email gets no mail — «keine E-Mail-Adresse» / «E-Mail-Adresse ungültig» — null when it can. */
    public static function addressProblem(string $email): ?string
    {
        return match (true) {
            trim($email) === ''          => self::NO_ADDRESS,
            self::recipient($email) === '' => self::INVALID_ADDRESS,
            default                      => null,
        };
    }

    /** The mandator's name — `{absender}` of every document mail (the company; the person is `{anzeigename}`). */
    public function senderCompany(): string
    {
        return Creditor::mandator($this->em)?->getName() ?? '';
    }

    /**
     * The mail as the outbox will store it — subject, fact line and text of the kind resolved in the
     * document's language (German when the kind has no text in it), `{anzeigename}` = $sender's name
     * ('' without one: its line disappears), `{briefanrede}` = the greeting of the document's address
     * in THAT language ({@see DocumentMail::$address}).
     *
     * @return array{to: string, subject: string, facts: string, body: string, language: string, kind: string}
     * @throws \LogicException $mail has no deliverable address ({@see addressProblem()})
     */
    public function compose(DocumentMail $mail, ?SenderIdentity $sender = null): array
    {
        if (($problem = self::addressProblem($mail->to)) !== null) {
            throw new \LogicException($mail->source . ': ' . $problem);
        }
        $language = $this->emailService()->documentMailLanguage($mail->kind, $mail->language, $this->kinds());
        $greeting = $mail->address?->greeting($language) ?? \Z77\Shared\Mail\LetterSalutation::resolve('', '', '', '', $language);
        $values   = ['anzeigename' => $sender?->name ?? '', 'briefanrede' => $greeting] + $mail->values;
        $composed = $this->emailService()->documentMail($mail->kind, $mail->language, $values, $this->kinds());
        // A placeholder without a value (no mandator name) must not leave a dangling dash.
        $composed['subject'] = (string) preg_replace('/[\s—–·:-]+$/u', '', $composed['subject']);

        return ['to' => self::recipient($mail->to), 'kind' => $mail->kind] + $composed;
    }

    /**
     * Queues one outbox mail per DocumentMail into ONE batch (`OutboxService::queueBatch()` — all or
     * none). The caller has left out what cannot be mailed; a mail without an address here is a
     * programming error ({@see compose()} throws). Every mail is signed by $sender — already re-checked
     * against the CURRENT user's identities by the caller (`SenderIdentities::choose()`); $userId is
     * stored as «Ausgelöst von». Answers the batch id ('' for an empty list).
     *
     * @param list<DocumentMail> $mails
     * @throws \Z77\Module\Mailing\Outbox\InvalidOutboxMailException a draft refused by the outbox — nothing written
     * @throws \RuntimeException the outbox is not installed
     */
    public function queue(array $mails, string $actor, SenderIdentity $sender, ?int $userId = null): string
    {
        if (!$this->available()) {
            throw new \RuntimeException('The mail outbox (module-mailing) is not installed — documents cannot be sent by e-mail.');
        }
        if ($mails === []) {
            return '';
        }
        $drafts = [];
        foreach ($mails as $mail) {
            $composed = $this->compose($mail, $sender);
            // The closing block names the identity (board «E-Mail-Standard»): name, e-mail, phone.
            $context  = [
                'body'   => $composed['body'],
                'facts'  => $composed['facts'],
                // The closing speaks the text's language, not the installation's translator scope.
                'language' => $composed['language'],
                'sender' => ['name' => $sender->name, 'email' => $sender->email, 'phone' => $sender->phone],
            ];
            $drafts[] = OutboxDraft::template($composed['to'], $composed['subject'], 'emails/documentMail', 'Z77\\Shared', $context, $mail->recipientName)
                ->withLanguage($composed['language'])
                ->withAttachments([$mail->attachment])
                ->withSource($mail->source)
                ->withSender($sender, $userId);
        }
        $rows = (new OutboxService($this->em))->queueBatch($drafts, $actor);

        return $rows[0]->getBatchId();
    }

    /**
     * What went out per document id — the outbox rows of the sources `<prefix><id>`, oldest first, as
     * plain arrays (`OutboxMailRepository::deliveriesOf()`). Empty without the outbox.
     *
     * @param list<int> $ids
     * @return array<int, list<array<string, mixed>>>
     */
    public function deliveries(string $prefix, array $ids): array
    {
        if ($ids === [] || !$this->available()) {
            return [];
        }
        /** @var OutboxMailRepository $mails */
        $mails  = $this->em->getRepository(OutboxMail::class);
        $result = [];
        foreach ($mails->deliveriesOf(array_map(static fn(int $id) => $prefix . $id, $ids)) as $source => $rows) {
            $result[(int) substr($source, strlen($prefix))] = $rows;
        }

        return $result;
    }

    /** Whether one of $deliveries waits or went out ({@see COVERING_STATES}). */
    public static function isCovered(array $deliveries): bool
    {
        foreach ($deliveries as $delivery) {
            if (in_array($delivery['state'] ?? '', self::COVERING_STATES, true)) {
                return true;
            }
        }

        return false;
    }

    /** The outbox's pace in German («10 pro Minute») from its ONE setting; '' without the outbox. */
    public function rate(): string
    {
        return $this->available() ? OutboxSettings::fromModules(DI::getModuleManager())->rateText() : '';
    }

    /** Whether the mail config is complete — else the mails wait in the outbox (the confirmation warns). */
    public static function mailReady(): bool
    {
        return \Z77\Shared\Mail\Mailer::create()->isConfigured();
    }

    /** German, one line per delivery: «per E-Mail an a@b.ch gesendet am 22.10.2026 18:04 · als Buchhaltung». */
    public static function deliveryLine(array $delivery): string
    {
        $when = static fn(?\DateTimeImmutable $at): string => $at !== null ? $at->format('d.m.Y H:i') : '';
        $as   = trim((string) ($delivery['sender'] ?? ''));

        return (match ($delivery['state']) {
            'sent'      => 'per E-Mail an ' . $delivery['to'] . ' gesendet am ' . $when($delivery['sent_at']),
            'failed'    => 'E-Mail an ' . $delivery['to'] . ' fehlgeschlagen (eingereiht ' . $when($delivery['created_at']) . ')',
            'cancelled' => 'E-Mail an ' . $delivery['to'] . ' abgebrochen (eingereiht ' . $when($delivery['created_at']) . ')',
            default     => 'E-Mail an ' . $delivery['to'] . ' im Postausgang seit ' . $when($delivery['created_at']),
        }) . ($as !== '' ? ' · als ' . $as : '');
    }

    /**
     * The flash after queueing ONE document by hand: «E-Mail im Postausgang — Versand 10 pro Minute.»,
     * «… (erneut)» when one went out or waits already.
     */
    public function queuedMessage(string $documentName, bool $again): string
    {
        $rate = $this->rate();

        return $documentName . ': E-Mail im Postausgang' . ($again ? ' (erneut)' : '') . ($rate !== '' ? ' — Versand ' . $rate : '') . '.';
    }

    private function emailService(): EmailService
    {
        return $this->emailService ??= DI::getEmailService();
    }

    private function kinds(): DocumentMailKinds
    {
        return $this->kinds ??= DocumentMailKinds::fromModules(DI::getModuleManager());
    }
}
