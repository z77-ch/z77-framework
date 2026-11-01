<?php

/**
 * A sender identity through `EmailService::send()` down to the real headers (CLI, offline, a
 * capturing transport) — mail.md «sender identity». What is load-bearing: `EmailMessage` can set
 * a display name alone (it used to be dropped silently), the From ADDRESS and the envelope stay
 * the installation's, Reply-To names the person, and a mail without identity is unchanged.
 *
 * Run: php tests/mail-sender-identity.php
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'        => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'      => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => __DIR__ . '/../packages/kernel/persistence/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Shared\Mail\EmailMessage;
use Z77\Shared\Mail\EmailService;
use Z77\Shared\Mail\Mailer;
use Z77\Shared\Mail\MailTransport;
use Z77\Shared\Mail\SenderIdentity;

$failures = 0;
$check = static function (string $label, bool $ok, string $got = '') use (&$failures): void {
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . ($ok || $got === '' ? '' : "\n       got: {$got}") . "\n";
    if (!$ok) {
        $failures++;
    }
};

$transport = new class implements MailTransport {
    public string $sender = '';
    public string $data = '';

    public function send(string $sender, array $recipients, string $data): void
    {
        $this->sender = $sender;
        $this->data   = $data;
    }
};
$service = new EmailService(new Mailer($transport, 'no-reply@example.ch', 'Muster AG'));
$header  = static function (string $name) use ($transport): string {
    return preg_match('/^' . $name . ': (.*)$/mi', $transport->data, $m) === 1 ? trim($m[1]) : '';
};
$mail = static fn() => (new EmailMessage())->to('kunde@example.ch')->subject('Test')->text('Hallo');

$identity = new SenderIdentity('Max Muster', 'max.muster@example.ch');

$sent = $service->send($mail()->signedBy($identity));
$check('signed: sent', $sent === true, implode(' ', $service->getLastErrors()));
$check('signed: From carries the name, the address stays the account', str_contains($header('From'), 'Max Muster') && str_contains($header('From'), '<no-reply@example.ch>'), $header('From'));
$check('signed: the envelope stays the account', $transport->sender === 'no-reply@example.ch', $transport->sender);
$check('signed: Reply-To is the person, with the name', str_contains($header('Reply-To'), 'Max Muster') && str_contains($header('Reply-To'), '<max.muster@example.ch>'), $header('Reply-To'));

$service->send($mail()->fromName('Erika Muster'));
$check('a display name alone is applied', str_contains($header('From'), 'Erika Muster') && str_contains($header('From'), '<no-reply@example.ch>'), $header('From'));
$check('…and sets no Reply-To', $header('Reply-To') === '', $header('Reply-To'));

$service->send($mail());
$check('no identity: the installation\'s name and address', str_contains($header('From'), 'Muster AG') && str_contains($header('From'), '<no-reply@example.ch>'), $header('From'));
$check('no identity: no Reply-To', $header('Reply-To') === '', $header('Reply-To'));

$service->send($mail()->from('info@example.ch', 'Same Domain'));
$check('from() with an address still wins', str_contains($header('From'), 'Same Domain') && str_contains($header('From'), '<info@example.ch>'), $header('From'));

$service->send($mail()->replyTo('kontakt@example.ch'));
$check('replyTo() without a name stays bare', $header('Reply-To') === 'kontakt@example.ch', $header('Reply-To'));

$service->send($mail()->replyTo("bad\r\nBcc: x@example.ch"));
$check('an invalid Reply-To is still dropped', $header('Reply-To') === '' && !str_contains($transport->data, 'Bcc:'), $header('Reply-To'));

$before = $transport->data;
$sent = $service->send($mail()->fromName("Max\r\nBcc: x@example.ch"));
$check('a line break in the name: not sent, no header injection', $sent === false && $transport->data === $before);

echo $failures === 0 ? "\nALL GREEN\n" : "\n{$failures} FAILURE(S)\n";
exit($failures === 0 ? 0 : 1);
