<?php

/**
 * Optimistic-locking harness (CLI). Runs the real stack — Navigation entity,
 * NavigationValidator, CollectionStore, FileRepository — via the skeleton's
 * composer autoloader (vendor/z77/* symlinks to packages/), no HTTP needed.
 *
 * Verifies the edit-flow conflict guard (EntityStateHash + guardStoredState):
 *   - form A loaded, entity changed elsewhere, A saved → rejected as a
 *     validation error, store keeps the other change
 *   - no intermediate change → save passes
 *   - missing hash → conflict (fail loud)
 *   - conflict survives repeated isValid() calls and coexists with field errors
 *   - new entities are exempt (no stored state, guard not called)
 *
 * Run: php tests/optimistic-locking.php
 * Uses a throwaway data directory in the system temp; removed on success.
 */

$work = sys_get_temp_dir().'/z77-optimistic-locking-'.getmypid();
define('ABS_BASE_PATH', $work);

require __DIR__.'/../skeleton/vendor/autoload.php';

use Z77\Persistence\Concurrency\EntityStateHash,
    Z77\Persistence\File\Repository\FileRepository,
    Z77\Persistence\File\Storage\CollectionStore,
    Z77\Persistence\File\Storage\FileStorage,
    Z77\Shared\Attributes\Entity as EntityAttr,
    Z77\Shared\Entities\Navigation,
    Z77\Shared\Validators\NavigationValidator;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

const CONFLICT_MSG = 'Der Eintrag wurde inzwischen geändert — neu laden und Änderung erneut anbringen.';

$attr  = (new ReflectionClass(Navigation::class))->getAttributes(EntityAttr::class)[0]->newInstance();
$store = new CollectionStore(new FileStorage(), $attr);
$repo  = new FileRepository(Navigation::class, $store);

// seed the stored state
$seed = new Navigation();
$seed->setName('Original');
$seed->setSlot('main-nav');
$store->persistAll([$seed]);
$id = $seed->getId();

echo "1. hash is deterministic over the stored state\n";
$hashA = EntityStateHash::of($repo->find($id));
check('two loads of the same state hash identically', $hashA === EntityStateHash::of($repo->find($id)));

echo "2. conflict: form A loaded, entity changed elsewhere, A saved\n";
// admin B saves in between
$navB = $repo->find($id);
$navB->setName('Von B geändert');
$store->persistAll([$navB]);

// admin A posts the form rendered from hashA — controller flow: fresh read,
// guard BEFORE mapFromArray, then hydrate the body and validate
$fresh     = $repo->find($id);
$validator = new NavigationValidator($fresh);
$validator->guardStoredState($hashA);
$fresh->mapFromArray(['name' => 'Von A geändert']);
check('save is rejected', $validator->isValid() === false);
check('conflict message in the general error channel', in_array(CONFLICT_MSG, $validator->getErrors(), true));
check('no field errors from the conflict itself', $validator->getFieldErrors() === []);
check('store keeps the other change', $repo->find($id)->getName() === 'Von B geändert');

echo "3. conflict survives isValid() resets and coexists with field errors\n";
check('second isValid() still fails', $validator->isValid() === false);
$fresh->mapFromArray(['name' => '']);   // also break a field rule
$validator->isValid();
check('conflict and field error coexist', in_array(CONFLICT_MSG, $validator->getErrors(), true) && $validator->hasFieldError('name'));

echo "4. no intermediate change → save passes\n";
$current   = $repo->find($id);
$hash      = EntityStateHash::of($current);
$validator = new NavigationValidator($current);
$validator->guardStoredState($hash);
$current->mapFromArray(['name' => 'Ungestört gespeichert']);
check('save is accepted', $validator->isValid() === true);
$store->persistAll([$current]);
check('store holds the new state', $repo->find($id)->getName() === 'Ungestört gespeichert');

echo "5. missing hash → conflict (fail loud)\n";
$validator = new NavigationValidator($repo->find($id));
$validator->guardStoredState('');
check('empty submitted hash is rejected', $validator->isValid() === false);

echo "6. new entity is exempt (guard not called)\n";
$new = new Navigation();
$new->setName('Neuer Eintrag');
$new->setSlot('main-nav');
$validator = new NavigationValidator($new);
check('new entity validates without a stored-state hash', $validator->isValid() === true);

echo "\n{$pass} passed, {$fail} failed\n";

if ($fail === 0) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($work);
}

exit($fail === 0 ? 0 : 1);
