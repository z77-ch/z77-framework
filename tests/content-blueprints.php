<?php

/**
 * Content blueprints harness (CLI) — ADR-044.
 *
 * What is load-bearing here:
 *
 *   - the LEGACY path is unchanged: InlineMarkdown without a profile and a
 *     BlockView without schema format bold, italic and links as before — every
 *     existing site renders through it;
 *   - a schema-aware field WITHOUT `inline` is plain text: markdown stays literal;
 *   - link targets are gated per field; `//host` is never a link (it was, in
 *     legacy — the content rule already forbade it);
 *   - `localize` runs page links (only) through the localiser; media, external
 *     and anchors are untouched;
 *   - `action:<name>` renders the declared fallback + attributes; an unknown
 *     action stays literal text;
 *   - Blueprint::enforce() saves exactly one block per slot in slot order, takes
 *     type/key from the slot, and takes orphans from the STORED blocks only — a
 *     crafted body can neither add nor remove a block.
 *
 * Run: php tests/content-blueprints.php
 * No DI, no composer install: the classes are pure and required directly.
 */

$src = __DIR__ . '/../packages/kernel/shared/src/Content/';
foreach (['InlineProfile', 'InlineMarkdown', 'BlockView', 'BlockSchemaValidator', 'Blueprint'] as $class) {
    require $src . $class . '.php';
}

use Z77\Shared\Content\BlockSchemaValidator;
use Z77\Shared\Content\BlockView;
use Z77\Shared\Content\Blueprint;
use Z77\Shared\Content\InlineMarkdown;
use Z77\Shared\Content\InlineProfile;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$md = new InlineMarkdown();
$fr = static fn(string $path): string => '/fr' . ($path === '/kontakt' ? '/contact' : $path);
$actions = ['contact' => ['href' => '/kontakt', 'attributes' => ['data-apply-open' => '', 'data-x' => 'a"b', 'on click' => 'x']]];

echo "InlineMarkdown — legacy (no profile)\n";
$out = $md->toHtml('**fett** *kursiv* [Seite](/wohnen) <b>x</b>');
check('bold, italic, link, escaped html', $out === '<strong>fett</strong> <em>kursiv</em> <a href="/wohnen">Seite</a> &lt;b&gt;x&lt;/b&gt;', $out);
$out = $md->toHtml('[böse](javascript:alert(1)) [pr](//evil.example)');
check('javascript: and //host stay literal', !str_contains($out, '<a'), $out);
$out = $md->toHtml("Zeile 1\nZeile 2");
check('legacy has no <br>', !str_contains($out, '<br>'), $out);

echo "InlineMarkdown — profiles\n";
$out = $md->toHtml('**fett** [Seite](/wohnen)', InlineProfile::plain());
check('plain profile: all literal', $out === '**fett** [Seite](/wohnen)', $out);
$out = $md->toHtml("**fett** *k*\nneu", new InlineProfile(['bold', 'break']));
check('bold + break only, break is exactly <br>', $out === "<strong>fett</strong> *k*<br>neu", $out);
$tabs = new InlineProfile(['link'], ['external', 'tel', 'mailto'], false, [], null, true);
$out = $md->toHtml('[W](https://x.ch) [T](tel:+41445212191) [M](mailto:a@b.ch)', $tabs);
check('newTab only on external; tel links work',
    $out === '<a href="https://x.ch" target="_blank" rel="noopener">W</a> <a href="tel:+41445212191">T</a> <a href="mailto:a@b.ch">M</a>', $out);

$links = new InlineProfile(['link'], ['page', 'media'], true, $actions, $fr);
$out = $md->toHtml('[K](/kontakt) [P](/media/front/a.pdf) [A](#top)', $links);
check('page localised, media + anchor untouched',
    $out === '<a href="/fr/contact">K</a> <a href="/media/front/a.pdf">P</a> <a href="#top">A</a>', $out);
$out = $md->toHtml('[E](https://example.com) [M](mailto:a@b.ch) [X](action:contact)', $links);
check('external, mailto, action not allowed → literal', !str_contains($out, '<a'), $out);
$noLoc = new InlineProfile(['link'], ['page'], false, [], $fr);
$out = $md->toHtml('[K](/kontakt)', $noLoc);
check('localize=false leaves the canonical path', $out === '<a href="/kontakt">K</a>', $out);
$out = $md->toHtml('[Q](/wohnen?a=1&b=2)', $links);
check('query string survives localise + re-escape', $out === '<a href="/fr/wohnen?a=1&amp;b=2">Q</a>', $out);

$act = new InlineProfile(['link'], ['action'], true, $actions, $fr);
$out = $md->toHtml('[unser Kontaktformular](action:contact)', $act);
check('action: fallback localised, attributes escaped, bad name dropped',
    $out === '<a href="/fr/contact" data-apply-open data-x="a&quot;b">unser Kontaktformular</a>', $out);
$out = $md->toHtml('[x](action:nope)', $act);
check('unknown action → literal', $out === '[x](action:nope)', $out);
$out = $md->toHtml('[x](//evil.example)', new InlineProfile(['link'], InlineProfile::TARGETS));
check('//host rejected under every profile', !str_contains($out, '<a'), $out);

echo "InlineProfile::fromDescriptor\n";
$p = InlineProfile::fromDescriptor(['key' => 'copy']);
check('no inline key → plain', $p->features() === []);
$p = InlineProfile::fromDescriptor(['inline' => ['bold', 'script', 'link']]);
check('unknown features dropped', $p->features() === ['bold', 'link']);
check('link default targets exclude action', $p->allowsTarget('page') && !$p->allowsTarget('action'));

echo "BlockView\n";
$block  = ['type' => 'faq', 'title' => '**T**', 'copy' => '[K](/kontakt)', 'rows' => [['term' => 'A', 'copy' => '**x**']], 'paras' => ['[K](/kontakt)', '*i*']];
$schema = [
    ['key' => 'title', 'kind' => 'text'],
    ['key' => 'copy',  'kind' => 'textarea', 'inline' => ['link'], 'links' => ['targets' => ['page'], 'localize' => true]],
    ['key' => 'rows',  'kind' => 'list', 'item' => [['key' => 'term', 'kind' => 'text'], ['key' => 'copy', 'kind' => 'textarea', 'inline' => ['bold']]]],
    ['key' => 'paras', 'kind' => 'list', 'item' => 'textarea', 'inline' => ['link'], 'links' => ['localize' => true]],
];
$legacy = new BlockView($block);
check('legacy view formats every field', $legacy->html('title') === '<strong>T</strong>');
$view = new BlockView($block, true, $schema, $actions, $fr);
check('schema view: field without inline is plain', $view->html('title') === '**T**', $view->html('title'));
check('schema view: link localised', $view->html('copy') === '<a href="/fr/contact">K</a>', $view->html('copy'));
$rows = $view->list('rows');
check('object rows inherit the item schema', $rows[0]->html('copy') === '<strong>x</strong>' && $rows[0]->html('term') === 'A');
check('listHtml formats scalar items under the list profile',
    $view->listHtml('paras') === ['<a href="/fr/contact">K</a>', '*i*'], implode(' | ', $view->listHtml('paras')));
check('unknown field on schema view is plain', $view->html('nope') === '');

echo "BlockSchemaValidator\n";
$v = new BlockSchemaValidator();
$schema = [
    ['key' => 'title', 'label' => 'Titel', 'kind' => 'text', 'required' => true, 'maxLength' => 10],
    ['key' => 'link',  'label' => 'Link',  'kind' => 'url'],
    ['key' => 'rows',  'label' => 'Zeilen', 'kind' => 'list', 'min' => 1, 'max' => 2,
     'item' => [['key' => 'term', 'label' => 'Begriff', 'kind' => 'text', 'required' => true]]],
];
check('valid block → no errors', $v->errors(['title' => 'Hallo', 'link' => '/x', 'rows' => [['term' => 'A']]], $schema) === []);
$errors = $v->errors(['title' => '', 'link' => '//evil', 'rows' => []], $schema, 'Intro');
check('required, url, min reported with context', count($errors) === 3 && str_starts_with($errors[0], 'Intro: «Titel»'), implode(' | ', $errors));
$errors = $v->errors(['title' => 'Viel zu langer Titel', 'rows' => [['term' => 'A'], ['term' => ''], ['term' => 'C']]], $schema);
check('maxLength, max and row field reported', count($errors) === 3, implode(' | ', $errors));

echo "Blueprint\n";
$bp = new Blueprint('faq', [
    ['key' => 'intro', 'type' => 'intro', 'label' => 'Einleitung'],
    ['key' => 'rows',  'type' => 'faqTable'],
]);
$schemas = ['intro' => [['key' => 'title', 'kind' => 'text', 'default' => 'Neu'], ['key' => 'items', 'kind' => 'list']], 'faqTable' => []];
$stored = [
    ['type' => 'faqTable', 'key' => 'rows', 'rows' => ['a']],
    ['type' => 'text', 'content' => 'orphan without key'],
    ['type' => 'hero', 'key' => 'intro', 'title' => 'wrong type for slot'],
];
$rows = $bp->arrange($stored, $schemas);
check('arrange: slot order, fresh block for a mismatched slot',
    $rows[0]['slot']['key'] === 'intro' && $rows[0]['block'] === ['type' => 'intro', 'key' => 'intro', 'title' => 'Neu', 'items' => []]
    && $rows[1]['block']['rows'] === ['a']);
check('arrange: orphans last, kept verbatim', count($rows) === 4 && $rows[2]['slot'] === null && $rows[3]['block']['type'] === 'hero');

$posted = [
    ['type' => 'hero', 'key' => 'intro', 'title' => 'Posted'],
    ['type' => 'script', 'key' => 'evil'],
];
$saved = $bp->enforce($posted, $stored, $schemas);
check('enforce: type forced from slot', $saved[0] === ['type' => 'intro', 'key' => 'intro', 'title' => 'Posted']);
check('enforce: slot missing in post keeps the stored block', $saved[1]['rows'] === ['a']);
check('enforce: posted extra block ignored, stored orphans kept',
    count($saved) === 4 && !in_array('script', array_column($saved, 'type'), true));
check('enforce: labels default to the key', $bp->slot('rows')['label'] === 'rows');

try {
    new Blueprint('x', [['key' => 'a', 'type' => 't'], ['key' => 'a', 'type' => 't']]);
    check('duplicate slot key throws', false);
} catch (InvalidArgumentException) {
    check('duplicate slot key throws', true);
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
