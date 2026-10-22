<?php

/**
 * Frontend starter pages harness (CLI) — ADR-044 addendum 2026-10-22.
 *
 * What is load-bearing here:
 *
 *   - every page blueprint in frontendConfig has a German AND an English seed
 *     (kernel core/data/content/<slug>.<lang>.default.json), whose blocks are
 *     exactly the blueprint's slots (key, type, order) and pass the schema
 *     validation the backend runs on save; no French seed, no seed without a
 *     blueprint;
 *   - the starter is neutral: no «z77», no «wir» / «we» / «our», no «DSGVO» /
 *     «GDPR» in any seed nor in the visible text of the frontend templates;
 *   - the templates hold no copy: with PHP and tags stripped nothing is left
 *     but whitespace and the © entity;
 *   - each IndexController action reads its document through page() and names
 *     every slot of its blueprint; every PageSections mapper hands the slot's
 *     `edit` marker on, and every section partial prints `edit` on its root;
 *   - rendered from the seeds, a section partial shows the document's text,
 *     escaped, and the page-editor marker only for an editor (a visitor's HTML
 *     carries none);
 *   - the contact page still renders the public form with the handler's view
 *     context (form, fields, errors, formError, checkUrl, csrfToken).
 *
 * Run: php tests/frontend-starter-pages.php
 * No DI: the classes are required through a PSR-4 map of this checkout; the
 * three template helpers are stubbed.
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Module\\Frontend\\' => '/../packages/module-frontend/src/',
        'Z77\\Shared\\'           => '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\'      => '/../packages/kernel/persistence/src/',
        'Z77\\Core\\'             => '/../packages/kernel/core/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__ . $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

// Template helpers (the real ones live in core autoload/prod/php/Helper.php and need DI).
function e(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'); }
function raw(?string $value): string { return $value ?? ''; }
function localizedUrl(string $url, ?string $language = null): string { return '/en' . $url; }

use Z77\Module\Frontend\Ui\Services\PageSections;
use Z77\Shared\Content\BlockSchemaValidator;
use Z77\Shared\Content\Blueprint;
use Z77\Shared\Content\ContentView;
use Z77\Shared\Content\DefaultBlockRegistry;
use Z77\Shared\Entities\Content;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

$root      = str_replace('\\', '/', realpath(__DIR__ . '/..'));
$frontend  = $root . '/packages/module-frontend';
$seedDir   = $root . '/packages/kernel/core/data/content';
$config    = require $frontend . '/src/App/Config/frontendConfig.inc.php';
$blueprints = $config['contentBlueprints'] ?? [];
$languages  = ['de', 'en'];

// type ⇒ schema, from the module's renderers plus the core types.
$schemas = [];
foreach (DefaultBlockRegistry::create()->schemas() as $type => $schema) {
    $schemas[$type] = $schema;
}
foreach ($config['contentBlocks'] as $class) {
    $renderer = new $class();
    $schemas[$renderer->type()] = $renderer->schema();
}

/** Every string value of a decoded JSON document, flattened. */
function strings(mixed $value): array
{
    if (is_string($value)) { return [$value]; }
    if (!is_array($value)) { return []; }
    return array_merge([], ...array_map('strings', array_values($value)));
}

/** The visible text of a template: PHP blocks, comments and tags removed. */
function visibleText(string $template): string
{
    $text = preg_replace('/<\?(php|=).*?\?>/s', ' ', $template) ?? '';
    $text = preg_replace('/<!--.*?-->/s', ' ', $text) ?? '';
    $text = preg_replace('/<[^>]*>/s', ' ', $text) ?? '';
    return trim(preg_replace('/\s+/', ' ', $text) ?? '');
}

$forbidden = [
    'z77'   => '/z77/i',
    'wir'   => '/\bwir\b/iu',
    'we'    => '/\bwe\b/i',
    'our'   => '/\bour\b/i',
    'DSGVO' => '/DSGVO/i',
    'GDPR'  => '/GDPR/i',
];

// ── seeds ───────────────────────────────────────────────────────────────────
echo "seeds\n";
check('blueprints declared for the seven pages + footer',
    array_keys($blueprints) === ['home', 'about', 'services', 'contact', 'thanks', 'legal', 'privacy', 'footer'],
    implode(',', array_keys($blueprints)));
check('no French starter document', glob($seedDir . '/*.fr.default.json') === []);

$expected = [];
foreach (array_keys($blueprints) as $slug) {
    foreach ($languages as $lang) {
        $expected[] = "{$slug}.{$lang}.default.json";
    }
}
$present = array_map('basename', glob($seedDir . '/*.default.json'));
sort($expected);
sort($present);
check('exactly one de + en seed per blueprint slug, nothing else', $present === $expected,
    implode(',', array_diff($present, $expected)) . ' | missing ' . implode(',', array_diff($expected, $present)));

$validator = new BlockSchemaValidator();
$docs      = [];
foreach ($blueprints as $slug => $slots) {
    foreach ($languages as $lang) {
        $file = "{$seedDir}/{$slug}.{$lang}.default.json";
        $raw  = is_file($file) ? (string)file_get_contents($file) : '';
        $doc  = json_decode($raw, true);
        if (!is_array($doc)) {
            check("{$slug}.{$lang}: valid JSON", false);
            continue;
        }
        $docs["{$slug}.{$lang}"] = $doc;

        check("{$slug}.{$lang}: no BOM, slug/language/active", !str_starts_with($raw, "\xEF\xBB\xBF")
            && $doc['slug'] === $slug && $doc['language'] === $lang && $doc['active'] === true && ($doc['title'] ?? '') !== '');

        $shape = array_map(static fn(array $b): string => ($b['key'] ?? '?') . ':' . ($b['type'] ?? '?'), $doc['blocks'] ?? []);
        $want  = array_map(static fn(array $s): string => $s['key'] . ':' . $s['type'], $slots);
        check("{$slug}.{$lang}: blocks = blueprint slots in order", $shape === $want, implode(' ', $shape));

        $errors = [];
        foreach ($doc['blocks'] ?? [] as $block) {
            $schema = $schemas[$block['type'] ?? ''] ?? null;
            if ($schema === null) {
                $errors[] = 'unknown type ' . ($block['type'] ?? '?');
                continue;
            }
            $errors = array_merge($errors, $validator->errors($block, $schema, (string)($block['key'] ?? '')));
        }
        check("{$slug}.{$lang}: passes the schema validation", $errors === [], implode(' / ', $errors));

        $hits = [];
        foreach (strings($doc) as $value) {
            foreach ($forbidden as $word => $pattern) {
                if (preg_match($pattern, $value)) {
                    $hits[] = "{$word} in «{$value}»";
                }
            }
        }
        check("{$slug}.{$lang}: neutral (no z77 / wir / we / our / DSGVO / GDPR)", $hits === [], implode(' | ', $hits));
    }
}

// The starter set: every block type of it appears on some page once at least.
$used = [];
foreach ($blueprints as $slots) {
    foreach ($slots as $slot) {
        $used[$slot['type']] = true;
    }
}
foreach (['hero', 'text-section', 'cards', 'image-text', 'cta', 'form'] as $type) {
    check("starter block type «{$type}» is used by a page", isset($used[$type]));
}

// ── templates ───────────────────────────────────────────────────────────────
echo "templates\n";
$templates = array_merge(
    glob($frontend . '/res/view/templates/Main/IndexController/*.tpl.php'),
    glob($frontend . '/res/view/templates/partials/sections/*.tpl.php'),
    [$frontend . '/res/view/templates/partials/header.tpl.php', $frontend . '/res/view/templates/partials/footer.tpl.php']
);
foreach ($templates as $file) {
    $name = substr($file, strlen($frontend . '/res/view/templates/'));
    $text = visibleText((string)file_get_contents($file));
    check("{$name}: no copy", str_replace('&copy;', '', $text) === '', $text);

    $hits = [];
    foreach ($forbidden as $word => $pattern) {
        if (preg_match($pattern, $text)) {
            $hits[] = $word;
        }
    }
    check("{$name}: neutral", $hits === [], implode(',', $hits));
}

foreach (glob($frontend . '/res/view/templates/partials/sections/*.tpl.php') as $file) {
    $name = basename($file);
    check("partials/sections/{$name}: prints the slot marker", str_contains((string)file_get_contents($file), "raw(\$section['edit'])"));
}

// ── controller ──────────────────────────────────────────────────────────────
echo "controller\n";
$controller = (string)file_get_contents($frontend . '/src/Ui/Controllers/Main/IndexController.php');
foreach ($blueprints as $slug => $slots) {
    if ($slug === 'footer') {
        continue;
    }
    $action = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $slug)))) . 'Action';
    $ok     = preg_match('/function ' . $action . '\(\).*?\n    \}/s', $controller, $m) === 1;
    $body   = $ok ? $m[0] : '';
    check("{$action}: reads its document through page('{$slug}')", str_contains($body, "\$this->page('{$slug}')"));
    $missing = array_filter($slots, static fn(array $s): bool => !str_contains($body, "'" . $s['key'] . "'"));
    check("{$action}: hands every slot to a section", $missing === [], implode(',', array_column($missing, 'key')));
    check("{$action}: no text in the controller", !preg_match("/=>\s*'[A-ZÄÖÜ][a-zäöü]+ [a-zäöü]/u", $body));
}
check('a missing document answers 404 (NotFoundException)',
    str_contains($controller, 'PageContent::find($slug)') && str_contains($controller, 'throw new NotFoundException'));

$sections = (string)file_get_contents($frontend . '/src/Ui/Services/PageSections.php');
preg_match_all('/public static function (\w+)\(ContentView \$page, string \$key\): array\s*\{(.*?)\n    \}/s', $sections, $mm, PREG_SET_ORDER);
check('PageSections has one mapper per section type', count($mm) === 6, (string)count($mm));
foreach ($mm as [$all, $method, $body]) {
    check("PageSections::{$method}() hands the slot marker on", str_contains($body, '$page->editAttribute($key)'));
}

// ── rendered from the seeds ─────────────────────────────────────────────────
echo "rendering\n";

function render(string $file, array $vars): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    include $file;
    return (string)ob_get_clean();
}

function view(array $doc, array $schemas): ContentView
{
    $content = new Content();
    $content->setSlug($doc['slug']);
    $content->setLanguage($doc['language']);
    $content->setTitle($doc['title']);
    $content->setBlocks($doc['blocks']);
    return new ContentView($content, $schemas);
}

$partials = $frontend . '/res/view/templates/partials/sections/';
$home     = view($docs['home.de'], $schemas);
$editor   = $home->forEditor(new Blueprint('home', $blueprints['home']), null);

$visitorHtml = render($partials . 'hero.tpl.php', ['section' => PageSections::hero($home, 'hero')])
    . render($partials . 'textSection.tpl.php', ['section' => PageSections::textSection($home, 'intro')])
    . render($partials . 'cards.tpl.php', ['section' => PageSections::cards($home, 'cards')])
    . render($partials . 'cta.tpl.php', ['section' => PageSections::cta($home, 'cta')]);
check('visitor: the home text is on the page', str_contains($visitorHtml, 'Ihr Hauptversprechen in einem Satz')
    && str_contains($visitorHtml, 'Leistung 3') && str_contains($visitorHtml, 'Kontakt aufnehmen'));
check('visitor: no page-editor marker', !str_contains($visitorHtml, 'data-content-edit'));
check('cta: the page link is localised', str_contains($visitorHtml, 'href="/en/contact"'), $visitorHtml);
check('cards: three cards sit in thirds', str_contains($visitorHtml, 'fe-grid fe-grid--thirds'));

$editorHtml = render($partials . 'hero.tpl.php', ['section' => PageSections::hero($editor, 'hero')])
    . render($partials . 'cards.tpl.php', ['section' => PageSections::cards($editor, 'cards')]);
check('editor: marker on the hero root', preg_match('/<section class="fe-hero" data-content-edit="[^"]*slot=hero"/', $editorHtml) === 1, $editorHtml);
check('editor: marker on the cards root, labelled', str_contains($editorHtml, 'data-content-edit-label="Karten"'));

$about = view($docs['about.de'], $schemas);
$html  = render($partials . 'imageText.tpl.php', ['section' => PageSections::imageText($about, 'image')]);
check('image-text: the placeholder image with its alt text', str_contains($html, 'src="/assets/frontend/images/placeholder.svg"')
    && str_contains($html, 'alt="Platzhalter:'));

$legal    = view($docs['legal.de'], $schemas);
$sections = PageSections::textSections($legal, ['address', 'register', 'vat']);
check('legal: three sections from the document', count($sections) === 3);
$blocks = $docs['legal.de']['blocks'];
foreach ($blocks as &$block) {
    if (($block['key'] ?? '') === 'vat') {
        $block['title'] = '';
        $block['paras'] = [];
    }
}
unset($block);
$emptied = view(['blocks' => $blocks] + $docs['legal.de'], $schemas);
check('legal: an emptied section is left out', array_column(PageSections::textSections($emptied, ['address', 'register', 'vat']), 'title')
    === ['Kontaktadresse', 'Handelsregistereintrag']);
$html = render($partials . 'legalText.tpl.php', ['sections' => $sections]);
check('legal: rendered as prose blocks with h2', substr_count($html, '<div class="fe-prose__block"') === 3 && str_contains($html, '<h2>Kontaktadresse</h2>'));

$contact = view($docs['contact.en'], $schemas);
$html    = render($partials . 'textSection.tpl.php', ['section' => PageSections::textSection($contact, 'intro'), 'titleTag' => 'h1']);
check('contact: the intro opens the page with h1', str_contains($html, '<h1 class="fe-section__title">How to reach you</h1>'));
check('contact: mailto and tel links from the document', str_contains($html, 'href="mailto:name@example.com"') && str_contains($html, 'href="tel:+41000000000"'), $html);

$xss  = view(['blocks' => [['type' => 'hero', 'key' => 'hero', 'title' => '<script>x</script>', 'eyebrow' => '', 'subline' => '']]] + $docs['thanks.de'], $schemas);
$html = render($partials . 'hero.tpl.php', ['section' => PageSections::hero($xss, 'hero')]);
check('text is escaped', str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'));

$bad  = view(['blocks' => [['type' => 'cta', 'key' => 'cta', 'title' => 't', 'text' => '', 'label' => 'Go', 'href' => 'javascript:alert(1)']]] + $docs['home.de'], $schemas);
check('cta: an unsafe target renders no button', PageSections::cta($bad, 'cta')['href'] === '');

// ── contact form ────────────────────────────────────────────────────────────
echo "contact form\n";
$contactTpl = (string)file_get_contents($frontend . '/res/view/templates/Main/IndexController/contactAction.tpl.php');
$formTpl    = (string)file_get_contents($partials . 'form.tpl.php');
foreach (['form', 'fields', 'errors', 'formError', 'checkUrl', 'csrfToken'] as $var) {
    $passes = '/\'' . $var . '\'\s*=>\s*\$' . $var . '\b/';
    check("contact → partials/sections/form passes \${$var}", preg_match($passes, $contactTpl) === 1);
    check("partials/sections/form → partials/publicForm passes \${$var}", preg_match($passes, $formTpl) === 1);
}
check('contactAction keeps the PublicFormHandler + PRG to /thanks',
    str_contains($controller, 'PublicFormHandler::create(new ContactFormDefinition())')
    && str_contains($controller, "localizedUrl('/thanks')")
    && str_contains($controller, '$form->viewContext()'));
check('contact stays out of the page cache', ($config['cache']['controllers']['index']['actions']['contact']['enabled'] ?? null) === false);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
