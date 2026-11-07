<?php

/**
 * Member shell: the action cell's buttons a second time in the toolbar, phone only (CLI, no
 * bootstrap) — ADR-033 revision 2026-11-01. What is load-bearing: the REAL skeleton and action
 * partial render every kind of action into both places, the copy carries no id that would stand
 * twice, a page without actions gets no copy, and the CSS shows it only on a narrow screen.
 * What it cannot prove: how it looks; that takes a browser.
 *
 * Run: php tests/member-shell-actions.php
 */

require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
// The shell's labels come from the module dictionary (ADR-056).
require_once __DIR__ . '/../vendor/autoload.php';
if (!defined('ABS_BASE_PATH')) {
    define('ABS_BASE_PATH', str_replace('\\', '/', sys_get_temp_dir()) . '/z77-shell-actions-none');
}
require_once __DIR__ . '/support/TestTranslator.php';
\Z77\Tests\Support\TestTranslator::install(\Z77\Tests\Support\TestTranslator::packages('module-member'));

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

/** Just enough of the template renderer: the action partial is real, other partials render empty. */
final class ShellView
{
    private const TEMPLATES = __DIR__ . '/../packages/module-member/res/view/templates/';

    public function partial(string $name, array $vars = []): string
    {
        return $name === 'partials/shell/action' ? $this->render($name . '.tpl.php', $vars) : '';
    }

    public function render(string $file, array $vars): string
    {
        extract($vars);
        ob_start();
        include self::TEMPLATES . $file;

        return (string) ob_get_clean();
    }
}

/** The contents of the first div with exactly this class, up to its own closing tag. */
function block(string $html, string $class): string
{
    $open = strpos($html, '<div class="' . $class . '">');
    if ($open === false) {
        return '';
    }
    $depth = 0;
    preg_match_all('#<div\b|</div>#', $html, $tags, PREG_OFFSET_CAPTURE, $open);
    foreach ($tags[0] as [$tag, $at]) {
        $depth += $tag === '</div>' ? -1 : 1;
        if ($depth === 0) {
            return substr($html, $open, $at - $open);
        }
    }

    return '';
}

$actions = [
    ['label' => 'Speichern', 'submit' => 'profile-form'],
    ['label' => 'Neu', 'dialog' => 'new-dialog'],
    ['label' => 'Abmelden', 'href' => '/logout', 'method' => 'post', 'quiet' => true],
    ['label' => 'Zur Liste', 'href' => '/list'],
];
$page = fn(array $vars) => (new ShellView())->render('html-shell-skeleton.tpl.php', $vars + ['csrfToken' => 'token-1', 'main' => '<p>x</p>']);

$html  = $page(['shellActions' => $actions]);
$cell  = block($html, 'me-shell__act');
$copy  = block($html, 'me-shell__actions me-shell__actions--narrow');
$strip = fn(string $block) => trim((string) preg_replace('#^<div[^>]*>#', '', $block));

check('the cell holds the actions', str_contains($cell, 'Speichern') && str_contains($cell, 'Zur Liste'));
check('the copy is the same markup as the cell', $copy !== '' && $strip($copy) === $strip($cell));
check('the copy stands inside the toolbar', str_contains(block($html, 'me-shell__toolbar'), 'me-shell__actions--narrow'));
check('submit: both name the form from outside', substr_count($html, 'form="profile-form"') === 2);
check('dialog: both open it', substr_count($html, 'data-dialog-open="new-dialog"') === 2);
check('post: both carry their own form and CSRF field', substr_count($html, 'action="/logout"') === 2 && substr_count($html, 'value="token-1"') === 2);
check('link: both lead there', substr_count($html, 'href="/list"') === 2);
check('nothing in the copy has an id', !preg_match('/\sid="/', $copy));

$legacy = $page(['shellAction' => ['label' => 'Einzeln', 'href' => '/one']]);
check('the legacy single action comes along', substr_count($legacy, 'href="/one"') === 2);

$work = $page(['shellWorkActions' => [['label' => 'Am Gezeigten', 'href' => '/work']], 'railItems' => [['label' => 'x']]]);
check('no action in the cell: no copy', !str_contains($work, 'me-shell__actions--narrow'));
check('work actions stay single', substr_count($work, 'href="/work"') === 1);

$scss = (string) file_get_contents(__DIR__ . '/../packages/module-member/res/scss/member.scss');
$narrowAt = strpos($scss, '@media (max-width: 60rem) {' . "\n" . '    .me-body--shell:not(.me-body--plain)');
$narrowAt = $narrowAt === false ? strpos($scss, "@media (max-width: 60rem) {\r\n    .me-body--shell:not(.me-body--plain)") : $narrowAt;
$hidden   = strpos($scss, '&__actions--narrow { display: none; }');
$shown    = strpos($scss, '.me-shell__actions--narrow {');
check('wide: hidden', $hidden !== false && $narrowAt !== false && $hidden < $narrowAt);
check('narrow: shown, inside the narrow block', $shown !== false && $shown > (int) $narrowAt && preg_match('/\.me-shell__actions--narrow \{\s+display: flex;/', $scss) === 1);

$css = (string) file_get_contents(__DIR__ . '/../packages/module-member/res/assets/css/member.css');
check('member.css is built from it', str_contains($css, 'me-shell__actions--narrow'));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
