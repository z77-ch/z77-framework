<?php

/**
 * CsrfService harness (CLI, no session_start, plain $_SESSION) — security.md SEC-007.
 *
 * What is load-bearing here: an empty token is refused whether or not the session holds
 * one. Before SEC-007 a session without a token compared '' with '' and passed.
 *
 * Run: php tests/csrf-service.php
 */

spl_autoload_register(static function (string $class): void {
    $prefix = 'Z77\\Core\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/../packages/kernel/core/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

use Z77\Core\Http\Security\CsrfService;
use Z77\Core\Session\SessionManager;

$failures = 0;
$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$_SESSION = [];
$csrf = new CsrfService(new SessionManager());

$check('no token in the session: an empty token is refused', $csrf->validate('') === false);
$check('no token in the session: any other token is refused', $csrf->validate('abc') === false);
$check('validating does not create a token', $_SESSION === []);

$token = $csrf->getToken();

$check('the session token is issued non-empty', $token !== '');
$check('the session token passes', $csrf->validate($token) === true);
$check('with a token in the session: an empty token is refused', $csrf->validate('') === false);
$check('with a token in the session: a wrong token is refused', $csrf->validate(str_repeat('0', strlen($token))) === false);

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
