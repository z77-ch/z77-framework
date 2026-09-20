<?php

/**
 * HtmlToText harness (CLI) — the plain-text half of every HTML mail.
 *
 * What is load-bearing here:
 *
 *   - URL PARITY: the text half carries as many addresses as the HTML half,
 *     because a receiver's spam filter compares the two (`URI_COUNT_ODD`,
 *     MAIL-SPAM-001). A button link must survive as `label (href)`, and a
 *     LINKED IMAGE — no label at all — must survive too;
 *   - …without DOUBLING one: a label that already spells the address out
 *     (markup around it, or a trailing slash apart) keeps the label alone;
 *   - the one exception: a bare `#fragment` points into a document the text
 *     half does not have, and no receiver counts it;
 *   - the space AFTER an inline closing tag stays (`</strong> Auf` —
 *     MAIL-TEXT-001), while the markup's indentation at the start of a line
 *     goes. Both are whitespace after a `>`; only the position tells them
 *     apart, which is why the pass runs at the end.
 *
 * The parity checks count `href=` in the HTML against `://` in the text, the
 * way a filter does — not the way the converter does.
 *
 * Run: php tests/mail-html-to-text.php
 * No file system, no DI, no composer install: the class is a pure function
 * object, so the harness requires the one file and calls it.
 */

require __DIR__ . '/../packages/kernel/shared/src/Mail/HtmlToText.php';

use Z77\Shared\Mail\HtmlToText;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** What a spam filter compares: addresses in the HTML half vs. in the text half. */
function parity(string $html, string $text): bool
{
    return substr_count($html, 'href="http') === substr_count($text, 'http');
}

$convert = static fn (string $html): string => (new HtmlToText())->convert($html);

echo "\nHtmlToText\n\n";

// ── the login mail's button link ────────────────────────────────────────────

$html = '<p>Zum Anmelden:</p>'
      . '<p><a href="https://axo3.ch/member/main/login/redeem?token=a1b2c3" '
      . 'style="display:inline-block;">Anmeldung fortsetzen</a></p>';
$text = $convert($html);

check('label stays',        str_contains($text, 'Anmeldung fortsetzen'));
check('address stays',      str_contains($text, '(https://axo3.ch/member/main/login/redeem?token=a1b2c3)'));
check('one URL, one URL',   parity($html, $text));

// ── no doubling where the label IS the address ──────────────────────────────

$text = $convert('<p><a href="https://axo3.ch/login">https://axo3.ch/login</a></p>');
check('label = address: no parentheses', !str_contains($text, '('));
check('…and the address is there once',  substr_count($text, 'https://axo3.ch/login') === 1);

$text = $convert('<p><a href="https://axo3.ch/"><strong>https://axo3.ch</strong></a></p>');
check('markup in the label does not double it', substr_count($text, 'https://axo3.ch') === 1);

$text = $convert('<p><a href="https://axo3.ch/">https://axo3.ch</a></p>');
check('a trailing slash does not double it',    substr_count($text, 'https://axo3.ch') === 1);

// ── the exceptions ──────────────────────────────────────────────────────────

$text = $convert('<p><a href="#top">Nach oben</a></p>');
check('fragment: label only',  trim($text) === 'Nach oben');

$text = $convert('<p><a href="mailto:info@example.ch">Schreiben Sie uns</a></p>');
check('mailto keeps its address', str_contains($text, '(mailto:info@example.ch)'));

$html = '<p><a href="https://axo3.ch/bild"><img src="https://axo3.ch/logo.png" alt=""></a></p>';
$text = $convert($html);
check('linked image keeps the address', str_contains($text, '(https://axo3.ch/bild)'));
check('…so parity holds without a label', substr_count($text, 'https://axo3.ch/bild') === 1);

$text = $convert('<p><a name="anker">Kein Ziel</a></p>');
check('anchor without href: text only', trim($text) === 'Kein Ziel');

$html = '<p>Eins: <a href="https://a.example/x">A</a> und zwei: <a href="https://b.example/y">B</a></p>';
$text = $convert($html);
check('two links, two addresses', parity($html, $text));

// ── MAIL-TEXT-001: the space after an inline closing tag ────────────────────

$text = $convert('<p><strong>Vergleichen Sie zuerst:</strong> Auf dem Bildschirm …</p>');
check('space after </strong> stays', str_contains($text, 'zuerst: Auf'));

$text = $convert("<p>\n    Eingerückt im Markup\n</p>\n<p>\n    Zweiter Absatz\n</p>");
check('indentation at the line start goes', !str_contains($text, "\n "));
check('…and both lines are there',
    str_contains($text, 'Eingerückt im Markup') && str_contains($text, 'Zweiter Absatz'));

// ── entities ────────────────────────────────────────────────────────────────

$text = $convert('<p><a href="https://axo3.ch/s?a=1&amp;b=2">Suche</a></p>');
check('&amp; in the href is decoded', str_contains($text, '(https://axo3.ch/s?a=1&b=2)'));

$text = $convert('<p>Preis:&nbsp;10&nbsp;Franken</p>');
check('&nbsp; becomes a plain space', str_contains($text, 'Preis: 10 Franken'));

echo "\n";
echo $fail === 0
    ? "PASS — {$pass} checks\n"
    : "FAIL — {$fail} of " . ($pass + $fail) . " checks failed\n";

exit($fail === 0 ? 0 : 1);
