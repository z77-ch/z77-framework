<?php

/**
 * module-mailing harness (CLI) — the mail outbox and its sending job (step 2
 * of the mail plan, owner decisions 2026-10-22) against a REAL MariaDB
 * (ADR-039 decision 16), throwaway schema per run, created by the module's
 * own MIGRATION through the `z77-db` application (never `SchemaTool`).
 *
 * What is load-bearing here:
 *
 *   - (A) `migrate` creates `mail_outbox` (utf8mb4_unicode_ci / InnoDB,
 *     MEDIUMTEXT html, the three indexes, no foreign key); a second
 *     `migrate` is a no-op and `diff` reports NO change;
 *   - (B) the settings: the defaults are the module config's, a project's
 *     `outboxConfig.inc.php` records only its deviation, values clamp;
 *   - (C) the attachment-provider registry: `type:id`, bad declarations
 *     refused naming the origin, a project's extension file counts;
 *   - (D) queueing: every `Message` rule asked before anything is written,
 *     a batch is all-or-nothing under ONE batch id, the job is queued once,
 *     a template is rendered into the shared layout at queue time;
 *   - (E) the job slice with a fake transport: 10 per slice, `again` with
 *     notBefore 60 while mails wait, `done` when the outbox is empty, the
 *     sender from the mail config, the text half derived;
 *   - (F) retries: 5 / 15 min backoff, failed after maxAttempts, a mail that
 *     can never be sent fails at once, an interrupted `sending` row fails
 *     with the question, an unconfigured mailer burns no attempt;
 *   - (G) attachments resolved at send time through a provider, a throwing
 *     provider is a retry;
 *   - (H) cancel / retry are conditional transitions;
 *   - (I) the screen through host doubles: the list, the bands, the menu
 *     items by state, the window with the sandboxed iframe — no raw mail
 *     HTML in the backend page (Rule 7);
 *   - (J) the dashboard entries; (K) access, registration, navigation seed;
 *     (L) the developer path (FileTransport); (M) source guards.
 *
 * Run: php tests/module-mailing.php
 * Needs vendor/ with Doctrine, a reachable MariaDB, credentials in
 * `%USERPROFILE%\.z77\mariadb.txt` or Z77_TEST_DB_*. Nothing is written into
 * the repository; the schema `z77test_<random>` and the temp installation are
 * removed at the end.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "module-mailing: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Z77\Core\Config\AuthRole;
use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\I18n;
use Z77\Core\Services\ModuleManager;
use Z77\Module\Mailing\Attachments\AttachmentProvider;
use Z77\Module\Mailing\Attachments\AttachmentProviders;
use Z77\Module\Mailing\Attachments\AttachmentReference;
use Z77\Module\Mailing\Dashboard\MailingDashboard;
use Z77\Module\Mailing\Entities\OutboxMail;
use Z77\Module\Mailing\Jobs\MailOutboxJob;
use Z77\Module\Mailing\Outbox\InvalidOutboxMailException;
use Z77\Module\Mailing\Outbox\OutboxDraft;
use Z77\Module\Mailing\Outbox\OutboxSender;
use Z77\Module\Mailing\Outbox\OutboxService;
use Z77\Module\Mailing\Outbox\OutboxSettings;
use Z77\Module\Mailing\Outbox\OutboxStateException;
use Z77\Module\Mailing\Repositories\OutboxMailRepository;
use Z77\Module\Mailing\Ui\OutboxControllerTrait;
use Z77\Module\Mailing\Ui\OutboxLayout;
use Z77\Module\Mailing\Ui\OutboxListing;
use Z77\Persistence\Doctrine\Bootstrap as DoctrineBootstrap;
use Z77\Persistence\Doctrine\Console\MigrationDirectories;
use Z77\Persistence\Doctrine\Console\MigrationsApplication;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Dashboard\Severity;
use Z77\Shared\Jobs\JobContext;
use Z77\Shared\Jobs\JobQueue;
use Z77\Shared\Mail\Attachment;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\Mailer;
use Z77\Shared\Mail\MailTransport;
use Z77\Shared\Services\AuthService;
use Z77\Shared\Libraries\Convention\Naming;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Runs $fn and returns the exception when it throws $class, null otherwise. */
function caught(callable $fn, string $class): ?\Throwable
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class ? $e : null; }
    return null;
}

// ── doubles ──────────────────────────────────────────────────────────────

/** Records what would have gone over the wire; fails while $failWith is set. */
final class FakeTransport implements MailTransport
{
    /** @var list<array{sender:string,recipients:list<string>,data:string}> */
    public array $sent = [];
    public ?string $failWith = null;

    public function send(string $sender, array $recipients, string $data): void
    {
        if ($this->failWith !== null) {
            throw new \RuntimeException($this->failWith);
        }
        $this->sent[] = ['sender' => $sender, 'recipients' => $recipients, 'data' => $data];
    }
}

/** An attachment provider as module-debtor will register one: `test-pdf:<n>`. */
final class TestPdfAttachment implements AttachmentProvider
{
    public static int $resolved = 0;
    public static bool $broken  = false;

    public function accepts(string $id): bool
    {
        return ctype_digit($id) && (int) $id < 900;
    }

    public function resolve(string $id): Attachment
    {
        if (self::$broken) {
            throw new \RuntimeException('PDF renderer unavailable');
        }
        self::$resolved++;

        return new Attachment("Beleg {$id}.pdf", 'application/pdf', "%PDF-1.4 test {$id}");
    }
}

final class NotAProvider {}

// ── credentials: never in the repository ─────────────────────────────────

$credentials = [
    'host'     => getenv('Z77_TEST_DB_HOST') ?: 'localhost',
    'user'     => getenv('Z77_TEST_DB_USER') ?: 'z77test',
    'password' => getenv('Z77_TEST_DB_PASSWORD') ?: '',
];
if ($credentials['password'] === '') {
    $file = rtrim((string) (getenv('USERPROFILE') ?: getenv('HOME')), '/\\') . '/.z77/mariadb.txt';
    foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
        if (str_starts_with($line, $credentials['user'] . '=')) {
            $credentials['password'] = substr($line, strlen($credentials['user']) + 1);
        }
    }
}
if ($credentials['password'] === '') {
    fwrite(STDERR, "module-mailing: no database password — %USERPROFILE%\\.z77\\mariadb.txt or Z77_TEST_DB_PASSWORD.\n");
    exit(2);
}

// ── throwaway schema (deliberately NOT in our collation) and installation ─

$dbName = 'z77test_' . bin2hex(random_bytes(4));
$admin  = DriverManager::getConnection([
    'driver' => 'pdo_mysql', 'host' => $credentials['host'],
    'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$admin->executeStatement("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-module-mailing-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);

$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static function () use ($admin, $dbName, $rm, $base): void {
    try { $admin->executeStatement("DROP DATABASE IF EXISTS `{$dbName}`"); } catch (\Throwable) {}
    $rm($base);
});

$root     = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$package  = $root . '/packages/module-mailing';
$override = $base . '/override/module/mailing';
$write('config/vendor/fileFinder.inc.php', '<?php return ' . var_export([
    'resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'],
    'namespaces'  => [
        'Z77\\Module\\Mailing\\' => ['sourcePaths' => [$override, $package]],
        'Z77\\Shared\\'          => ['sourcePaths' => [$root . '/packages/kernel/shared']],
    ],
], true) . ';');
$write('config/vendor/moduleManager.inc.php', '<?php return ' . var_export([
    'modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'mailing', 'modules' => ['mailing' => []],
], true) . ';');
$write('config/systemConfig.inc.php', "<?php return ['canonicalBaseUrl' => '', 'baseCurrency' => 'CHF'];");
$write('config/i18n.inc.php', "<?php return ['defaultLanguage' => 'de', 'languages' => ['de']];");
$write('config/client/database.inc.php', '<?php return ' . var_export([
    'host' => $credentials['host'], 'port' => null, 'name' => $dbName,
    'user' => $credentials['user'], 'password' => $credentials['password'],
], true) . ';');
// A body template of a project, found override-first like any mail template.
$write('override/module/mailing/res/view/templates/emails/outboxTest.tpl.php',
    "<p>Guten Tag <?= e(\$name) ?></p>\n<p><a href=\"https://example.ch/offer\">Zum Angebot</a></p>\n");

/** The DI wiring Bootstrap::__construct() + pullUpServices() do, reduced to what the drivers and the CLI read. Calling it again is the «fresh request». */
$wireDi = static function () use ($base): UnifiedEntityManager {
    DI::getInstance(true)
        ->set('CacheManager', CacheManager::class, true)
        ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
        ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true)
        ->set('ModuleManager', fn($c) => new ModuleManager($c->get('ConfigManager')), true)
        ->set('I18n', fn($c) => new I18n($c->get('ConfigManager')), true)
        ->set('DataSourceResolver', fn() => new DataSourceResolver(['file' => 'File', 'doctrine' => 'Doctrine']), true)
        ->set('UnifiedEntityManager', fn($c) => new UnifiedEntityManager($c->get('DataSourceResolver')), true)
        ->set('EmailService', fn() => new EmailService(), true)
        ->set('AuthService', fn() => new class {
            public function getCurrentUser(): AuthUser { return new AuthUser(['id' => '7', 'user_name' => 'max.muster', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND]); }
        }, true)
    ;
    DI::getCacheManager()->setCacheDir($base . '/var/cache');

    return DI::getUnifiedEntityManager();
};
$em = $wireDi();

$db = DriverManager::getConnection(['driver' => 'pdo_mysql', 'dbname' => $dbName] + [
    'host' => $credentials['host'], 'user' => $credentials['user'], 'password' => $credentials['password'],
]);
$tables = fn() => array_map('strtolower', $db->createSchemaManager()->listTableNames());
$count  = fn(string $where = '1=1') => (int) $db->fetchOne("SELECT COUNT(*) FROM mail_outbox WHERE {$where}");
$ts     = fn(string $modify) => (new DateTimeImmutable($modify))->format('Y-m-d H:i:s');

/** Runs one z77-db command in-process on a fresh application; returns [exit code, output]. */
$run = static function (array $input): array {
    $app = MigrationsApplication::create(
        DoctrineBootstrap::buildEntityManager(),
        MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder()),
        DI::getCacheManager()->generatedPhp(),
        MigrationDirectories::missing(DI::getModuleManager(), DI::getFileFinder())
    );
    $app->setAutoExit(false);
    $out  = new BufferedOutput();
    $code = $app->run(new ArrayInput($input + ['--no-interaction' => true]), $out);

    return [$code, $out->fetch()];
};

// ── A. the migration ─────────────────────────────────────────────────────

echo "A. Migration (z77-db migrate on an empty database)\n";
$config = DI::getModuleManager()->getModuleConfig('mailing');
check('A0 the module config announces exactly ONE Doctrine entity, no routes, no view area', $config?->get('doctrineEntities') === [OutboxMail::class]
    && $config->get('viewArea') === false && !$config->has('defaultGroup'));
$dirs = MigrationDirectories::collect(DI::getModuleManager(), DI::getFileFinder());
check('A1 the module\'s res/migrations is collected under Z77\\Module\\Mailing\\Migrations', ($dirs['Z77\\Module\\Mailing\\Migrations'] ?? '') === $package . '/res/migrations');
check('A2 the database is empty', $tables() === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A3 migrate exits 0' . ($code !== 0 ? " — got {$code}: " . trim($out) : ''), $code === 0);
$executed = $db->fetchFirstColumn('SELECT version FROM schema_migration');
check('A4 Version20261022170000 ran', in_array('Z77\\Module\\Mailing\\Migrations\\Version20261022170000', $executed, true), implode(', ', $executed));
check('A4b Version20261023100000 ran (the sender identity, 2026-10-23)', in_array('Z77\\Module\\Mailing\\Migrations\\Version20261023100000', $executed, true), implode(', ', $executed));
$info = $db->fetchAssociative('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, 'mail_outbox']);
check('A5 mail_outbox is utf8mb4_unicode_ci and InnoDB although the database default is general_ci',
    ($info['TABLE_COLLATION'] ?? '') === 'utf8mb4_unicode_ci' && ($info['ENGINE'] ?? '') === 'InnoDB');
$types = $db->fetchAllKeyValue('SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$dbName, 'mail_outbox']);
check('A6 html_body MEDIUMTEXT, text_body / attachments TEXT, recipient_email VARCHAR(254), the delivery columns',
    ($types['html_body'] ?? '') === 'mediumtext' && ($types['text_body'] ?? '') === 'text' && ($types['attachments'] ?? '') === 'text'
    && ($types['recipient_email'] ?? '') === 'varchar(254)' && isset($types['next_attempt_at'], $types['sent_at'], $types['last_error'], $types['attempts'], $types['batch_id'], $types['source'], $types['language'], $types['reply_to'], $types['template']),
    json_encode($types));
$indexes = $db->fetchFirstColumn('SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME', [$dbName, 'mail_outbox']);
check('A7 the three indexes (state+due, batch, source) and the primary key', $indexes === ['idx_mail_outbox_batch', 'idx_mail_outbox_source', 'idx_mail_outbox_state_due', 'PRIMARY'], implode(', ', $indexes));
$fks = $db->fetchFirstColumn('SELECT REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$dbName, 'mail_outbox']);
check('A8 NO foreign key — the source reference is opaque', $fks === []);
[$code, $out] = $run(['command' => 'migrate']);
check('A9 a second migrate is a no-op', $code === 0 && str_contains($out, 'Already at the latest version'));
[$code, $out] = $run(['command' => 'diff', '--namespace' => 'Z77\\Module\\Mailing\\Migrations']);
check('A10 diff after migrate reports NO change — mapping and migration agree', $code !== 0 && str_contains($out, 'No changes detected') && count(glob($package . '/res/migrations/Version*.php')) === 2, trim($out));
check('A11 the migrations are expand-only: no DROP outside down()', (function () use ($package): bool {
    foreach (glob($package . '/res/migrations/Version*.php') as $file) {
        $s = (string) file_get_contents($file);
        if (substr_count(substr($s, 0, (int) strpos($s, 'function down')), 'DROP') !== 0) { return false; }
    }
    return true;
})());
check('A12 the sender columns (2026-10-23): sender_user_id INT NULL, sender_name VARCHAR(190) and sender_email VARCHAR(254) NOT NULL',
    (function () use ($db, $dbName): bool {
        $c = $db->fetchAllAssociativeIndexed('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ?', [$dbName, 'mail_outbox', 'sender%']);
        return ($c['sender_user_id']['IS_NULLABLE'] ?? '') === 'YES' && str_starts_with((string) ($c['sender_user_id']['COLUMN_TYPE'] ?? ''), 'int')
            && ($c['sender_name']['COLUMN_TYPE'] ?? '') === 'varchar(190)' && ($c['sender_name']['IS_NULLABLE'] ?? '') === 'NO'
            && ($c['sender_email']['COLUMN_TYPE'] ?? '') === 'varchar(254)' && ($c['sender_email']['IS_NULLABLE'] ?? '') === 'NO';
    })());

// ── B. settings ──────────────────────────────────────────────────────────

echo "B. Settings (one place, a project's deviation only)\n";
$s = OutboxSettings::fromModules(DI::getModuleManager());
check('B1 the defaults come from mailingConfig → outbox: 10 per slice, 60 s pause, 3 attempts, 5 / 15 / 60 min',
    $s->batchSize === 10 && $s->pauseSeconds === 60 && $s->maxAttempts === 3 && $s->retryMinutes === [5, 15, 60]
    && $config->get('outbox') === OutboxSettings::DEFAULTS);
$write('override/module/mailing/src/App/Config/outboxConfig.inc.php', "<?php return ['batchSize' => 20];");
$em = $wireDi();
$s =OutboxSettings::fromModules(DI::getModuleManager());
check('B2 a project\'s outboxConfig.inc.php records only the deviation, the rest stays as shipped', $s->batchSize === 20 && $s->pauseSeconds === 60 && $s->maxAttempts === 3);
@unlink($override . '/src/App/Config/outboxConfig.inc.php');
$em = $wireDi();
$c = OutboxSettings::fromArray(['batchSize' => 0, 'pauseSeconds' => -5, 'maxAttempts' => 99, 'retryMinutes' => ['x', 0]]);
check('B3 values clamp instead of stopping the mail (batch ≥ 1, pause ≥ 0, attempts ≤ 20, a minute ≥ 1)', $c->batchSize === 1 && $c->pauseSeconds === 0 && $c->maxAttempts === 20 && $c->retryMinutes === [1]);
$d = new OutboxSettings();
check('B4 retryDelaySeconds(): 5 min after the 1st failure, 15 after the 2nd, 60 after the 3rd, the last repeats',
    $d->retryDelaySeconds(1) === 300 && $d->retryDelaySeconds(2) === 900 && $d->retryDelaySeconds(3) === 3600 && $d->retryDelaySeconds(7) === 3600);
check('B5 rateText(): the pace in German from the same values — what a send action tells after queueing (owner 2026-10-23)',
    $d->rateText() === '10 pro Minute' && (new OutboxSettings(20, 300))->rateText() === '20 alle 5 Minuten'
    && (new OutboxSettings(5, 30))->rateText() === '5 alle 30 Sekunden' && (new OutboxSettings(50, 0))->rateText() === '50 je Durchgang');

// ── C. attachment providers ──────────────────────────────────────────────

echo "C. Attachment-provider registry\n";
$r = AttachmentReference::parse('invoice-pdf:123');
check('C1 a reference is type:id', $r->type === 'invoice-pdf' && $r->id === '123' && (string) $r === 'invoice-pdf:123');
check('C2 a malformed reference is refused (no type, upper case, whitespace, empty id)',
    caught(fn() => AttachmentReference::parse('123'), \InvalidArgumentException::class) !== null
    && caught(fn() => AttachmentReference::parse('Invoice:1'), \InvalidArgumentException::class) !== null
    && caught(fn() => AttachmentReference::parse('invoice-pdf:1 2'), \InvalidArgumentException::class) !== null
    && caught(fn() => AttachmentReference::parse('invoice-pdf:'), \InvalidArgumentException::class) !== null);
check('C3 bad declarations fail at registration naming the problem: a list, a missing class, not a provider',
    str_contains((string) caught(fn() => new AttachmentProviders([TestPdfAttachment::class]), \RuntimeException::class)?->getMessage(), 'type =>')
    && str_contains((string) caught(fn() => new AttachmentProviders(['x-pdf' => 'Nope\\Missing']), \RuntimeException::class)?->getMessage(), 'non-existent')
    && str_contains((string) caught(fn() => new AttachmentProviders(['x-pdf' => NotAProvider::class]), \RuntimeException::class)?->getMessage(), 'does not implement')
    && str_contains((string) caught(fn() => new AttachmentProviders(['X_PDF' => TestPdfAttachment::class]), \RuntimeException::class)?->getMessage(), 'kebab'));
$providers = new AttachmentProviders(['test-pdf' => TestPdfAttachment::class]);
check('C4 refusal(): null for a known type and an accepted id; German reasons otherwise',
    $providers->refusal('test-pdf:12') === null && str_contains((string) $providers->refusal('other:1'), 'kein Modul')
    && str_contains((string) $providers->refusal('test-pdf:999'), 'nicht gefunden') && str_contains((string) $providers->refusal('nonsense'), 'keine gültige'));
check('C5 the module itself registers no type; fromModules() is empty', AttachmentProviders::fromModules(DI::getModuleManager())->types() === []);
$write('override/module/mailing/src/App/Config/mailAttachmentsConfig.inc.php', '<?php return [\'test-pdf\' => \\TestPdfAttachment::class];');
$em = $wireDi();
check('C6 a project\'s mailAttachmentsConfig.inc.php adds a type without copying a module config', AttachmentProviders::fromModules(DI::getModuleManager())->types() === ['test-pdf']);
@unlink($override . '/src/App/Config/mailAttachmentsConfig.inc.php');
$em = $wireDi();
// Step 3 of the mail plan (2026-10-22): module-debtor registers the booked document's PDF.
$debtorDeclared = (require $root . '/packages/module-debtor/src/App/Config/debtorConfig.inc.php')['mailAttachments'] ?? null;
$debtorRegistry = is_array($debtorDeclared) ? new AttachmentProviders($debtorDeclared) : null;
check('C7 module-debtor declares the type `invoice-pdf` under mailAttachments — a provider the registry accepts (implements AttachmentProvider, no-arg constructor); module-mailing names no document module',
    $debtorRegistry?->types() === ['invoice-pdf'] && ($debtorDeclared['invoice-pdf'] ?? '') === 'Z77\\Module\\Debtor\\Mail\\InvoicePdfAttachment'
    && !str_contains(implode('', array_map('file_get_contents', glob($package . '/src/*/*.php'))), 'Module\\Debtor'));

// ── D. queueing ──────────────────────────────────────────────────────────

echo "D. Queueing (validation, batch, job, template)\n";
$jobs    = new JobQueue($em);
$service = new OutboxService($em, $providers, DI::getEmailService(), $jobs);
$ok      = OutboxDraft::html('anna@example.ch', 'Herbstangebot', '<p>Hallo</p>', 'Anna Beispiel');
$problems = fn(OutboxDraft $d) => $service->problems($d);
check('D1 a valid draft has no problem', $problems($ok) === []);
check('D2 the Message rules are asked before anything is stored: address syntax, CR/LF in subject and name, empty subject, no content, Reply-To, language, source',
    $problems($ok->to('not-an-address')) === ['Ungültige Empfängeradresse «not-an-address».']
    && $problems($ok->to('')) === ['Empfängeradresse fehlt.']
    && $problems(OutboxDraft::html('a@example.ch', "Hi\r\nBcc: x@evil.test", '<p>x</p>')) === ['Betreff darf keinen Zeilenumbruch enthalten.']
    && $problems($ok->to('a@example.ch', "Max\nBcc: x@evil.test")) === ['Empfängername darf keinen Zeilenumbruch enthalten.']
    && $problems(OutboxDraft::html('a@example.ch', '  ', '<p>x</p>')) === ['Betreff fehlt.']
    && $problems(OutboxDraft::html('a@example.ch', 'Leer', '   ')) === ['Die E-Mail hat keinen Inhalt.']
    && $problems($ok->withReplyTo('nope')) === ['Ungültige Antwortadresse (Reply-To) «nope».']
    && $problems($ok->withLanguage('deutsch')) === ['Ungültige Sprache «deutsch».']
    && $problems($ok->withSource("invoice:1\n")) === ['Ungültige Quellenangabe.'],
    json_encode([$problems($ok->to('not-an-address')), $problems(OutboxDraft::html('a@example.ch', "Hi\r\nBcc: x@evil.test", '<p>x</p>'))], JSON_UNESCAPED_UNICODE));
check('D3 attachments: an unknown type and an id the provider does not accept are refused at queue time',
    $problems($ok->withAttachments(['other-pdf:1'])) !== [] && $problems($ok->withAttachments(['test-pdf:950'])) !== [] && $problems($ok->withAttachments(['test-pdf:1'])) === []);
$e = caught(fn() => $service->queueBatch([$ok, $ok->to('b@example.ch'), $ok->to('broken')], 'max.muster'), InvalidOutboxMailException::class);
check('D4 a batch is all-or-nothing: one bad draft (index 2) refuses the batch, nothing written, no job queued',
    $e !== null && array_keys($e->errors) === [2] && str_starts_with($e->getMessage(), 'Mail 3: ') && $count() === 0 && !$jobs->hasOpenEntry(OutboxService::JOB_KEY));
$batch = $service->queueBatch([$ok, $ok->to('b@example.ch', 'Bea')->withSource('newsletter:5')], 'max.muster');
$em = $wireDi();
check('D5 a valid batch: two queued rows, ONE batch id of 32 hex, due now, created_by the actor, attempts 0',
    count($batch) === 2 && $count("state = 'queued'") === 2 && (int) $db->fetchOne('SELECT COUNT(DISTINCT batch_id) FROM mail_outbox') === 1
    && preg_match('/^[0-9a-f]{32}$/', $batch[0]->getBatchId()) === 1 && $batch[0]->getBatchId() === $batch[1]->getBatchId()
    && $count("created_by = 'max.muster' AND attempts = 0 AND next_attempt_at IS NOT NULL") === 2 && $batch[1]->getSource() === 'newsletter:5');
check('D6 … and the job is queued exactly once (a second batch adds no second entry)', (function () use ($service, $ok, $jobs): bool {
    $open1 = $jobs->hasOpenEntry(OutboxService::JOB_KEY);
    $service->queue($ok->to('c@example.ch'), 'max.muster');
    $entries = array_filter($jobs->all(), fn($r) => $r->getJobKey() === OutboxService::JOB_KEY);
    return $open1 && count($entries) === 1 && reset($entries)->getCreatedBy() === 'max.muster';
})());
$tpl = $service->queue(OutboxDraft::template('d@example.ch', 'Ihr Angebot', 'emails/outboxTest', 'Z77\\Module\\Mailing', ['name' => '<Anna & Co>'])->withLanguage('de'), 'max.muster');
check('D7 a template draft is rendered AT QUEUE TIME into the shared emails/layout (override-first), the context escaped; the reference kept',
    str_contains($tpl->getHtmlBody(), '<!DOCTYPE html>') && str_contains($tpl->getHtmlBody(), 'Guten Tag &lt;Anna &amp; Co&gt;') && str_contains($tpl->getHtmlBody(), '<title>Ihr Angebot</title>')
    && $tpl->getTemplate() === 'Z77\\Module\\Mailing:emails/outboxTest' && $tpl->getTextBody() === '' && $tpl->getLanguage() === 'de');
$txt = $service->queue(OutboxDraft::text('e@example.ch', 'Nur Text', "Zeile 1\nZeile 2"), 'max.muster');
check('D8 a text-only draft stores no HTML', $txt->getHtmlBody() === '' && $txt->getTextBody() === "Zeile 1\nZeile 2");
check('D9 the service never sends: nothing left the outbox state queued', $count("state <> 'queued'") === 0 && $count() === 5);
$db->executeStatement('DELETE FROM mail_outbox');

// ── E. the job slice ─────────────────────────────────────────────────────

echo "E. The job slice (fake transport)\n";
$em        = $wireDi();
$transport = new FakeTransport();
$mailer    = new Mailer($transport, 'outbox@example.ch', 'Z77 Test');
$settings  = new OutboxSettings();
$clockNow  = new \DateTimeImmutable();
$clock     = function () use (&$clockNow): \DateTimeImmutable { return $clockNow; };
$repo      = fn(): OutboxMailRepository => DI::getUnifiedEntityManager()->getRepository(OutboxMail::class);
$job       = function (?OutboxSettings $with = null, ?Mailer $withMailer = null) use (&$mailer, $settings, $providers, $clock, $repo): MailOutboxJob {
    $s = $with ?? $settings;
    return new MailOutboxJob(new OutboxSender($repo(), $providers, $withMailer ?? $mailer, $s), $repo(), $s, $clock);
};
$context = fn(?array $cursor = null, int $budget = 50) => new JobContext('mail-outbox', [], $cursor, 1, time() + $budget, new AuthUser(['id' => 'cron', 'user_name' => 'cron:mail-outbox', 'roles' => [AuthRole::CRON_JOB], 'realm' => AuthUser::REALM_CRON]));
$service   = new OutboxService(DI::getUnifiedEntityManager(), $providers, DI::getEmailService(), new JobQueue(DI::getUnifiedEntityManager()));
$drafts    = [];
for ($i = 1; $i <= 25; $i++) {
    $drafts[] = OutboxDraft::html("r{$i}@example.ch", 'Wochenpost', '<p>Hallo <strong>Nr. ' . $i . '</strong></p><p><a href="https://example.ch/x">Mehr</a></p>');
}
$service->queueBatch($drafts, 'max.muster');
// The rows were created «now»; the clock runs a second ahead so they are due.
$clockNow = new \DateTimeImmutable('+1 second');
$r1 = $job()->run($context());
check('E1 the first slice sends exactly batchSize (10) and asks again in pauseSeconds (60) — the old system\'s rhythm',
    count($transport->sent) === 10 && $r1->wantsMore() && $r1->getNotBefore() === 60 && $count("state = 'sent'") === 10 && $count("state = 'queued'") === 15,
    $r1->getNote() . ' / ' . $r1->getNotBefore());
check('E2 the cursor carries running totals only (state lives in mail_outbox)', $r1->getCursor() === ['sent' => 10, 'retry' => 0, 'failed' => 0] && str_contains($r1->getNote(), '10 sent'), json_encode($r1->getCursor()));
$first = $transport->sent[0];
check('E3 the sender is the mail config\'s identity (Mailer default From), the recipient the row\'s, longest-waiting first',
    $first['sender'] === 'outbox@example.ch' && $first['recipients'] === ['r1@example.ch'] && preg_match('/^From: .*<outbox@example\.ch>\r$/m', $first['data']) === 1,
    substr($first['data'], 0, 300));
check('E4 the text half is derived from the HTML at send time (multipart/alternative, the link kept)',
    str_contains($first['data'], 'multipart/alternative') && str_contains(quoted_printable_decode($first['data']), 'Mehr (https://example.ch/x)'));
$r2 = $job()->run($context($r1->getCursor()));
$r3 = $job()->run($context($r2->getCursor()));
check('E5 slice 2 sends the next 10, slice 3 the last 5 and is DONE (the queue entry finishes)',
    count($transport->sent) === 25 && $r2->wantsMore() && $r3->isDone() && $count("state = 'sent'") === 25 && str_contains($r3->getNote(), 'outbox empty (total 25 sent'), $r3->getNote());
check('E6 every sent row: sent_at set, next_attempt_at cleared, attempts 1, no error', $count("state = 'sent' AND sent_at IS NOT NULL AND next_attempt_at IS NULL AND attempts = 1 AND last_error = ''") === 25);
$transport->sent = [];
$service->queueBatch([$drafts[0], $drafts[1], $drafts[2]], 'max.muster');
$clockNow = new \DateTimeImmutable('+1 second');
$small = $job(new OutboxSettings(batchSize: 2, pauseSeconds: 30))->run($context());
check('E7 batchSize / pauseSeconds are honoured as configured (2 per slice, 30 s)', count($transport->sent) === 2 && $small->wantsMore() && $small->getNotBefore() === 30);
$noTime = $job()->run($context(null, 3));
check('E8 JobContext::hasTimeLeft() is asked before each mail: a slice without budget sends nothing and pauses', count($transport->sent) === 2 && $noTime->wantsMore() && $noTime->getNotBefore() === 60);
$job()->run($context());
$db->executeStatement('DELETE FROM mail_outbox');
$transport->sent = [];

// ── F. retries ───────────────────────────────────────────────────────────

echo "F. Retries, backoff, failed, interrupted, unconfigured\n";
$em = $wireDi();
$service->queue(OutboxDraft::html('retry@example.ch', 'Zustellung', '<p>x</p>'), 'max.muster');
$id = (int) $db->fetchOne("SELECT id FROM mail_outbox WHERE recipient_email = 'retry@example.ch'");
$transport->failWith = '451 4.7.1 Try again later';
$clockNow = new \DateTimeImmutable('+1 second');
$f1  = $job()->run($context());
$row = $db->fetchAssociative('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
$due = strtotime($row['next_attempt_at']) - $clockNow->getTimestamp();
check('F1 a transport error: queued again, attempts 1, the error recorded, next attempt in 5 min', $row['state'] === 'queued' && (int) $row['attempts'] === 1
    && $row['last_error'] === '451 4.7.1 Try again later' && $due === 300, json_encode($row));
check('F2 … and the job waits for the retry, never longer than MAX_IDLE_WAIT (300 s), never shorter than the pause', $f1->wantsMore() && $f1->getNotBefore() >= 60 && $f1->getNotBefore() <= MailOutboxJob::MAX_IDLE_WAIT, (string) $f1->getNotBefore());
$f1b = $job()->run($context());
check('F3 before it is due the mail is not touched (a slice sends nothing)', (int) $db->fetchOne('SELECT attempts FROM mail_outbox WHERE id = ?', [$id]) === 1 && $f1b->wantsMore());
$clockNow = $clockNow->modify('+301 seconds');
$job()->run($context());
$row = $db->fetchAssociative('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
check('F4 the 2nd failure waits 15 min', $row['state'] === 'queued' && (int) $row['attempts'] === 2 && strtotime($row['next_attempt_at']) - $clockNow->getTimestamp() === 900);
$clockNow = $clockNow->modify('+901 seconds');
$f3  = $job()->run($context());
$row = $db->fetchAssociative('SELECT * FROM mail_outbox WHERE id = ?', [$id]);
check('F5 the 3rd failure (maxAttempts 3) → failed, out of the queue; the job is done', $row['state'] === 'failed' && (int) $row['attempts'] === 3 && $row['next_attempt_at'] === null && $f3->isDone(), json_encode($row) . ' / ' . $f3->getNote());
$transport->failWith = null;
// A row written around the service (an import, a hand edit): an address Message refuses.
$db->executeStatement("INSERT INTO mail_outbox (created_at, created_by, recipient_email, recipient_name, reply_to, subject, html_body, text_body, template, language, attachments, source, batch_id, state, attempts, last_error, next_attempt_at)
    VALUES ('" . $ts('-1 minute') . "', 'x', 'kaputt', '', '', 'Kaputt', '<p>x</p>', '', '', '', '[]', '', 'b', 'queued', 0, '', '" . $ts('-1 minute') . "')");
$job()->run($context());
check('F6 a mail that can never be sent fails at once (attempt 1), not after three tries', $db->fetchOne("SELECT CONCAT(state, '/', attempts) FROM mail_outbox WHERE recipient_email = 'kaputt'") === 'failed/1'
    && str_starts_with((string) $db->fetchOne("SELECT last_error FROM mail_outbox WHERE recipient_email = 'kaputt'"), 'Nicht versendbar: Invalid e-mail address'));
$service->queue(OutboxDraft::html('lost@example.ch', 'Abgebrochen', '<p>x</p>'), 'max.muster');
$db->executeStatement("UPDATE mail_outbox SET state = 'sending' WHERE recipient_email = 'lost@example.ch'");
$transport->sent = [];
$job()->run($context());
check('F7 a row still «sending» at the start of a slice (its process died) is FAILED with the question — never re-sent unasked',
    $db->fetchOne("SELECT state FROM mail_outbox WHERE recipient_email = 'lost@example.ch'") === 'failed'
    && $db->fetchOne("SELECT last_error FROM mail_outbox WHERE recipient_email = 'lost@example.ch'") === MailOutboxJob::INTERRUPTED && $transport->sent === []);
$service->queue(OutboxDraft::html('wait@example.ch', 'Warten', '<p>x</p>'), 'max.muster');
$clockNow = new \DateTimeImmutable('+1 second');
$un = $job(null, new Mailer(null))->run($context());
check('F8 sending not configured: again in 900 s, the waiting mail untouched (no attempt burnt)', $un->wantsMore() && $un->getNotBefore() === MailOutboxJob::UNCONFIGURED_WAIT
    && $db->fetchOne("SELECT CONCAT(state, '/', attempts) FROM mail_outbox WHERE recipient_email = 'wait@example.ch'") === 'queued/0');
$job()->run($context());

// ── G. attachments ───────────────────────────────────────────────────────

echo "G. Attachments resolved at send time\n";
$transport->sent = [];
TestPdfAttachment::$resolved = 0;
$service->queue(OutboxDraft::html('doc@example.ch', 'Ihre Rechnung', '<p>Anbei</p>')->withAttachments(['test-pdf:42'])->withSource('invoice:42'), 'max.muster');
check('G1 queueing stores the reference only — the provider is not asked for bytes yet', TestPdfAttachment::$resolved === 0
    && $db->fetchOne("SELECT attachments FROM mail_outbox WHERE recipient_email = 'doc@example.ch'") === '["test-pdf:42"]');
$clockNow = new \DateTimeImmutable('+1 second');
$job()->run($context());
$data = $transport->sent[0]['data'] ?? '';
check('G2 the job resolves it through the provider: multipart/mixed with the PDF attached', TestPdfAttachment::$resolved === 1 && str_contains($data, 'multipart/mixed')
    && str_contains($data, 'filename="Beleg 42.pdf"') && str_contains($data, base64_encode('%PDF-1.4 test 42')));
TestPdfAttachment::$broken = true;
$service->queue(OutboxDraft::html('doc2@example.ch', 'Ihre Rechnung', '<p>Anbei</p>')->withAttachments(['test-pdf:43']), 'max.muster');
$clockNow = new \DateTimeImmutable('+1 second');
$job()->run($context());
check('G3 a provider that throws is a RETRY of that mail (attempts 1, its message as the error), not a lost mail',
    $db->fetchOne("SELECT CONCAT(state, '/', attempts, '/', last_error) FROM mail_outbox WHERE recipient_email = 'doc2@example.ch'") === 'queued/1/PDF renderer unavailable');
TestPdfAttachment::$broken = false;
$db->executeStatement("UPDATE mail_outbox SET next_attempt_at = '" . $ts('-1 minute') . "' WHERE recipient_email = 'doc2@example.ch'");
$clockNow = new \DateTimeImmutable('+1 second');
$job()->run($context());
check('G4 … and goes out once the provider is back', $db->fetchOne("SELECT state FROM mail_outbox WHERE recipient_email = 'doc2@example.ch'") === 'sent');

// ── H. cancel / retry ────────────────────────────────────────────────────

echo "H. Cancel and retry (conditional transitions)\n";
$em      = $wireDi();
$service = new OutboxService(DI::getUnifiedEntityManager(), $providers, DI::getEmailService(), new JobQueue(DI::getUnifiedEntityManager()));
$c       = $service->queue(OutboxDraft::html('cancel@example.ch', 'Storno', '<p>x</p>'), 'max.muster');
$cid     = (int) $c->getId();
check('H1 cancel() a waiting mail → cancelled, out of the queue', $service->cancel($cid)->getState() === 'cancelled' && $db->fetchOne('SELECT next_attempt_at FROM mail_outbox WHERE id = ?', [$cid]) === null);
check('H2 cancel() twice / of a sent mail is refused (OutboxStateException, German)', caught(fn() => $service->cancel($cid), OutboxStateException::class) !== null
    && str_contains((string) caught(fn() => $service->cancel((int) $db->fetchOne("SELECT id FROM mail_outbox WHERE state = 'sent' LIMIT 1")), OutboxStateException::class)?->getMessage(), 'wartende'));
$failedId = (int) $db->fetchOne("SELECT id FROM mail_outbox WHERE recipient_email = 'retry@example.ch'");
$again    = $service->retry($failedId, 'max.muster');
check('H3 retry() of a failed mail → queued, due now, a full new round (attempts 0), the last error still shown', $again->getState() === 'queued' && $again->getAttempts() === 0
    && $again->getLastError() === '451 4.7.1 Try again later' && $again->getNextAttemptAt() !== null);
check('H4 retry() of a queued mail is refused', caught(fn() => $service->retry($failedId, 'max.muster'), OutboxStateException::class) !== null);
$repoNow = $repo();
$c2      = $service->queue(OutboxDraft::html('race@example.ch', 'Rennen', '<p>x</p>'), 'max.muster');
$service->cancel((int) $c2->getId());
check('H5 the job cannot claim a mail cancelled after its id was read (the row\'s state is the lock)', !$repoNow->claim((int) $c2->getId(), new \DateTimeImmutable('+1 minute'))
    && (new OutboxSender($repoNow, $providers, $mailer, $settings))->send((int) $c2->getId(), new \DateTimeImmutable('+1 minute')) === OutboxSender::SKIPPED);
$clockNow = new \DateTimeImmutable('+1 second');
$job()->run($context());

// ── I. the screen ────────────────────────────────────────────────────────

echo "I. The screen — the fragment rendered through its trait with a host double\n";
$em = $wireDi();
$useRequest = function (array $get, ?array $post = null, bool $fetch = false): void {
    $GLOBALS['z77Get']   = $get;
    $GLOBALS['z77Post']  = $post ?? [];
    $GLOBALS['z77Fetch'] = $fetch;
    DI::getInstance()->set('Request', fn() => new class {
        public function getGetParameter(string $p): mixed { return $GLOBALS['z77Get'][$p] ?? null; }
        public function getPostParameters(): array { return $GLOBALS['z77Post']; }
        public function isPost(): bool { return $GLOBALS['z77Post'] !== []; }
        public function getMode(): \Z77\Core\Http\RequestMode { return $GLOBALS['z77Fetch'] ? \Z77\Core\Http\RequestMode::Fetch : \Z77\Core\Http\RequestMode::Page; }
    }, true);
};
$host = function () {
    return new class {
        use OutboxControllerTrait { listAction as public; actionsAction as public; detailAction as public; retryAction as public; cancelAction as public; startAction as public; }
        public array $context = [];
        public object $layoutManager;
        public object $messageService;
        public ?string $redirectedTo = null;
        public ?string $error = null;
        public function __construct()
        {
            $this->layoutManager  = new class {
                public array $sections = [];
                public function addPartials(string $name, string $path, string $ns, string $section = 'main'): void { $this->sections[$section][] = $path . '/' . $name; }
                public function removeSection(string $section): void { unset($this->sections[$section]); $this->sections['removed'][] = $section; }
            };
            $this->messageService = new class {
                public array $flashes = [];
                public function pushFlash(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
                public function pushFlashAfterRedirect(string $type, string $message): void { $this->flashes[] = [$type, $message]; }
            };
        }
        protected function em() { return DI::getUnifiedEntityManager(); }
        protected function html(array $context = []): \Z77\Core\Http\Response\HtmlResponse { $this->context = $context; return new \Z77\Core\Http\Response\HtmlResponse(null, $context); }
        protected function fetch(): \Z77\Core\Http\Response\FetchResponse { return new \Z77\Core\Http\Response\FetchResponse(); }
        protected function fetchError(string $text): \Z77\Core\Http\Response\FetchResponse { $this->error = $text; return (new \Z77\Core\Http\Response\FetchResponse())->setStatus('error'); }
        protected function redirect(string $url, int $status = 302): \Z77\Core\Http\Response\RedirectResponse { $this->redirectedTo = $url; return new \Z77\Core\Http\Response\RedirectResponse($url, $status); }
    };
};
$renderer = new class([
    'Z77\\Module\\Mailing' => $package . '/res/view/templates/',
    'Z77\\Shared'          => $root . '/packages/kernel/shared/res/view/templates/',
]) {
    public function __construct(private array $dirs) {}
    public function partial(string $path, array $context = [], ?string $ns = null): string
    {
        $dir = $this->dirs[$ns ?? 'Z77\\Module\\Mailing'] ?? $this->dirs['Z77\\Module\\Mailing'];
        return (function (string $z77TplPath, array $z77TplContext) { extract($z77TplContext, EXTR_SKIP); ob_start(); require $z77TplPath; return ob_get_clean(); })->call($this, $dir . $path . '.tpl.php', $context);
    }
};
$render   = fn(string $tpl, array $context) => $renderer->partial("Backend/OutboxController/" . $tpl, $context);
$commands = fn($answer) => $answer instanceof \Z77\Core\Http\Response\FetchResponse ? (fn() => $this->commands)->call($answer) : [];

$layout = OutboxLayout::config();
check('I1 the layout pins the body to the fragment\'s listAction template in module-mailing', ($layout['levelElements']['body']['main'][0] ?? []) === ['nameSpace' => 'Z77\\Module\\Mailing', 'path' => 'Backend/OutboxController', 'name' => 'listAction']);
// Fixtures for the screen: one of each state, a hostile subject and body.
$db->executeStatement('DELETE FROM mail_outbox');
$hostile = $service->queue(OutboxDraft::html('evil@example.ch', 'Hallo <b>fett</b> & "zitiert"', '<p>Hi</p><script>alert(1)</script><img src="https://tracker.test/p.gif">', 'Eve <x>'), 'max.muster');
$db->executeStatement("UPDATE mail_outbox SET state = 'failed', attempts = 3, last_error = '550 <mailbox> unknown', next_attempt_at = NULL WHERE id = ?", [$hostile->getId()]);
$queued  = $service->queue(OutboxDraft::html('q@example.ch', 'Wartet', '<p>q</p>')->withSource('newsletter:5'), 'max.muster');
$sent    = $service->queue(OutboxDraft::html('s@example.ch', 'Gesendet', '<p>s</p>'), 'max.muster');
$db->executeStatement("UPDATE mail_outbox SET state = 'sent', attempts = 1, sent_at = '" . $ts('now') . "', next_attempt_at = NULL WHERE id = ?", [$sent->getId()]);
$em = $wireDi();

$useRequest([]);
$h = $host();
$h->listAction();
$html = $render('listAction', $h->context);
check('I2 list, default view «Wartend»: only the waiting mail; the view tabs in the toolbar (hc2); the action cell EMPTY',
    $h->context['state']->extra('view') === 'queued' && count($h->context['mails']) === 1 && $h->context['mails'][0]->getRecipientEmail() === 'q@example.ch'
    && ($h->layoutManager->sections['hc2'] ?? []) === ['Backend/OutboxController/toolbar'] && !isset($h->layoutManager->sections['hc1']));
$tb = $renderer->partial('Backend/OutboxController/toolbar', $h->context);
check('I3 the four tabs, «Wartend» active, the others carry ?view=', substr_count($tb, 'be-viewtabs__tab') === 4 && str_contains($tb, 'href="/backend/service/outbox/list" aria-current="page">Wartend')
    && str_contains($tb, '?view=sent') && str_contains($tb, '?view=failed') && str_contains($tb, '?view=all'));
check('I4 the list is a fetch region with the kernel\'s head/find partials, the row opens a WINDOW and its ⋮ a CONTEXT MENU',
    str_contains($html, 'data-fetch-region="outbox-find-list"') && str_contains($html, 'id="outbox-find"')
    && str_contains($html, 'data-window-open="/backend/service/outbox/detail?id=' . $queued->getId() . '"')
    && str_contains($html, 'aria-haspopup="menu" aria-expanded="false" data-menu-open="/backend/service/outbox/actions?id=' . $queued->getId() . '"')
    && str_contains($html, 'for="outbox-find-f_recipient"') && str_contains($html, 'newsletter:5'));
check('I5 the mail-config band (no config/client/mail.inc.php yet) and the cron band (no heartbeat) say why nothing moves; the job is queued, so no job band',
    $h->context['mailReady'] === false && str_contains($html, 'E-Mail-Versand ist nicht eingerichtet') && $h->context['cronStale'] === true && str_contains($html, 'noch nie gelaufen')
    && $h->context['jobQueued'] === true && !str_contains($html, 'der Versand ist nicht eingereiht'));
// Sending configured (the dev box's FileTransport), the job entry gone (removed by hand), a fresh heartbeat.
$write('config/client/mail.inc.php', "<?php return ['enabled' => true, 'transport' => 'file', 'fromAddress' => 'noreply@example.ch', 'fromName' => 'Test', 'outbox' => '" . $base . "/data/framework/mail/outbox'];");
$write('data/framework/jobs/last-pass.json', json_encode(['at' => date(DATE_ATOM), 'summary' => []]));
$em = $wireDi();
foreach ((new JobQueue(DI::getUnifiedEntityManager()))->all() as $entry) {
    (new JobQueue(DI::getUnifiedEntityManager()))->delete($entry);
}
$useRequest([]);
$h = $host();
$h->listAction();
$html = $render('listAction', $h->context);
check('I6 configured + fresh heartbeat + no job entry: only the job band, with the green «Versand starten» (a fetch POST) — the pending step made visible',
    $h->context['mailReady'] === true && $h->context['cronStale'] === false && $h->context['jobQueued'] === false
    && str_contains($html, 'der Versand ist nicht eingereiht') && str_contains($html, 'data-fetch-post="/backend/service/outbox/start"') && str_contains($html, 'be-btn be-btn--confirm')
    && !str_contains($html, 'nicht eingerichtet') && !str_contains($html, 'Cron-Lauf'));
$useRequest(['view' => 'failed']);
$h = $host();
$h->listAction();
$html = $render('listAction', $h->context);
check('I7 view «Fehlgeschlagen»: the hostile subject, name and error are TEXT (escaped), no tag of the mail reaches the page',
    count($h->context['mails']) === 1 && str_contains($html, 'Hallo &lt;b&gt;fett&lt;/b&gt; &amp; &quot;zitiert&quot;') && !str_contains($html, '<b>fett</b>')
    && str_contains($html, '550 &lt;mailbox&gt; unknown') && str_contains($html, 'Eve &lt;x&gt;') && stripos($html, '<script') === false && !str_contains($html, 'tracker.test'));
$useRequest(['view' => 'all', 'f_subject' => 'Gesendet']);
$h = $host();
$h->listAction();
check('I8 view «Alle» + a subject search narrows in the database', count($h->context['mails']) === 1 && $h->context['mails'][0]->getState() === 'sent' && $h->context['paging']->total === 1);
$useRequest([], null, true);
$h = $host();
$h->listAction();
check('I9 a FETCH of the region adds no toolbar slot', !isset($h->layoutManager->sections['hc2']));

// One send run as its own list (owner 2026-10-23): `?batch=` — the «Postausgang ansehen» of a send action.
$runRows  = $service->queueBatch([OutboxDraft::html('r1@example.ch', 'Lauf 1', '<p>1</p>'), OutboxDraft::html('r2@example.ch', 'Lauf 2', '<p>2</p>')], 'max.muster');
$runBatch = $runRows[0]->getBatchId();
$db->executeStatement("UPDATE mail_outbox SET state = 'sent', attempts = 1, sent_at = '2026-10-23 08:05:00', next_attempt_at = NULL WHERE id = ?", [$runRows[0]->getId()]);
$em = $wireDi();
$useRequest(['view' => 'all', 'batch' => $runBatch]);
$h = $host();
$h->listAction();
$html = $render('listAction', $h->context);
$tb   = $renderer->partial('Backend/OutboxController/toolbar', $h->context);
check('I9b `?batch=` (a pattern extra, listing.md): exactly the run\'s two mails in «Alle»; the chip «Versand #<8 hex>» with a × back to the list without the batch; the tabs keep the batch; the sent row says when (status «gesendet 23.10. 08:05»); batchUrl() is the link a send action builds',
    array_map(fn($m) => $m->getRecipientEmail(), $h->context['mails']) === ['r2@example.ch', 'r1@example.ch'] && $h->context['paging']->total === 2
    && str_contains($html, 'data-outbox-batch="' . $runBatch . '"') && str_contains($html, 'Versand #' . substr($runBatch, 0, 8))
    && str_contains($html, 'href="/backend/service/outbox/list?view=all" aria-label="Filter')
    && str_contains($tb, 'href="/backend/service/outbox/list?view=sent&amp;batch=' . $runBatch . '"') && str_contains($tb, 'href="/backend/service/outbox/list?batch=' . $runBatch . '"')
    && str_contains($html, 'gesendet</span> <small class="be-list__cell--muted">23.10. 08:05</small>')
    && OutboxListing::batchUrl($runBatch) === '/backend/service/outbox/list?view=all&batch=' . $runBatch && OutboxListing::batchLabel($runBatch) === 'Versand #' . substr($runBatch, 0, 8),
    json_encode(array_map(fn($m) => $m->getRecipientEmail(), $h->context['mails'])));
$useRequest(['view' => 'all', 'batch' => 'nicht-hex']);
$h = $host();
$h->listAction();
check('I9c a batch value off the pattern narrows nothing (every mail of «Alle», no chip) — never a 500', $h->context['state']->extra('batch') === '' && $h->context['paging']->total >= 5
    && !str_contains($render('listAction', $h->context), 'data-outbox-batch'));
$db->executeStatement('DELETE FROM mail_outbox WHERE batch_id = ?', [$runBatch]);
$em = $wireDi();

$menu = function (int $id) use ($host, $useRequest, $render): string {
    $useRequest(['id' => (string) $id], null, true);
    $h = $host();
    $h->actionsAction();
    return $render('actions', $h->context);
};
$mFailed = $menu((int) $hostile->getId());
$mQueued = $menu((int) $queued->getId());
$mSent   = $menu((int) $sent->getId());
check('I10 the menu is a .be-menu (no dialog header, no «Schliessen»): failed → Anzeigen + «Erneut senden»; queued → Anzeigen + «Abbrechen»; sent → Anzeigen only',
    str_contains($mFailed, 'class="be-menu" role="menu"') && !str_contains($mFailed, 'be-modal__header') && !str_contains($mFailed, 'Schliessen')
    && str_contains($mFailed, 'data-fetch-post="/backend/service/outbox/retry"') && !str_contains($mFailed, '/cancel')
    && str_contains($mQueued, 'data-fetch-post="/backend/service/outbox/cancel"') && !str_contains($mQueued, '/retry')
    && str_contains($mSent, 'Anzeigen') && !str_contains($mSent, 'data-fetch-post') && substr_count($mSent, 'role="menuitem"') === 1);
$useRequest(['id' => '999999'], null, true);
$h = $host();
$h->actionsAction();
check('I11 the menu of an unknown id is a fetch error, no menu', $h->error === 'E-Mail nicht gefunden');

$useRequest(['id' => (string) $hostile->getId()], null, true);
$h = $host();
$h->detailAction();
$win = $render('detail', $h->context);
check('I12 the window: its root declares mask, entity and title; the main section is swapped for the detail',
    str_contains($win, 'data-window="outbox-mail" data-window-entity="outbox-mail:' . $hostile->getId() . '"') && in_array('main', $h->layoutManager->sections['removed'] ?? [], true)
    && ($h->layoutManager->sections['main'] ?? []) === ['Backend/OutboxController/detail']);
check('I13 the HTML half only inside an iframe with an EMPTY sandbox via srcdoc (escaped) — no raw <script>/<img> of the mail in the backend page (Rule 7, ADR-053)',
    preg_match('/<iframe [^>]*sandbox=""[^>]*srcdoc="([^"]*)"/', $win, $m) === 1 && str_contains($m[1], '&lt;script&gt;alert(1)&lt;/script&gt;')
    && stripos(preg_replace('/srcdoc="[^"]*"/', '', $win), '<script') === false && stripos(preg_replace('/srcdoc="[^"]*"/', '', $win), '<img') === false
    && str_contains($win, 'referrerpolicy="no-referrer"') && !preg_match('/sandbox="[^"]*allow-/', $win));
check('I14 the text half is escaped text, marked as derived; the error and the headers are text',
    str_contains($win, '(aus dem HTML abgeleitet)') && str_contains($win, '<pre') && str_contains($win, '550 &lt;mailbox&gt; unknown') && str_contains($win, 'Eve &lt;x&gt; &lt;evil@example.ch&gt;')
    && str_contains($win, 'aus der E-Mail-Konfiguration der Installation'));
$useRequest(['id' => '999999']);
$h = $host();
$h->detailAction();
check('I15 the detail of an unknown id in page mode redirects to the list (303) with an error flash', $h->redirectedTo === '/backend/service/outbox/list' && ($h->messageService->flashes[0][0] ?? '') === 'error');

$useRequest([], ['id' => (string) $hostile->getId()], true);
$h = $host();
$answer = $h->retryAction();
check('I16 «Erneut senden» (POST): the mail is queued again, a success flash, the list region reloads', $db->fetchOne('SELECT state FROM mail_outbox WHERE id = ?', [$hostile->getId()]) === 'queued'
    && ($h->messageService->flashes[0][0] ?? '') === 'success' && $commands($answer) === [['action' => 'refresh-region', 'name' => 'outbox-find-list']]);
$h = $host();
$h->retryAction();
check('I17 … a second «Erneut senden» of the same (now queued) mail is a fetch error with the German reason', str_contains((string) $h->error, 'fehlgeschlagene'));
$useRequest([], ['id' => (string) $queued->getId()], true);
$h = $host();
$answer = $h->cancelAction();
check('I18 «Abbrechen» (POST): cancelled, the region reloads', $db->fetchOne('SELECT state FROM mail_outbox WHERE id = ?', [$queued->getId()]) === 'cancelled' && $commands($answer) !== []);
foreach ((new JobQueue(DI::getUnifiedEntityManager()))->all() as $entry) {
    (new JobQueue(DI::getUnifiedEntityManager()))->delete($entry);
}
$useRequest([], [], true);
$h = $host();
$h->startAction();
check('I19 «Versand starten» queues the job when none waits; a second click says it is already queued', (new JobQueue(DI::getUnifiedEntityManager()))->hasOpenEntry('mail-outbox')
    && str_contains($h->messageService->flashes[0][1] ?? '', 'eingereiht') && (function () use ($host): bool {
        $h2 = $host(); $h2->startAction(); return ($h2->messageService->flashes[0][1] ?? '') === 'Der Versand ist bereits eingereiht';
    })());
$ref = new \ReflectionClass(OutboxControllerTrait::class);
check('I20 retry / cancel / start are POST-only fetch actions; the read actions are not', (function () use ($ref): bool {
    foreach (['retryAction', 'cancelAction', 'startAction'] as $m) {
        $attrs = array_map(fn($a) => $a->getName(), $ref->getMethod($m)->getAttributes());
        if (!in_array(\Z77\Shared\Attributes\Fetch::class, $attrs, true) || !in_array(\Z77\Shared\Attributes\HttpMethod::class, $attrs, true)) { return false; }
        if (($ref->getMethod($m)->getAttributes(\Z77\Shared\Attributes\HttpMethod::class)[0]->getArguments()[0] ?? '') !== 'POST') { return false; }
    }
    return $ref->getMethod('listAction')->getAttributes() === [] && $ref->getMethod('detailAction')->getAttributes() === [];
})());
check('I21 the mount URL is built in ONE place — OutboxListing::MOUNT (the trait\'s default base and the batch link other modules build, 2026-10-23)',
    substr_count((string) file_get_contents($ref->getFileName()), "'/backend/service/outbox'") === 0
    && substr_count((string) file_get_contents((new \ReflectionClass(OutboxListing::class))->getFileName()), "'/backend/service/outbox'") === 1);

// ── J. dashboard ─────────────────────────────────────────────────────────

echo "J. Dashboard (ADR-052)\n";
$em      = $wireDi();
$entries = fn() => iterator_to_array((function () { yield from (new MailingDashboard())->entries(new AuthUser(['id' => '1', 'user_name' => 'a', 'roles' => [AuthRole::ADMIN], 'realm' => AuthUser::REALM_BACKEND])); })(), false);
$db->executeStatement('DELETE FROM mail_outbox');
check('J1 an empty outbox contributes nothing', $entries() === []);
$service->queueBatch([OutboxDraft::html('x1@example.ch', 'A', '<p>a</p>'), OutboxDraft::html('x2@example.ch', 'B', '<p>b</p>')], 'max.muster');
check('J2 fresh waiting mails are no work for anyone (a batch of 500 takes ~50 min)', $entries() === []);
$db->executeStatement("UPDATE mail_outbox SET created_at = '" . $ts('-2 hours') . "' WHERE recipient_email = 'x1@example.ch'");
$db->executeStatement("UPDATE mail_outbox SET state = 'failed', next_attempt_at = NULL WHERE recipient_email = 'x2@example.ch'");
$list = $entries();
check('J3 «E-Mails fehlgeschlagen» (urgent, → view failed, count) and «E-Mails warten seit über einer Stunde» (todo, count) → Service › Postausgang',
    count($list) === 2 && $list[0]->severity === Severity::Urgent && $list[0]->label === 'E-Mails fehlgeschlagen' && $list[0]->count === 1
    && $list[0]->href() === '/backend/service/outbox/list?view=failed' && $list[1]->severity === Severity::Todo && $list[1]->count === 1 && $list[1]->href() === '/backend/service/outbox/list');
check('J4 the contributor is registered in the module config', $config->get('dashboard') === [MailingDashboard::class]);

// ── K. access, registration, seeds ───────────────────────────────────────

echo "K. Access, job registration, navigation seed\n";
$backendCfg = require $root . '/packages/module-backend/src/App/Config/backendConfig.inc.php';
$roles = array_map(fn(string $action) => AuthService::requiredRole($backendCfg['moduleRole'], $backendCfg['controllers'], 'service', 'OutboxController', Naming::toActionMethod($action)),
    ['list', 'actions', 'detail', 'retry', 'cancel', 'start']);
check('K1 every action of Service › Postausgang requires ADMIN (the backend module role, no deviation entry)', array_unique($roles) === [AuthRole::ADMIN] && !isset($backendCfg['controllers']['service']['OutboxController']), implode(',', $roles));
check('K2 the host controller and its layout config exist in module-backend and use the fragment', is_file($root . '/packages/module-backend/src/Ui/Controllers/Service/OutboxController.php')
    && str_contains((string) file_get_contents($root . '/packages/module-backend/src/Ui/Controllers/Service/OutboxController.php'), 'use OutboxControllerTrait;')
    && str_contains((string) file_get_contents($root . '/packages/module-backend/src/Ui/Config/Service/outboxControllerConfig.inc.php'), 'OutboxLayout::config()'));
$jobsReg = DI::getModuleManager()->getJobs();
check('K3 the job `mail-outbox` is registered by the module: MailOutboxJob, runAs cron, NO defaultSchedule (it is queued when there is work)',
    ($jobsReg['mail-outbox']['class'] ?? '') === MailOutboxJob::class && $jobsReg['mail-outbox']['runAs'] === AuthRole::CRON_JOB && $jobsReg['mail-outbox']['defaultSchedule'] === null
    && $jobsReg['mail-outbox']['module'] === 'mailing' && OutboxService::JOB_KEY === 'mail-outbox');
check('K4 the job is constructible without arguments (ADR-031)', (new \ReflectionClass(MailOutboxJob::class))->getConstructor()->getNumberOfRequiredParameters() === 0);
$seed = json_decode((string) file_get_contents($package . '/data/framework/routing/navigation.d/module-mailing.json'), true);
check('K5 the navigation seed: «Postausgang» keyed `outbox` under Service, the list route', $seed === [[
    'key' => 'outbox', 'name' => 'Postausgang', 'module' => 'backend', 'group' => 'service', 'controller' => 'outbox', 'action' => 'list',
    'slot' => '', 'active' => true, 'param' => '', 'sort_key' => 6, 'parent_key' => 'service',
]]);
check('K6 the package is registered in the monorepo (root composer.json requires z77/module-mailing)', isset(json_decode((string) file_get_contents($root . '/composer.json'), true)['require']['z77/module-mailing']));

// ── L. the developer path ────────────────────────────────────────────────

echo "L. Developer path: the job as the runner builds it, transport = 'file'\n";
$em = $wireDi();
$db->executeStatement("UPDATE mail_outbox SET state = 'cancelled', next_attempt_at = NULL WHERE state = 'queued'");
(new OutboxService(DI::getUnifiedEntityManager()))->queue(OutboxDraft::html('dev@example.ch', 'Lokaler Test', '<p>Nur in die Datei</p>'), 'max.muster');
$db->executeStatement("UPDATE mail_outbox SET next_attempt_at = '" . $ts('-1 minute') . "' WHERE recipient_email = 'dev@example.ch'");
$devResult = (new MailOutboxJob())->run($context());
$eml       = glob($base . '/data/framework/mail/outbox/*.eml') ?: [];
$emlData   = $eml === [] ? '' : (string) file_get_contents($eml[0]);
check('L0 `new MailOutboxJob()` (no doubles — module config, providers, Mailer::create()) writes the mail as .eml into the FileTransport outbox; nothing is delivered',
    $devResult->isDone() && count($eml) === 1 && str_contains(basename($eml[0]), 'dev-example.ch') && str_contains($emlData, 'Subject: Lokaler Test')
    && preg_match('/^From: .*<noreply@example\.ch>\r$/m', $emlData) === 1 && $db->fetchOne("SELECT state FROM mail_outbox WHERE recipient_email = 'dev@example.ch'") === 'sent', $devResult->getNote());

// ── N. the sender identity ───────────────────────────────────────────────

echo "N. The sender identity (owner 2026-10-23): the account sends, the person signs\n";
$em        = $wireDi();
$db->executeStatement('DELETE FROM mail_outbox');
$service   = new OutboxService(DI::getUnifiedEntityManager(), $providers, DI::getEmailService(), new JobQueue(DI::getUnifiedEntityManager()));
$peter     = new \Z77\Shared\Mail\SenderIdentity('Peter Ruepp', 'peter.ruepp@z77.ch');
$signed    = OutboxDraft::html('kunde@example.ch', 'Rechnung 1 — Muster AG', '<p>Hallo</p>')->withSender($peter, 7)->withSource('invoice:1');
check('N1 a draft with a valid identity has no problem; with an invalid one, or with Reply-To as well, it is refused',
    $service->problems($signed) === []
    && $service->problems(OutboxDraft::html('k@example.ch', 'A', '<p>x</p>')->withSender(new \Z77\Shared\Mail\SenderIdentity("Eve\r\nBcc: x@evil.ch", 'e@z77.ch'))) !== []
    && $service->problems(OutboxDraft::html('k@example.ch', 'A', '<p>x</p>')->withSender(new \Z77\Shared\Mail\SenderIdentity('Eve', 'kaputt'))) !== []
    && in_array('Absender und Antwortadresse (Reply-To) schliessen sich aus — die Antwortadresse ist die des Absenders.', $service->problems($signed->withReplyTo('x@z77.ch')), true));
$row = $service->queue($signed, 'peter');
$em  = $wireDi();
$stored = $db->fetchAssociative('SELECT sender_user_id, sender_name, sender_email, reply_to, created_by FROM mail_outbox WHERE id = ?', [$row->getId()]);
check('N2 the row records WHO triggered it and the identity as a snapshot: user id 7, «Peter Ruepp», peter.ruepp@z77.ch; reply_to stays empty',
    (int) $stored['sender_user_id'] === 7 && $stored['sender_name'] === 'Peter Ruepp' && $stored['sender_email'] === 'peter.ruepp@z77.ch' && $stored['reply_to'] === '' && $stored['created_by'] === 'peter', json_encode($stored));
$plain = $service->queue(OutboxDraft::html('k2@example.ch', 'Ohne Person', '<p>x</p>')->withReplyTo('buero@example.ch'), 'system');
check('N3 a draft without identity stores none (null / empty) — the mail of the account alone, its own Reply-To kept', (function () use ($db, $plain): bool {
    $r = $db->fetchAssociative('SELECT sender_user_id, sender_name, sender_email, reply_to FROM mail_outbox WHERE id = ?', [$plain->getId()]);
    return $r['sender_user_id'] === null && $r['sender_name'] === '' && $r['sender_email'] === '' && $r['reply_to'] === 'buero@example.ch';
})());
$transport->sent = [];
$clockNow = new \DateTimeImmutable('+1 second');
$job()->run($context());
$byTo = [];
foreach ($transport->sent as $sent) { $byTo[$sent['recipients'][0]] = $sent; }
$signedData = $byTo['kunde@example.ch']['data'] ?? '';
check('N4 sent: envelope sender and From ADDRESS = the account (outbox@example.ch), From NAME = the identity\'s, Reply-To = «"Peter Ruepp" <peter.ruepp@z77.ch>»',
    ($byTo['kunde@example.ch']['sender'] ?? '') === 'outbox@example.ch' && preg_match('/^From: "Peter Ruepp" <outbox@example\.ch>\r$/m', $signedData) === 1
    && preg_match('/^Reply-To: "Peter Ruepp" <peter\.ruepp@z77\.ch>\r$/m', $signedData) === 1, substr($signedData, 0, 400));
$plainData = $byTo['k2@example.ch']['data'] ?? '';
check('N5 … the mail without identity keeps the config\'s From name and its own Reply-To', preg_match('/^From: "Z77 Test" <outbox@example\.ch>\r$/m', $plainData) === 1 && preg_match('/^Reply-To: buero@example\.ch\r$/m', $plainData) === 1);
$deliveries = DI::getUnifiedEntityManager()->getRepository(OutboxMail::class)->deliveriesOf(['invoice:1']);
check('N6 deliveriesOf() names the identity a mail went out as (`sender`, `sender_email`)', ($deliveries['invoice:1'][0]['sender'] ?? '') === 'Peter Ruepp' && ($deliveries['invoice:1'][0]['sender_email'] ?? '') === 'peter.ruepp@z77.ch');
$useRequest(['id' => (string) $row->getId(), 'window' => '1']);
$h = $host();
$h->detailAction();
$win = $render('detail', $h->context);
check('N7 the window: «Ausgelöst von» peter als «Peter Ruepp <peter.ruepp@z77.ch>» (user id), «Antwort an» the identity, «Absender» its name with the account\'s address',
    str_contains($win, 'Ausgelöst von:</span> peter als Peter Ruepp &lt;peter.ruepp@z77.ch&gt; (Benutzer-Id 7)') && str_contains($win, 'Antwort an:</span> Peter Ruepp &lt;peter.ruepp@z77.ch&gt;')
    && str_contains($win, '«Peter Ruepp» mit der Adresse aus der E-Mail-Konfiguration'), strip_tags($win));
$db->executeStatement('DELETE FROM mail_outbox');

// ── M. source guards ─────────────────────────────────────────────────────

echo "M. Source guards\n";
$sources = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($package, \FilesystemIterator::SKIP_DOTS)) as $f) {
    if (str_ends_with($f->getFilename(), '.php')) { $sources[str_replace('\\', '/', $f->getPathname())] = (string) file_get_contents($f->getPathname()); }
}
$offending = fn(string $pattern) => array_keys(array_filter($sources, fn($s) => preg_match($pattern, $s) === 1));
check('M1 no superglobals (Rule 4)', $offending('/\$_(GET|POST|SERVER|REQUEST)\b/') === [], implode(', ', $offending('/\$_(GET|POST|SERVER|REQUEST)\b/')));
check('M2 no response instantiated directly (Rule 3) and no sleep() in the job (it holds its lock)', $offending('/new\s+(Html|Fetch|Json|Redirect)Response\b/') === [] && $offending('/\bu?sleep\s*\(/') === []);
check('M3 the templates carry no <script>, no inline handler, no innerHTML (Rule 7)', array_filter($sources, fn($s, $p) => str_contains($p, '/res/view/') && (stripos($s, '<script') !== false || preg_match('/\son[a-z]+\s*=/i', $s) === 1 || str_contains($s, 'innerHTML')), ARRAY_FILTER_USE_BOTH) === []);
check('M4 the outbox knows no document module (no use of debtor / order / contact)', $offending('/Z77\\\\Module\\\\(Debtor|Order|Contact)\\\\/') === []);
check('M5 the service never sends: OutboxService has no Mailer / transport', !str_contains($sources[$package . '/src/Outbox/OutboxService.php'], 'Mailer') && !str_contains($sources[$package . '/src/Outbox/OutboxService.php'], '->send('));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
