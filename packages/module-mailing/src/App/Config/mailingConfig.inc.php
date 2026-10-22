<?php
namespace Z77\Module\Mailing\App;

use Z77\Core\Config\AuthRole;
use Z77\Module\Mailing\Entities\OutboxMail;

/**
 * Mailing module — the MAIL OUTBOX (step 2 of the mail plan, owner decisions
 * 2026-10-22): e-mails are QUEUED (`OutboxService`), never sent in the
 * request, and the `mail-outbox` job sends them in throttled batches — 10,
 * then a minute's pause, then the next 10, as the old system did for the
 * ~500 mails a customer sends once a week. The shared base of the later
 * document mails (step 3) and the newsletter (step 4); both write drafts, the
 * outbox does the rest. The sender is the installation's identity from the
 * mail config (`config/client/mail.inc.php`, Service › E-Mail), as for the
 * form mails.
 *
 * NO routes and no view area: the one screen, Service › Postausgang, is a
 * fragment ({@see \Z77\Module\Mailing\Ui\OutboxControllerTrait}) mounted by a
 * host controller in module-backend under `/backend/service/outbox` (ADR-018).
 * `/mailing/…` resolves nothing.
 *
 * Registering the module on an EXISTING installation is the project's step:
 * add `"Z77\\Module\\Mailing\\": ["override/z77/module/mailing/src/"]` to the
 * project composer.json autoload, `composer update` (the installer adds the
 * module and its navigation entry), `php vendor/bin/z77-db migrate`
 * (`Version20261022170000`, table `mail_outbox`).
 */
return [
    'viewArea'   => false,
    'moduleRole' => AuthRole::ADMIN,

    'doctrineEntities' => [
        OutboxMail::class,
    ],

    // The outbox's tunables — the ONE place (Rule 2). A project records only its
    // deviation in `override/z77/module/mailing/src/App/Config/outboxConfig.inc.php`
    // (e.g. `<?php return ['batchSize' => 20];`), merged over these by
    // `Outbox\OutboxSettings::fromModules()`.
    'outbox' => [
        'batchSize'    => 10,           // mails per job slice
        'pauseSeconds' => 60,           // wait after a slice while mails remain
        'maxAttempts'  => 3,            // attempts per mail before «fehlgeschlagen»
        'retryMinutes' => [5, 15, 60],  // wait after the 1st, 2nd, 3rd failure (last repeats)
    ],

    // The job that sends the outbox (ADR-031). NO defaultSchedule on purpose:
    // OutboxService queues it whenever mails are added, and an idle schedule
    // would make every pass print (cron mails output). `maxAttempts` here is the
    // RUNNER's retry of a crashed slice, not the per-mail attempts above.
    'jobs' => [
        'mail-outbox' => [
            'class'       => \Z77\Module\Mailing\Jobs\MailOutboxJob::class,
            'label'       => 'E-Mail-Versand (Postausgang)',
            'runAs'       => AuthRole::CRON_JOB,
            'maxAttempts' => 3,
        ],
    ],

    // Attachment providers, type => class (ADR-053) — modules that put files into
    // outbox mails register here in THEIR config (`mailAttachments`), e.g.
    // debtor: 'invoice-pdf' => InvoicePdfAttachment::class. None of our own.
    'mailAttachments' => [],

    // The backend dashboard (ADR-052): failed mails, mails waiting too long.
    'dashboard' => [\Z77\Module\Mailing\Dashboard\MailingDashboard::class],

    'cache' => [
        'enabled' => false,
    ],
];
