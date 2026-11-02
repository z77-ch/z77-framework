<?php

/**
 * What a save of a collection entity writes (CLI, offline, temp ABS_BASE_PATH) — persistence-file.md
 * DATA-MERGE-001. What is load-bearing:
 *
 *   (A) with no concurrency a read + save behaves as a whole-row write always did, over every real
 *       collection entity: a stored value the setter tolerates does not throw, the row comes out
 *       normalised through the setters;
 *   (B) a deleted row stays deleted, whatever a stale object still holds;
 *   (C) ids are not handed out twice;
 *   (D) a key removed by hand stays removed; a key that never was there is added on the next save;
 *   (E) a row that no longer reads as an entity does not stop the save.
 *
 * Stale writers and parallel requests against the backend user: tests/backend-2fa.php.
 *
 * Run: php tests/persistence-file-merge.php
 */

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Persistence\File\Storage\FileStorage;
use Z77\Persistence\Resolver\DataSourceResolver;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Shared\Attributes\Counter;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Traits\ArrayMappable;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "persistence-file-merge: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}

$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-file-merge-' . bin2hex(random_bytes(4));
mkdir($base . '/data', 0777, true);
define('ABS_BASE_PATH', $base);
require $autoload;
ini_set('error_log', $base . '/error.log');
ini_set('log_errors', '1');

// The monorepo autoloader does not map every module.
spl_autoload_register(static function (string $class): void {
    if (preg_match('/^Z77\\\\Module\\\\([A-Za-z]+)\\\\(.+)$/', $class, $m)) {
        $file = __DIR__ . '/../packages/module-' . strtolower($m[1]) . '/src/' . str_replace('\\', '/', $m[2]) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function rrmdir(string $dir): void
{
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $dir . '/' . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

DI::getInstance(true)->set('CacheManager', CacheManager::class, true);
$uem = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
DI::getInstance()->set('UnifiedEntityManager', $uem, true);

#[Entity('file', 'probe/things.json')]
class Thing
{
    use ArrayMappable;

    private ?int $id = null;
    private string $a = '';
    private string $b = '';
    private ?string $n = null;
    private int $cast = 0;
    private bool $flag = false;
    private string $trimmed = '';
    private array $sane = [];
    #[Counter] private int $ctr = 0;

    public function __construct(array $data = []) { if ($data) { $this->mapFromArray($data); } }
    public function getId(): ?int { return $this->id; }
    public function getA(): string { return $this->a; }
    public function setA(string $v): void { $this->a = $v; }
    public function getB(): string { return $this->b; }
    public function setB(string $v): void { $this->b = $v; }
    public function getN(): ?string { return $this->n; }
    public function setN(?string $v): void { $this->n = $v; }
    public function setCast(mixed $v): void { $this->cast = (int) $v; }
    public function getCast(): int { return $this->cast; }
    public function setFlag(bool|int|string $v): void { $this->flag = (bool) $v; }
    public function getFlag(): bool { return $this->flag; }
    public function setTrimmed(string $v): void { $this->trimmed = trim($v); }
    public function getTrimmed(): string { return $this->trimmed; }
    public function setSane(mixed $v): void { $this->sane = is_array($v) ? $v : []; }
    public function getSane(): array { return $this->sane; }
    public function raise(): void { $this->ctr++; }
    public function getCtr(): int { return $this->ctr; }
}

$file    = $base . '/data/probe/things.json';
mkdir(dirname($file), 0777, true);
$seed    = static function (array $rows) use ($file): void { file_put_contents($file, json_encode($rows)); };
$rows    = static fn(): array => json_decode((string) file_get_contents($file), true);
$row     = static function (int $id = 1) use ($rows): ?array {
    foreach ($rows() as $r) { if ($r['id'] === $id) { return $r; } }
    return null;
};
$repo    = static fn() => $uem->getRepository(Thing::class);
$save    = static function (object $e) use ($uem): void { $uem->persist($e); $uem->flush(); };
$start   = ['id' => 1, 'a' => 'A', 'b' => 'B', 'n' => null, 'cast' => 1, 'flag' => true, 'trimmed' => 't', 'sane' => [], 'ctr' => 0];

// ── (A) no concurrency ──────────────────────────────────────────────────────
echo "(A) a plain read + save\n";
$seed([['trimmed' => ' t '] + $start]);
$e = $repo()->find(1);
$save($e);
check('a trimming setter normalises what the row holds on save', $row()['trimmed'] === 't' && $e->getTrimmed() === 't', var_export($row()['trimmed'], true));
$seed([['cast' => '5'] + $start]);
$e = $repo()->find(1);
$e->setA('x');
$save($e);
check('"5" through a cast setter is stored as 5', $row()['cast'] === 5 && $e->getCast() === 5, var_export($row()['cast'], true));
foreach (["''" => '', 'null' => null] as $label => $stored) {
    $seed([['cast' => $stored] + $start]);
    $e = $repo()->find(1);
    $e->setA('x');
    $save($e);
    check("{$label} in a cast int column: saved as 0", $row()['cast'] === 0 && $row()['a'] === 'x');
}
$seed([['sane' => null] + $start]);
$e = $repo()->find(1);
$e->setA('x');
$save($e);
check('null in an array column whose setter tolerates it: saved as []', $row()['sane'] === []);
$seed([['flag' => 1] + $start]);
$e = $repo()->find(1);
$e->setA('x');
$save($e);
check('1 in a bool column: saved as true', $row()['flag'] === true);

// The same against what another request wrote: the merged value is read through the setters too.
$seed([$start]);
$e = $repo()->find(1);
$hand = $rows();
$hand[0]['trimmed'] = ' neu ';
$hand[0]['cast'] = '7';
$hand[0]['sane'] = null;
file_put_contents($file, json_encode($hand));
$e->setA('x');
$save($e);
check('a column another writer changed, stored untidy: normalised, and my column written', $row()['trimmed'] === 'neu' && $row()['cast'] === 7 && $row()['sane'] === [] && $row()['a'] === 'x', json_encode($row()));
check('…the entity in hand fits its types and knows it', $e->getTrimmed() === 'neu' && $e->getCast() === 7 && $e->getSane() === []);

// Every real collection entity, and values its setters tolerate: what a whole-row write produced.
$classes = [
    BackendUser::class,
    'Z77\Shared\Entities\BlockedCountry', 'Z77\Shared\Entities\EmailDocumentSetting', 'Z77\Shared\Entities\EmailFormSetting',
    'Z77\Shared\Entities\JobRun', 'Z77\Shared\Entities\JobSchedule', 'Z77\Shared\Entities\MetaData',
    'Z77\Shared\Entities\Navigation', 'Z77\Shared\Entities\NavigationAlias',
    'Z77\Module\Contact\Entities\AddressType',
    'Z77\Module\Debtor\Entities\DunningLevel', 'Z77\Module\Debtor\Entities\PaymentTarget', 'Z77\Module\Debtor\Entities\PaymentTerms',
    'Z77\Module\Dms\Entities\AccessControlEntry', 'Z77\Module\Dms\Entities\Document', 'Z77\Module\Dms\Entities\Folder',
    'Z77\Module\Member\Entities\MemberAccount', 'Z77\Module\Member\Entities\MemberPendingLogin', 'Z77\Module\Member\Entities\MemberToken',
    'Z77\Module\Order\Entities\OrderStatus',
    'Z77\Module\Vat\Entities\TaxCode', 'Z77\Module\Vat\Entities\TaxRate',
];
$candidates = [null, '', ' ', '5', 'abc', 0, 1, true, []];
$tried = 0;
$threw = [];
$differ = [];
$namedClasses = [];
foreach ($classes as $class) {
    if (!class_exists($class)) {
        check("{$class} is loadable", false);
        continue;
    }
    $attr = (new ReflectionClass($class))->getAttributes(Entity::class)[0]->newInstance();
    if (($attr->perRecord ?? false) === true) {
        continue; // one file per record: DocumentStore, not this merge
    }
    $target = $base . '/data/' . $attr->getPath();
    @mkdir(dirname($target), 0777, true);
    $shape = (new $class())->mapToArray();
    foreach ($shape as $key => $default) {
        if ($key === 'id') {
            continue;
        }
        foreach ($candidates as $candidate) {
            $stored = ['id' => 1] + $shape;
            $stored['id'] = 1;
            $stored[$key] = $candidate;
            file_put_contents($target, json_encode([$stored]));
            try {
                $entity = @$uem->getRepository($class)->find(1);
            } catch (Throwable) {
                continue; // the read refuses it: nothing to save, as at HEAD
            }
            if ($entity === null) {
                continue;
            }
            $tried++;
            $namedClasses[$class] = true;
            $expected = $entity->mapToArray();
            try {
                $uem->persist($entity);
                @$uem->flush();
            } catch (Throwable $t) {
                $threw[] = (new ReflectionClass($class))->getShortName() . ".{$key}=" . json_encode($candidate) . ': ' . $t->getMessage();
                continue;
            }
            $written = json_decode((string) file_get_contents($target), true)[0];
            if ($written !== json_decode((string) json_encode($expected), true)) {
                $differ[] = (new ReflectionClass($class))->getShortName() . ".{$key}=" . json_encode($candidate);
            }
        }
    }
}
check("real entities: {$tried} stored values over " . count($namedClasses) . ' collection classes, none throws on save', $threw === [], implode(' | ', array_slice($threw, 0, 3)));
check('…each comes out as the whole-row write would have written it', $differ === [], implode(', ', array_slice($differ, 0, 8)));
check('the classes the review named are among them', isset($namedClasses[BackendUser::class], $namedClasses['Z77\Shared\Entities\MetaData'], $namedClasses['Z77\Module\Debtor\Entities\DunningLevel'], $namedClasses['Z77\Module\Debtor\Entities\PaymentTerms']));

// ── (B) deleted stays deleted ───────────────────────────────────────────────
echo "(B) a deleted row\n";
$seed([$start]);
$stale = $repo()->find(1);
$stale->setA('MINE');
$uem->remove($repo()->find(1));
@unlink($base . '/error.log');
$save($stale);
check('a changed stale save does not bring it back', $row() === null);
check('…and says so in the log', str_contains((string) @file_get_contents($base . '/error.log'), 'skipped, the row was deleted'));
$seed([$start, ['id' => 2] + $start]);
$stale = $repo()->find(1);
$uem->remove($repo()->find(1));
$save($stale);
check('an unchanged one neither, the other row stays', $row() === null && $row(2) !== null);
$seed([$start]);
$gone = $repo()->find(1);
$uem->remove($gone);
$save($gone);
check('remove and persist of the same object: stays gone', $row() === null);
$seed([$start]);
$other = $repo()->find(1);
$other->setA('O');
$save($other);
$uem->remove($repo()->find(1));
check('a delete after an update just deletes', $row() === null);
$seed([$start]);
$fresh = new Thing(['a' => 'N']);
$save($fresh);
check('a new entity is appended', $fresh->getId() === 2 && $row(2)['a'] === 'N');
$fresh->setA('N2');
$save($fresh);
check('…and updated by a second save', $row(2)['a'] === 'N2' && count($rows()) === 2);
$uem->remove($fresh);
$save($fresh);
check('…once deleted, a save of the same object does not recreate it', $row(2) === null);
$seed([$start]);
$built = new Thing(['a' => 'H']);
(new ReflectionProperty($built, 'id'))->setValue($built, 9);
$save($built);
check('built by hand with an id not in the store: appended, as before', $row(9)['a'] === 'H');
$built2 = new Thing(['a' => 'H2']);
(new ReflectionProperty($built2, 'id'))->setValue($built2, 1);
$save($built2);
check('built by hand with an existing id: the whole row, as before', $row()['a'] === 'H2' && $row()['b'] === '');

// ── (C) ids ─────────────────────────────────────────────────────────────────
echo "(C) ids are not handed out twice\n";
@unlink($file . '.highest-id');
$seed([$start, ['id' => 2] + $start]);
$stale = $repo()->find(2);
$stale->setA('STALE');
$uem->remove($repo()->find(2));
$next = new Thing(['a' => 'NEW', 'b' => 'NEWB']);
$save($next);
check('after deleting the highest id the next record gets a new one', $next->getId() === 3, (string) $next->getId());
$save($stale);
check('a stale save of the deleted id touches neither the new row nor the store', $row(2) === null && $row(3)['a'] === 'NEW' && $row(3)['b'] === 'NEWB');
$uem->remove($repo()->find(1));
$another = new Thing(['a' => 'X']);
$save($another);
check('deleting a lower id changes nothing about the next one', $another->getId() === 4, (string) $another->getId());
$uem2 = new UnifiedEntityManager(new DataSourceResolver(['file' => 'File']));
$uem2->remove($uem2->getRepository(Thing::class)->find(4));
$third = new Thing(['a' => 'Y']);
$uem2->persist($third);
$uem2->flush();
check('it holds across requests', $third->getId() === 5, (string) $third->getId());
$storage = new FileStorage();
check('the mark is no data file: list() does not see it', !in_array('probe/things.json.highest-id', $storage->list('probe'), true) && is_file($file . '.highest-id'));
foreach ([$third] as $x) { $uem2->remove($x); }
$seed([]);
$empty = new Thing(['a' => 'Z']);
$save($empty);
check('an emptied store still does not start over', $empty->getId() === 6, (string) $empty->getId());
@unlink($file . '.highest-id');
$seed([]);
$first = new Thing(['a' => 'A']);
$save($first);
check('a store that never saw a delete starts at 1', $first->getId() === 1);

// ── (D) keys ────────────────────────────────────────────────────────────────
echo "(D) keys\n";
$seed([['n' => 'SECRET'] + $start]);
$e = $repo()->find(1);
$hand = $rows();
unset($hand[0]['n']);
file_put_contents($file, json_encode($hand));
$e->setA('x');
$save($e);
check('a key removed by hand while a stale save runs stays removed', !array_key_exists('n', $row()) && $row()['a'] === 'x');
check('…the object in hand no longer holds the value', $e->getN() === null);
$save($e);
check('…a second save of it writes the default, not the old value', ($row()['n'] ?? null) === null);
$legacy = $start;
unset($legacy['n'], $legacy['ctr']);
$seed([$legacy]);
$e = $repo()->find(1);
$save($e);
check('a key that never was in the row is added on the next save', array_key_exists('n', $row()) && $row()['n'] === null && $row()['ctr'] === 0);
$seed([$legacy]);
$e = $repo()->find(1);
$hand = $rows();
$hand[0]['n'] = 'OTHER';
file_put_contents($file, json_encode($hand));
$save($e);
check('…one another writer added since the read is kept', $row()['n'] === 'OTHER');
$seed([['legacy_col' => 'old'] + $start]);
$e = $repo()->find(1);
$save($e);
check('a column the entity does not know is dropped, as before', !array_key_exists('legacy_col', $row()));
$usersFile = $base . '/data/framework/auth/backendUsers.json';
@mkdir(dirname($usersFile), 0777, true);
file_put_contents($usersFile, json_encode([['id' => 1, 'username' => 'max', 'password_hash' => 'H', 'roles' => ['editor'], 'sort_key' => 0, 'totp_secret' => 'CT', 'totp_activated_at' => '2026', 'totp_last_step' => 10, 'auth_epoch' => 3]]));
$user = $uem->getRepository(BackendUser::class)->find(1);
$hand = json_decode((string) file_get_contents($usersFile), true);
unset($hand[0]['totp_secret'], $hand[0]['totp_activated_at'], $hand[0]['totp_last_step']);
$hand[0]['auth_epoch'] = 4;
file_put_contents($usersFile, json_encode($hand));
$user->setName('Max Muster');
$uem->persist($user);
$uem->flush();
$after = json_decode((string) file_get_contents($usersFile), true)[0];
check('the hand reset of login.md under a stale save: the secret does not come back', !isset($after['totp_secret']) && $after['auth_epoch'] === 4 && $after['name'] === 'Max Muster', json_encode($after));
check('…and the object in hand has no 2FA', !$user->hasTotp());
foreach ([null, ''] as $stored) {
    file_put_contents($usersFile, json_encode([['id' => 1, 'username' => 'max', 'password_hash' => 'H', 'roles' => ['editor'], 'sort_key' => 0, 'sender_identities' => $stored]]));
    $user = $uem->getRepository(BackendUser::class)->find(1);
    $user->setName('x');
    $uem->persist($user);
    $uem->flush();
    check('BackendUser.sender_identities ' . json_encode($stored) . ' in the row: saved as []', json_decode((string) file_get_contents($usersFile), true)[0]['sender_identities'] === []);
}

// ── (E) a row that no longer reads ──────────────────────────────────────────
echo "(E) a row another writer left unreadable\n";
$seed([$start]);
$e = $repo()->find(1);
$hand = $rows();
$hand[0]['a'] = null;   // setA(string) refuses it
file_put_contents($file, json_encode($hand));
$e->setB('x');
$threwHere = false;
try {
    @$save($e);
} catch (Throwable) {
    $threwHere = true;
}
check('the save writes the whole row instead of throwing', !$threwHere && $row()['a'] === 'A' && $row()['b'] === 'x', json_encode($row()));

rrmdir($base);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
