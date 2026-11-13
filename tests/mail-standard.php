<?php

/**
 * The e-mail standard harness (CLI, offline, no database) — owner decision 2026-11-13, board
 * «E-Mail-Standard»: every mail the system sends by machine says so; a document mail opens with a
 * fact line and the Briefanrede of the address and closes with the sender identity; every
 * template mail ends with a divider and the company. What is load-bearing here:
 *
 *   - (A) `LetterSalutation`: the address's Briefanrede wins; else «Guten Tag Herr Meier» in
 *     German (salutation + title + name), «Guten Tag» without a person; other languages greet alone;
 *   - (B) `emails/documentMail`: the bold fact line, the escaped paragraphs, the closing block
 *     (`data-z77-closing`) with name / e-mail / phone of the identity — no phone, no line; no
 *     identity, the note alone;
 *   - (C) `emails/layout`: the note for a body without its own closing (system mails), none twice;
 *     the divider and the company (name, address line, contact line) — none without a company;
 *   - (D) the text half (`HtmlToText`): fact line, text, closing and company read as lines and
 *     paragraphs; no extra URL;
 *   - (E) `MailCompany`: the line join, the registry (one source; two refused loud).
 *
 * Run: php tests/mail-standard.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "mail-standard: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Shared\Mail\HtmlToText;
use Z77\Shared\Mail\LetterSalutation;
use Z77\Shared\Mail\MailClosing;
use Z77\Shared\Mail\MailCompany;
use Z77\Shared\Mail\MailCompanySource;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Renders a kernel mail template with $context, as EmailService does (closure scope, EXTR_SKIP). */
function render(string $template, array $context): string
{
    $file = __DIR__ . '/../packages/kernel/shared/res/view/templates/' . $template . '.tpl.php';
    return (static function (string $z77TplPath, array $z77TplContext): string {
        extract($z77TplContext, EXTR_SKIP);
        ob_start();
        include $z77TplPath;
        return (string) ob_get_clean();
    })($file, $context);
}

// ── A. LetterSalutation ─────────────────────────────────────────────────

echo "A. LetterSalutation — {briefanrede}\n";
check('A1 the address\'s Briefanrede wins, as written (white space collapsed to one line)',
    LetterSalutation::resolve('Sehr geehrter Herr Meier', 'Herr', '', 'Meier', 'de') === 'Sehr geehrter Herr Meier'
    && LetterSalutation::resolve("  Liebe\nLulu ", '', '', '', 'en') === 'Liebe Lulu');
check('A2 empty in German: «Guten Tag» + salutation + title + name',
    LetterSalutation::resolve('', 'Herr', '', 'Meier', 'de') === 'Guten Tag Herr Meier'
    && LetterSalutation::resolve('', 'Frau', 'Dr.', 'Muster', 'de') === 'Guten Tag Frau Dr. Muster');
check('A3 no person — no name, no salutation, the salutation «Firma» — reads «Guten Tag»',
    LetterSalutation::resolve('', 'Herr', '', '', 'de') === 'Guten Tag' && LetterSalutation::resolve('', '', '', 'Muster AG', 'de') === 'Guten Tag'
    && LetterSalutation::resolve('', 'Firma', '', 'Muster AG', 'de') === 'Guten Tag');
check('A4 other languages greet alone (the German salutation field is not put into a foreign sentence); an unknown language reads German',
    LetterSalutation::resolve('', 'Herr', '', 'Meier', 'en') === 'Hello' && LetterSalutation::resolve('', 'Herr', '', 'Meier', 'fr') === 'Bonjour'
    && LetterSalutation::resolve('', 'Herr', '', 'Meier', 'xx') === 'Guten Tag Herr Meier');

// ── B. emails/documentMail ──────────────────────────────────────────────

echo "B. emails/documentMail — fact line, text, closing\n";
$sabine = ['name' => 'Sabine Sachbearbeiter', 'email' => 'sabine@muster.example', 'phone' => '+41 44 123 45 67'];
$doc = render('emails/documentMail', [
    'body' => "Sehr geehrter Herr Meier\n\nDie Rechnung 26100012 liegt als PDF bei, mit QR-Einzahlungsschein.\n\nBesten Dank für die <Zusammenarbeit>.",
    'facts' => 'Rechnung 26100012 · CHF 640.00 · zahlbar bis 13.12.2026', 'sender' => $sabine, 'mailLanguage' => 'de',
]);
check('B1 the fact line on top, bold; then the paragraphs, escaped',
    str_contains($doc, 'font-weight:bold;">Rechnung 26100012 · CHF 640.00 · zahlbar bis 13.12.2026</p>')
    && strpos($doc, 'Rechnung 26100012 ·') < strpos($doc, 'Sehr geehrter Herr Meier') && str_contains($doc, '&lt;Zusammenarbeit&gt;'));
check('B2 the closing block (data-z77-closing): «Diese Nachricht wurde maschinell erstellt. Fragen beantwortet gerne:», the name bold, e-mail and phone — plain text, no mailto link',
    str_contains($doc, MailClosing::MARKER) && str_contains($doc, 'Diese Nachricht wurde maschinell erstellt. Fragen beantwortet gerne:<br><strong style="color:#222222;">Sabine Sachbearbeiter</strong><br>sabine@muster.example<br>+41 44 123 45 67</p>')
    && !str_contains($doc, 'mailto:'));
$noPhone = render('emails/documentMail', ['body' => 'Liebe Lulu', 'facts' => '', 'sender' => ['name' => 'Peter', 'email' => 'peter@muster.example', 'phone' => ''], 'mailLanguage' => 'de']);
check('B3 no phone → no line; no fact line → none',
    str_contains($noPhone, '<strong style="color:#222222;">Peter</strong><br>peter@muster.example</p>') && !str_contains($noPhone, 'font-weight:bold'));
$noSender = render('emails/documentMail', ['body' => 'Hello', 'mailLanguage' => 'en']);
check('B4 no identity: the note alone, in the mail\'s language',
    str_contains($noSender, '>This message was generated automatically.</p>') && !str_contains($noSender, 'Questions'));

$enText = render('emails/documentMail', ['body' => 'Hello', 'language' => 'en', 'sender' => $sabine, 'mailLanguage' => 'de']);
check('B5 the closing speaks the TEXT\'s language (`language` from documentMail()), also where the translator scope fell back to German',
    str_contains($enText, 'This message was generated automatically. Questions are gladly answered by:'));

// ── C. emails/layout ────────────────────────────────────────────────────

echo "C. emails/layout — the note for system mails, the divider and the company\n";
$company = new MailCompany('Muster AG', 'Bahnhofstrasse 12 · 8001 Zürich', '+41 44 123 45 00 · info@muster.example · muster.example');
$system  = render('emails/layout', ['emailBody' => '<p>Dein Anmeldecode: 482 913</p>', 'subject' => 'Code', 'mailLanguage' => 'de', 'mailCompany' => $company]);
check('C1 a body without its own closing (the app code, «ZIP bereit»): the layout adds «Diese Nachricht wurde maschinell erstellt.» — once',
    substr_count($system, 'Diese Nachricht wurde maschinell erstellt.') === 1 && !str_contains($system, 'Fragen beantwortet'));
check('C2 the divider and the company: the full name bold, the address line, the contact line — smaller and grey, after the note',
    str_contains($system, 'border-top:3px solid #9a9aa8') && str_contains($system, '<strong style="color:#444444;">Muster AG</strong><br>Bahnhofstrasse 12 · 8001 Zürich<br>+41 44 123 45 00 · info@muster.example · muster.example</p>')
    && str_contains($system, 'font-size:11.5px') && strpos($system, 'maschinell erstellt') < strpos($system, 'Muster AG'));
$wrapped = render('emails/layout', ['emailBody' => $doc, 'subject' => 'Rechnung', 'mailLanguage' => 'de', 'mailCompany' => $company]);
check('C3 a document mail (its own closing): no second note; the company after the closing block',
    substr_count($wrapped, 'Diese Nachricht wurde maschinell erstellt.') === 1 && strpos($wrapped, 'Fragen beantwortet gerne') < strpos($wrapped, 'Muster AG</strong>'));
$noCompany = render('emails/layout', ['emailBody' => '<p>x</p>', 'subject' => 'x', 'mailLanguage' => 'de']);
check('C4 no company (no module registers one, nothing filled): no divider, no foot — the note stays',
    !str_contains($noCompany, 'border-top:3px') && str_contains($noCompany, 'maschinell erstellt'));
$nameOnly = render('emails/layout', ['emailBody' => '<p>x</p>', 'subject' => 'x', 'mailLanguage' => 'de', 'mailCompany' => new MailCompany('Muster AG')]);
check('C5 only the filled parts: a company with a name alone ends after the name (no empty line)',
    str_contains($nameOnly, '<strong style="color:#444444;">Muster AG</strong></p>'));

// ── D. the text half ────────────────────────────────────────────────────

echo "D. HtmlToText — the text half of the standard\n";
$text = (new HtmlToText())->convert($wrapped);
check('D1 the text half reads: fact line, Briefanrede, text, closing lines, company lines — paragraphs apart, the closing lines together',
    str_contains($text, "Rechnung 26100012 · CHF 640.00 · zahlbar bis 13.12.2026\r\n\r\nSehr geehrter Herr Meier\r\n\r\nDie Rechnung 26100012")
    && str_contains($text, "Diese Nachricht wurde maschinell erstellt. Fragen beantwortet gerne:\r\nSabine Sachbearbeiter\r\nsabine@muster.example\r\n+41 44 123 45 67")
    && str_contains($text, "Muster AG\r\nBahnhofstrasse 12 · 8001 Zürich\r\n+41 44 123 45 00 · info@muster.example · muster.example"), json_encode($text, JSON_UNESCAPED_UNICODE));
check('D2 no URL appears in the text half that the HTML half does not carry (no link at all)', !str_contains($text, '(http') && !str_contains($text, '(mailto'));

// ── E. MailCompany ──────────────────────────────────────────────────────

echo "E. MailCompany — the line join and the registry\n";
check('E1 joinLine keeps the filled parts, white space collapsed, « · » between',
    MailCompany::joinLine('', 'Bahnhofstrasse  12', ' ', '8001 Zürich') === 'Bahnhofstrasse 12 · 8001 Zürich' && MailCompany::joinLine('', ' ') === '');
check('E2 a company with nothing filled is empty (no foot)', (new MailCompany(' ', '', ''))->isEmpty() && !(new MailCompany('X'))->isEmpty());

final class FakeCompanySource implements MailCompanySource
{
    public function company(): ?MailCompany { return new MailCompany('Fake AG', 'Weg 1 · 3000 Bern'); }
}
final class ThrowingCompanySource implements MailCompanySource
{
    public function company(): ?MailCompany { throw new \RuntimeException('no table'); }
}
$sourceOf = static fn(array $byModule) => MailCompany::sourceClassFrom($byModule);
check('E3 one module declares the source: its class; none: null',
    $sourceOf(['mandator' => FakeCompanySource::class, 'debtor' => null]) === FakeCompanySource::class && $sourceOf(['debtor' => null]) === null);
$twice = null;
try { $sourceOf(['a' => FakeCompanySource::class, 'b' => FakeCompanySource::class]); } catch (\LogicException $e) { $twice = $e->getMessage(); }
$bad = null;
try { $sourceOf(['a' => \stdClass::class]); } catch (\LogicException $e) { $bad = $e->getMessage(); }
check('E4 two modules declaring a source, or a class that is no MailCompanySource, are refused loud (LogicException)',
    $twice !== null && str_contains($twice, 'declared twice') && $bad !== null && str_contains($bad, 'MailCompanySource'));
check('E5 a source that throws answers «no company» (logged) — a footer never stops a mail',
    MailCompany::fromSource(ThrowingCompanySource::class) === null && MailCompany::fromSource(FakeCompanySource::class)?->name === 'Fake AG');

// ── F. the fact line: declaration, record, editor ────────────────────────

echo "F. the fact line — declaration, operator record, editor\n";
$kinds = new \Z77\Shared\Mail\DocumentMailKinds(['invoice' => ['label' => 'Rechnung', 'subject' => ['de' => 'R {nummer}'], 'body' => ['de' => 'T'], 'facts' => ['de' => 'Rechnung {nummer}']]]);
$bare  = new \Z77\Shared\Mail\DocumentMailKinds(['credit-note' => ['label' => 'Gutschrift', 'subject' => ['de' => 'G'], 'body' => ['de' => 'T']]]);
$broken = null;
try { new \Z77\Shared\Mail\DocumentMailKinds(['x' => ['label' => 'X', 'subject' => ['de' => 'X'], 'body' => ['de' => 'T'], 'facts' => ['de' => "eins\nzwei"]]]); } catch (\RuntimeException $e) { $broken = $e->getMessage(); }
check('F1 a kind declares its fact line per language (optional — none reads []); a line break in it is refused at declaration',
    $kinds->get('invoice')['facts'] === ['de' => 'Rechnung {nummer}'] && $bare->get('credit-note')['facts'] === [] && $broken !== null && str_contains($broken, 'ONE line'));
$record = new \Z77\Shared\Entities\EmailDocumentSetting(['kind' => 'invoice', 'subjects' => ['de' => 'S'], 'bodies' => ['de' => 'B'], 'facts' => ['de' => "Zeile\nzwei"]]);
$v = new \Z77\Shared\Validators\EmailDocumentSettingValidator($record);
$long = new \Z77\Shared\Entities\EmailDocumentSetting(['kind' => 'invoice', 'subjects' => ['de' => 'S'], 'bodies' => ['de' => 'B'], 'facts' => ['de' => str_repeat('x', 201)]]);
$vl = new \Z77\Shared\Validators\EmailDocumentSettingValidator($long);
$ok = new \Z77\Shared\Entities\EmailDocumentSetting(['kind' => 'invoice', 'subjects' => ['de' => 'S'], 'bodies' => ['de' => 'B'], 'facts' => ['de' => '', 'en' => 'Invoice {nummer}']]);
$vo = new \Z77\Shared\Validators\EmailDocumentSettingValidator($ok);
check('F2 the operator\'s record keeps `facts` (snake_case key, round trip); the editor\'s validator refuses a line break and more than 200 characters on `facts`, an empty fact line is fine',
    $record->mapToArray()['facts'] === ['de' => "Zeile\nzwei"] && (new \Z77\Shared\Entities\EmailDocumentSetting(['kind' => 'x']))->getFacts() === []
    && !$v->isValid() && $v->hasFieldError('facts') && !$vl->isValid() && $vl->hasFieldError('facts') && $vo->isValid());
$editor = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/Service/EmailSettingsController/documentEdit.tpl.php');
check('F3 the editor (Service › E-Mail › Beleg-Mails) offers «Faktenzeile» per language (`facts[<lang>]`) and says the text opens with {briefanrede} without greeting formula or signature',
    str_contains($editor, 'name="facts[<?= e($language) ?>]"') && str_contains($editor, 'Faktenzeile') && str_contains($editor, '{briefanrede}') && str_contains($editor, 'Gruss und Unterschrift gehören nicht hinein'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
