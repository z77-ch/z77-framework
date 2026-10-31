<?php

/**
 * Member tenant noun harness (CLI, offline, no DI) — memberConfig `tenantNoun`,
 * z77-rudaz.ch 2026-10-31.
 *
 * The member area names what a member works FOR: «Verwaltung» (AXO3, the default)
 * or «Projekt» (rudaz). What is load-bearing:
 *
 *   (A) the default reads exactly as the hard-coded wording before (feminine);
 *   (B) neuter and masculine get the right articles, adjective endings, «zu» and
 *       pronouns in nominative, accusative and dative;
 *   (C) a broken config falls back to the default; the genitive is refused;
 *       fromConfig() without a container is the default, never an error;
 *   (D) no member template or class names «Verwaltung» outside the noun any more.
 *
 * Run: php tests/member-tenant-noun.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "member-tenant-noun: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

// The monorepo autoloader does not map module-member — same loader as
// member-operator-invite.php.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Module\\Member\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/module-member/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Module\Member\Services\TenantNoun;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** The phrases the member templates use, in template order. */
function phrases(TenantNoun $n): array
{
    return [
        $n->with('dies', 'acc'),                // invite: fügen Sie … hinzu
        $n->with('welch', 'acc'),               // join-activated / danke: für … Sie arbeiten
        $n->with('Ihr', 'dat'),                 // profile: Am Namen von …
        $n->pronoun('acc'),                     // profile: wer … besitzt
        $n->with('der', 'dat'),                 // profile: in … selbst
        $n->with('der', 'nom'),                 // danke: kann … Sie erneut einladen
        $n->with('dies', 'dat'),                // register: gehört bereits zu …
        $n->with('dies', 'nom'),                // register: kommt … als weiterer Mandant hinzu
        $n->article('der', 'nom'),              // register: relative pronoun
        $n->with('ein', 'dat', 'bestehend'),    // register: an … mitzuarbeiten
        $n->zu(),                               // invite subject without a label hook
    ];
}

echo "(A) default = the wording before\n";
$f = new TenantNoun();
check('Verwaltung / Verwaltungen', $f->singular() === 'Verwaltung' && $f->plural() === 'Verwaltungen');
$got = phrases($f);
check('feminine phrases', $got === [
    'diese Verwaltung', 'welche Verwaltung', 'Ihrer Verwaltung', 'sie', 'der Verwaltung', 'die Verwaltung',
    'dieser Verwaltung', 'diese Verwaltung', 'die', 'einer bestehenden Verwaltung', 'zur Verwaltung',
], json_encode($got, JSON_UNESCAPED_UNICODE));

echo "(B) neuter (rudaz) and masculine\n";
$n = TenantNoun::fromArray(['singular' => 'Projekt', 'plural' => 'Projekte', 'gender' => 'n']);
$got = phrases($n);
check('neuter phrases', $got === [
    'dieses Projekt', 'welches Projekt', 'Ihrem Projekt', 'es', 'dem Projekt', 'das Projekt',
    'diesem Projekt', 'dieses Projekt', 'das', 'einem bestehenden Projekt', 'zum Projekt',
], json_encode($got, JSON_UNESCAPED_UNICODE));
check('plural kept', $n->plural() === 'Projekte');
$m = TenantNoun::fromArray(['singular' => 'Auftrag', 'plural' => 'Aufträge', 'gender' => 'M']);
$got = phrases($m);
check('masculine phrases (gender case-insensitive)', $got === [
    'diesen Auftrag', 'welchen Auftrag', 'Ihrem Auftrag', 'ihn', 'dem Auftrag', 'der Auftrag',
    'diesem Auftrag', 'dieser Auftrag', 'der', 'einem bestehenden Auftrag', 'zum Auftrag',
], json_encode($got, JSON_UNESCAPED_UNICODE));
check('adjective after ein-word, nominative', $n->with('ein', 'nom', 'neu') === 'ein neues Projekt' && $m->with('ein', 'nom', 'neu') === 'ein neuer Auftrag' && $f->with('ein', 'nom', 'neu') === 'eine neue Verwaltung');
check('adjective after der-word, accusative', $m->with('der', 'acc', 'neu') === 'den neuen Auftrag' && $n->with('der', 'acc', 'neu') === 'das neue Projekt');

echo "(C) fallbacks\n";
check('unknown gender → default', TenantNoun::fromArray(['singular' => 'Projekt', 'gender' => 'x'])->with('der') === 'die Verwaltung');
check('empty singular → default', TenantNoun::fromArray(['singular' => ' ', 'gender' => 'n'])->singular() === 'Verwaltung');
check('missing plural → singular', TenantNoun::fromArray(['singular' => 'Team', 'gender' => 'n'])->plural() === 'Team');
check('non-string parts → default', TenantNoun::fromArray(['singular' => ['x'], 'gender' => 1])->singular() === 'Verwaltung');
$threw = false;
try { $f->with('der', 'gen'); } catch (InvalidArgumentException) { $threw = true; }
check('genitive refused (the noun itself would inflect)', $threw);
check('fromConfig() without a container → default', TenantNoun::fromConfig()->with('der') === 'die Verwaltung');

echo "(D) no hard-coded «Verwaltung» left\n";
$left = [];
$root = __DIR__ . '/../packages/module-member';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'TenantNoun.php')) {
        continue;
    }
    foreach (file($file->getPathname()) as $i => $line) {
        $code = trim($line);
        // comments may keep the word (they explain the AXO3 origin)
        if (str_contains($line, 'Verwaltung') && !preg_match('#^(\*|//|/\*|<\?php /\*)#', $code)) {
            $left[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($i + 1);
        }
    }
}
check('member texts all go through the noun', $left === [], implode(', ', $left));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
