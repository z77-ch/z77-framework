<?php

/**
 * Backend user sender identities harness (CLI, offline, no database) — owner decision
 * 2026-10-23: a staff member sends mail in his own name. What is load-bearing here:
 *
 *   - (A) `SenderIdentity`: the one rule (display name + deliverable address, no header
 *     characters in the name), the form rows (`listFromForm()`: parallel maps, posted order,
 *     empty rows dropped, values cleaned);
 *   - (B) `BackendUser::$senderIdentities`: stored under `sender_identities` as rows, the file
 *     round trip (mapToArray → JSON → mapFromArray), a file without the key reads [];
 *   - (C) `BackendUserValidator`: a bad row named with its number, duplicates, the maximum;
 *   - (D) `SenderIdentities`: the default is the first, `choose()` re-checks the posted index
 *     against THIS user's list (Rule 7), no identity = no choice;
 *   - (E) the kernel mail stack: `Message::fromName()` + the `Mailer` keep the ACCOUNT's
 *     address and take the identity's name; Reply-To «name <email>»;
 *   - (F) the user form renders the rows (core.js «rows» contract) and the controller rebuilds
 *     the list from the form, never from the body's own key;
 *   - (G) the shared partial «Senden als»: select / read-only / the hint without a field.
 *
 * Run: php tests/backend-user-identities.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "backend-user-identities: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Shared\Entities\BackendUser;
use Z77\Shared\Mail\Mailer;
use Z77\Shared\Mail\MailTransport;
use Z77\Shared\Mail\Message;
use Z77\Shared\Mail\SenderIdentities;
use Z77\Shared\Mail\SenderIdentity;
use Z77\Shared\Validators\BackendUserValidator;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** A stand-in for TemplateRenderer: namespaces map onto the package template roots. */
final class TplRenderer
{
    private const ROOTS = [
        'Z77\\Shared'         => __DIR__ . '/../packages/kernel/shared/res/view/templates/',
        'Z77\\Module\\Backend' => __DIR__ . '/../packages/module-backend/res/view/templates/',
    ];

    public function __construct(private string $nameSpace) {}

    public function render(string $path, array $context, ?string $nameSpace = null): string
    {
        $file = self::ROOTS[$nameSpace ?? $this->nameSpace] . $path . '.tpl.php';
        $run  = function () use ($file, $context): string {
            extract($context, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        };
        return \Closure::bind($run, $this, self::class)();
    }

    public function partial(string $path, array $context = [], ?string $nameSpace = null): string
    {
        return $this->render($path, $context, $nameSpace);
    }
}

final class RecordingTransport implements MailTransport
{
    public array $sent = [];
    public function send(string $sender, array $recipients, string $data): void
    {
        $this->sent[] = ['sender' => $sender, 'recipients' => $recipients, 'data' => $data];
    }
}

// ── A. SenderIdentity ────────────────────────────────────────────────────

echo "A. SenderIdentity — the rule and the form rows\n";
$peter = new SenderIdentity('Max Muster', 'max.muster@example.ch');
check('A1 a display name and a deliverable address: no problem; the label «Name <email>»', $peter->problems() === [] && $peter->label() === 'Max Muster <max.muster@example.ch>');
check('A2 an empty name, an empty address are refused (both named)', count((new SenderIdentity('', ''))->problems()) === 2);
check('A3 an invalid / reserved-TLD address is refused', (new SenderIdentity('X', 'kein-at'))->problems() !== [] && (new SenderIdentity('X', 'a@b.invalid'))->problems() !== []);
$bad = array_map(fn(string $n) => (new SenderIdentity($n, 'a@z77.ch'))->problems() !== [], ["Eve\r\nBcc: x@y.ch", 'Eve "x"', 'Eve <a@b.ch>', 'support@bank.ch', 'Back\\slash', str_repeat('n', SenderIdentity::NAME_LENGTH + 1)]);
check('A4 a name with CR/LF, a quote, <>, @, a backslash or over 80 characters is refused', !in_array(false, $bad, true));
check('A5 umlauts and «&» are fine («Müller & Co»)', (new SenderIdentity('Müller & Co', 'info@mueller.ch'))->problems() === []);
$rows = SenderIdentity::listFromForm(['0' => '  Max   Muster ', '1' => '', 'n1' => "Büro\x07"], ['0' => ' Max.Muster@Example.ch ', '1' => '', 'n1' => 'buero@z77.ch']);
check('A6 listFromForm: posted order kept (server rows, then the added n1), an empty row dropped, text and address cleaned like the entity fields',
    array_map(fn(SenderIdentity $i) => $i->toArray(), $rows) === [['name' => 'Max Muster', 'email' => 'max.muster@example.ch'], ['name' => 'Büro', 'email' => 'buero@z77.ch']],
    json_encode(array_map(fn($i) => $i->toArray(), $rows)));
check('A7 listFromForm: a row with only an address stays (the validator names its missing name); junk input is no row',
    count(SenderIdentity::listFromForm(['k' => ''], ['k' => 'x@z77.ch'])) === 1 && SenderIdentity::listFromForm('x', null) === [] && SenderIdentity::listFromForm([['nested']], []) !== null);

// ── B. the entity ────────────────────────────────────────────────────────

echo "B. BackendUser::\$senderIdentities — stored rows, the file round trip\n";
$user = new BackendUser(['id' => 7, 'username' => 'peter', 'name' => 'Max Muster']);
check('B1 a user written before the field has no identity', $user->getSenderIdentities() === [] && $user->mapToArray()['sender_identities'] === []);
$user->setSenderIdentities([$peter, ['name' => 'Büro Muster', 'email' => 'buero@z77.ch'], 'junk', 42]);
check('B2 the setter takes objects and rows, in order; junk is dropped; the JSON key is snake_case `sender_identities`',
    $user->mapToArray()['sender_identities'] === [['name' => 'Max Muster', 'email' => 'max.muster@example.ch'], ['name' => 'Büro Muster', 'email' => 'buero@z77.ch']]);
$json = json_encode([$user->mapToArray()], JSON_UNESCAPED_UNICODE);
$back = new BackendUser(json_decode($json, true)[0]);
(new \ReflectionProperty(BackendUser::class, 'id'))->setValue($back, 7);   // the repository sets the id this way (no setter)
check('B3 the file round trip (mapToArray → JSON → mapFromArray, what FileStorage does) gives the same identities, the default first',
    count($back->getSenderIdentities()) === 2 && $back->getSenderIdentities()[0]->equals($peter) && $back->getSenderIdentities()[1]->name === 'Büro Muster');
$legacy = new BackendUser(['id' => 8, 'username' => 'alt', 'password_hash' => 'x', 'roles' => ['admin']]);
check('B4 a stored row without the key (older backendUsers.json) loads with []', $legacy->getSenderIdentities() === []);

// ── C. the validator ─────────────────────────────────────────────────────

echo "C. BackendUserValidator — the identities\n";
$validate = function (array $identities): BackendUserValidator {
    $u = new BackendUser(['id' => 7, 'username' => 'peter', 'roles' => ['admin']]);
    $u->setSenderIdentities($identities);
    $v = new BackendUserValidator($u);
    $v->isValid();
    return $v;
};
check('C1 none is valid (a user may have no identity — he then cannot send)', !$validate([])->hasFieldError('sender_identities'));
check('C2 two good rows are valid', !$validate([$peter, new SenderIdentity('Büro', 'buero@z77.ch')])->hasFieldError('sender_identities'));
$v = $validate([$peter, new SenderIdentity('', 'x@z77.ch')]);
check('C3 a bad second row: ONE field error «Absender 2: …», the row index for the «n Fehler» link', $v->hasFieldError('sender_identities') && str_starts_with($v->getFieldError('sender_identities'), 'Absender 2: Anzeigename fehlt') && $v->invalidSenderIdentity() === 1, $v->getFieldError('sender_identities'));
$v = $validate([$peter, new SenderIdentity('Max privat', 'MAX.MUSTER@example.ch')]);
check('C4 the same address twice (any case) is refused, naming the row it stands in', str_contains($v->getFieldError('sender_identities'), 'steht schon in Zeile 1'));
$many = array_map(fn(int $i) => new SenderIdentity('P' . $i, "p{$i}@z77.ch"), range(1, BackendUser::SENDER_IDENTITIES_MAX + 1));
check('C5 more than ' . BackendUser::SENDER_IDENTITIES_MAX . ' are refused', str_contains($validate($many)->getFieldError('sender_identities'), 'Höchstens'));

// ── D. the resolution ────────────────────────────────────────────────────

echo "D. SenderIdentities — default, choose() re-checks (Rule 7)\n";
$senders = new SenderIdentities($back);
check('D1 the default is the first; userId is the user\'s', $senders->default()?->equals($peter) === true && $senders->userId() === 7);
check('D2 choose(): the posted index of THIS user\'s list (string or int); absent = the default',
    $senders->choose('1')?->name === 'Büro Muster' && $senders->choose(0)?->equals($peter) && $senders->choose(null)?->equals($peter) && $senders->choose('')?->equals($peter));
check('D3 choose(): an index outside the list, a negative, junk or an array is refused (null)',
    $senders->choose('2') === null && $senders->choose('-1') === null && $senders->choose('1 OR 1') === null && $senders->choose(['0']) === null && $senders->choose(1.0) === null);
$tampered = new BackendUser(['id' => 9, 'username' => 'x', 'sender_identities' => [['name' => "Evil\nBcc", 'email' => 'e@z77.ch']]]);
check('D4 a stored identity edited by hand into something invalid is NOT chosen', (new SenderIdentities($tampered))->choose(0) === null);
$nobody = new SenderIdentities(null);
check('D5 no backend user (CLI, a member): no identity, nothing chosen, no link', $nobody->isEmpty() && $nobody->choose(0) === null && $nobody->userId() === null && $nobody->editUrl() === '');
$vm = (new SenderIdentities($legacy))->viewModel();
check('D6 the view model of a user without identity: no choices, the hint «Erfasse zuerst eine E-Mail an deinem User.»', $vm['identities'] === [] && $vm['missing'] === 'Erfasse zuerst eine E-Mail an deinem User.' && $vm['field'] === 'sender');
check('D7 the view model of a user with two: labels by index, the default preselected, no hint',
    $senders->viewModel()['identities'] === [0 => 'Max Muster <max.muster@example.ch>', 1 => 'Büro Muster <buero@z77.ch>'] && $senders->viewModel()['selected'] === 0 && $senders->viewModel()['missing'] === '');

// ── E. the kernel mail stack ─────────────────────────────────────────────

echo "E. Message / Mailer — the account sends, the person signs\n";
$transport = new RecordingTransport();
$mailer    = new Mailer($transport, 'no-reply@z77.ch', 'z77 GmbH');
$mailer->send((new Message())->to('kunde@example.ch')->subject('Rechnung 1 — z77')->text('Hallo')
    ->fromName('Max Muster')->replyTo('max.muster@example.ch', 'Max Muster'));
$data = $transport->sent[0]['data'] ?? '';
check('E1 envelope sender = the account; From = «"Max Muster" <no-reply@z77.ch>» (the identity\'s name, the ACCOUNT\'s address)',
    $transport->sent[0]['sender'] === 'no-reply@z77.ch' && preg_match('/^From: "Max Muster" <no-reply@z77\.ch>\r$/m', $data) === 1, $data);
check('E2 Reply-To = «"Max Muster" <max.muster@example.ch>»', preg_match('/^Reply-To: "Max Muster" <max\.muster@example\.ch>\r$/m', $data) === 1);
$mailer->send((new Message())->to('kunde@example.ch')->subject('A')->text('B')->replyTo('x@z77.ch'));
$data2 = $transport->sent[1]['data'] ?? '';
check('E3 without a name: From keeps the config\'s name, Reply-To is the bare address (unchanged behaviour)',
    preg_match('/^From: "z77 GmbH" <no-reply@z77\.ch>\r$/m', $data2) === 1 && preg_match('/^Reply-To: x@z77\.ch\r$/m', $data2) === 1);
$mailer->send((new Message())->to('kunde@example.ch')->subject('A')->text('B')->fromName('Jürg Müller')->replyTo('j@z77.ch', 'Jürg Müller'));
$data3 = $transport->sent[2]['data'] ?? '';
check('E4 a non-ASCII name is RFC 2047 encoded in From and Reply-To', preg_match('/^From: =\?UTF-8\?B\?[^?]+\?= <no-reply@z77\.ch>\r$/m', $data3) === 1 && preg_match('/^Reply-To: =\?UTF-8\?B\?[^?]+\?= <j@z77\.ch>\r$/m', $data3) === 1, $data3);
$threw = false;
try { (new Message())->fromName("Eve\r\nBcc: x@y.ch"); } catch (\InvalidArgumentException) { $threw = true; }
$threw2 = false;
try { (new Message())->replyTo('a@z77.ch', "Eve\nX"); } catch (\InvalidArgumentException) { $threw2 = true; }
check('E5 CR/LF in the From name or the Reply-To name throws (mail injection)', $threw && $threw2);
$transport4 = new RecordingTransport();
$threw3 = false;
try { (new Mailer($transport4, '', ''))->send((new Message())->to('k@example.ch')->subject('A')->text('B')->fromName('Peter')); } catch (\RuntimeException) { $threw3 = true; }
check('E6 a display name alone is no sender: without the config fromAddress the send still fails (no From address)', $threw3 && $transport4->sent === []);

// ── F. the user form ─────────────────────────────────────────────────────

echo "F. The user form — rows (core.js «rows»), the controller rebuilds the list\n";
$renderEdit = function (BackendUser $entry, BackendUserValidator $validator): string {
    return (new TplRenderer('Z77\\Module\\Backend'))->render('System/BackendUserController/edit', [
        'entry' => $entry, 'entityCsrf' => 't', 'roleLabels' => ['admin' => 'Admin'], 'validator' => $validator, 'passwordMinLength' => 8,
    ]);
};
$form = $renderEdit($back, new BackendUserValidator($back));
check('F1 the section «Absender für E-Mails» with one row per identity: identity_name[i] / identity_email[i] filled, a × per row',
    str_contains($form, 'Absender für E-Mails') && str_contains($form, 'name="identity_name[0]" value="Max Muster"') && str_contains($form, 'name="identity_email[1]" value="buero@z77.ch"')
    && substr_count($form, 'data-row-remove') === 3);
check('F2 the rows contract: a [data-rows] container, the template with __i__, the «+ Absender» adder; no grip (the order is the posted order)',
    preg_match('/data-rows="identity"/', $form) === 1 && str_contains($form, '<template data-row-template="identity">') && str_contains($form, 'name="identity_name[__i__]"')
    && str_contains($form, 'data-row-add="identity"') && !str_contains($form, 'data-row-grip'));
check('F3 the container prefix matches no field name (core.js renumbers `<prefix>[n]` only — the parallel maps keep their keys)',
    !str_contains($form, 'name="identity['));
$empty = new BackendUser(['id' => 3, 'username' => 'neu']);
$formEmpty = $renderEdit($empty, new BackendUserValidator($empty));
check('F4 a user without identity: no row, the hint that he cannot send without one', !str_contains($formEmpty, 'name="identity_name[0]"') && str_contains($formEmpty, 'Noch kein Absender'));
$invalidUser = new BackendUser(['id' => 7, 'username' => 'peter', 'roles' => ['admin']]);
$invalidUser->setSenderIdentities([$peter, new SenderIdentity('Ohne', 'kaputt')]);
$iv = new BackendUserValidator($invalidUser);
$iv->isValid();
$formErr = $renderEdit($invalidUser, $iv);
check('F5 a refused row: the error text under the section, that row marked aria-invalid, «n Fehler» targets it',
    str_contains($formErr, 'Absender 2:') && preg_match('/id="bu-identity-email-1"[^>]*aria-invalid="true"/', $formErr) === 1
    && preg_match('/id="bu-identity-email-0"[^>]*aria-invalid="false"/', $formErr) === 1 && str_contains($formErr, 'bu-identity-name-1'));
check('F6 hostile values are escaped in the form', (function () use ($renderEdit): bool {
    $u = new BackendUser(['id' => 1, 'username' => 'x']);
    $u->setSenderIdentities([new SenderIdentity('"><script>x</script>', 'a@z77.ch')]);
    $html = $renderEdit($u, new BackendUserValidator($u));
    return !str_contains($html, '<script>x') && str_contains($html, '&quot;&gt;&lt;script&gt;');
})());
$controller = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Controllers/System/BackendUserController.php');
check('F7 the controller rebuilds the identities from the form rows AFTER mapFromArray (the body\'s own `sender_identities` is overwritten, never trusted)',
    preg_match('/mapFromArray\(BodyCleaner::cleanFor\(BackendUser::class, \$body\)\);.*setSenderIdentities\(SenderIdentity::listFromForm\(\$body\[\'identity_name\'\] \?\? \[\], \$body\[\'identity_email\'\] \?\? \[\]\)\)/s', $controller) === 1);

// ── G. the shared partial ────────────────────────────────────────────────

echo "G. partials/senderIdentityField — «Senden als»\n";
$partial = fn(array $vm): string => (new TplRenderer('Z77\\Shared'))->render('partials/senderIdentityField', $vm);
$two = $partial($senders->viewModel());
check('G1 two identities: a select «Senden als» posting the INDEX, the default selected, labels escaped',
    str_contains($two, '<select id="sender-identity" name="sender">') && str_contains($two, '<option value="0" selected>Max Muster &lt;max.muster@example.ch&gt;</option>') && str_contains($two, '<option value="1">Büro Muster'));
$one = $partial((new SenderIdentities(new BackendUser(['id' => 2, 'username' => 'a', 'sender_identities' => [['name' => 'Anna', 'email' => 'anna@z77.ch']]])))->viewModel());
check('G2 exactly one: read-only text and the index as a hidden field, no select', str_contains($one, 'Anna &lt;anna@z77.ch&gt;') && str_contains($one, '<input type="hidden" name="sender" value="0">') && !str_contains($one, '<select'));
$none = $partial(['identities' => [], 'selected' => 0, 'missing' => SenderIdentities::MISSING, 'editUrl' => SenderIdentities::USER_ADMIN_URL, 'field' => 'sender']);
check('G3 none: the hint, the link to the user administration when reachable, NO field', str_contains($none, 'Erfasse zuerst eine E-Mail an deinem User.') && str_contains($none, 'href="/backend/system/backend-user/list"') && !str_contains($none, 'name="sender"'));
$noneNoLink = $partial(['identities' => [], 'selected' => 0, 'missing' => SenderIdentities::MISSING, 'editUrl' => '', 'field' => 'sender']);
check('G4 … without access to the user administration: the hint without a link', !str_contains($noneNoLink, '<a '));
check('G5 the partial carries no script (Rule 7)', stripos((string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/view/templates/partials/senderIdentityField.tpl.php'), '<script') === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
