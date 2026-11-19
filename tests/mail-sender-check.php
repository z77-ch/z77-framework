<?php

/**
 * Setup step 9 `mail.sender` (CLI, offline, no DI, no database) — the dashboard's «Ersteinrichtung»
 * check of module-backend: open while mail is disabled, uses the `file` transport or has no
 * sender; silent for a real transport with a sender; id / slot / target.
 *
 * Run: php tests/mail-sender-check.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "mail-sender-check: vendor/autoload.php missing — run `composer install` first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Backend\ProjectHealth\MailSenderCheck;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

$good = ['enabled' => true, 'transport' => 'smtp', 'fromAddress' => 'info@example.ch'];
$run  = function (array $cfg): array {
    $c = new MailSenderCheck($cfg);

    return [$c->isOpen(), $c->label()];
};

[$open, $label] = $run([]);
check('M1 no config → open «E-Mail-Versand einschalten»', $open && $label === 'E-Mail-Versand einschalten');
[$open, $label] = $run(['enabled' => false] + $good);
check('M2 enabled = false → open «E-Mail-Versand einschalten»', $open && $label === 'E-Mail-Versand einschalten');
[$open, $label] = $run(['transport' => 'file'] + $good);
check('M3 transport file → open «E-Mail-Versand einrichten …»', $open && str_starts_with($label, 'E-Mail-Versand einrichten'));
[$open, $label] = $run(['fromAddress' => ''] + $good);
check('M4 no sender → open «E-Mail-Absender erfassen»', $open && $label === 'E-Mail-Absender erfassen');
[$open] = $run(['fromAddress' => '  '] + $good);
check('M5 a blank sender counts as none', $open);
[$open] = $run($good);
check('M6 smtp + sender → silent', !$open);
[$open] = $run(['transport' => 'mail'] + $good);
check('M7 transport mail + sender → silent', !$open);
[$open] = $run(['transport' => null] + $good);
check('M8 transport unset (default smtp) + sender → silent', !$open);

$c = new MailSenderCheck([]);
check('M9 id mail.sender, slot 90, target service/email-settings/list, setup + todo',
    $c->id() === 'mail.sender' && $c->order() === 90 && $c->target() === ['backend', 'service', 'email-settings', 'list']
    && $c->category() === \Z77\Shared\ProjectHealth\CheckCategory::Setup && $c->severity() === \Z77\Shared\Dashboard\Severity::Todo);

echo "\nmail-sender-check: {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
