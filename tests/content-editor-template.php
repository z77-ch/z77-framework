<?php

/**
 * Content editor template harness (CLI) — blueprint mode of the backend block
 * editor (ADR-044), rendered without DI.
 *
 * What is load-bearing here:
 *
 *   - blueprint mode renders one card per slot, in slot order, labelled with the
 *     slot label, and NO block add / move / remove controls — the client cannot
 *     restructure the page from the editor;
 *   - every card carries data-key, so editor.js writes the key back (without it
 *     the save would lose the slot binding);
 *   - list bounds reach the markup (data-min / data-max) and the allowed inline
 *     formatting is shown per field;
 *   - an orphan block is shown read-only (no remove button);
 *   - the optimistic-lock hash is in the form;
 *   - without a blueprint the free editor is unchanged (add bar present);
 *   - slot mode (the page editor, ADR-045 §4): one slot card only, no metadata
 *     fields, posts to the slot URL, cancel talks to the parent window;
 *   - the action row (ADR-049 revision 2026-10-10): ONE `.z77-form-actions` row
 *     between header and body, «Speichern» first, no `.be-modal__footer` left;
 *   - the list rows carry `data-entity="content:<id>"` and the `data-field` cells the
 *     in-place answer of a delete / variant save addresses (ADR-047 addendum).
 *
 * Run: php tests/content-editor-template.php
 */

require __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

// PSR-4 for the kernel packages of THIS checkout (no composer install needed).
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Shared\\'      => '/../packages/kernel/shared/src/',
        'Z77\\Persistence\\' => '/../packages/kernel/persistence/src/',
        'Z77\\Core\\'        => '/../packages/kernel/core/src/',
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

use Z77\Shared\Content\Blueprint;
use Z77\Shared\Entities\Content;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** Minimal stand-in for EntityValidator — the template only reads these. */
final class StubValidator
{
    public function hasFieldError(string $n): bool { return false; }
    public function getFieldError(string $n): string { return ''; }
    public function hasErrors(): bool { return false; }
    public function hasStateConflict(): bool { return false; }
    public function getErrors(): array { return []; }
}

/**
 * Minimal stand-in for TemplateRenderer: templates call $this->partial(), which here
 * resolves the kernel's shared partials and the backend module's own partials directly.
 */
final class StubRenderer
{
    public function render(string $file, array $vars): string
    {
        extract($vars);
        ob_start();
        include $file;
        return (string)ob_get_clean();
    }

    public function partial(string $path, array $context = [], ?string $nameSpace = null): string
    {
        $base = $nameSpace === 'Z77\\Shared'
            ? '/../packages/kernel/shared/res/view/templates/'
            : '/../packages/module-backend/res/view/templates/';
        return $this->render(__DIR__ . $base . $path . '.tpl.php', $context);
    }
}

function renderEditor(array $vars): string
{
    return (new StubRenderer())->render(
        __DIR__ . '/../packages/module-backend/res/view/templates/Content/ContentController/edit.tpl.php',
        $vars
    );
}

$schemas = [
    'intro' => [
        ['key' => 'title', 'label' => 'Titel', 'kind' => 'text'],
        ['key' => 'paras', 'label' => 'Absätze', 'kind' => 'list', 'item' => 'textarea', 'min' => 1, 'max' => 3,
         'inline' => ['bold', 'link'], 'links' => ['targets' => ['page', 'action']]],
    ],
    'cta' => [['key' => 'label', 'label' => 'Text', 'kind' => 'text']],
];
$content = new Content([
    'slug' => 'faq', 'language' => 'de', 'title' => 'FAQ', 'active' => true,
    'blocks' => [
        ['type' => 'cta', 'key' => 'cta', 'label' => 'Los'],
        ['type' => 'intro', 'key' => 'intro', 'title' => 'Hallo', 'paras' => ['Eins']],
        ['type' => 'legacy', 'content' => 'orphan'],
    ],
]);
$base = [
    'content' => $content, 'isNew' => false, 'knownTypes' => ['intro', 'cta'], 'schemas' => $schemas,
    'actions' => ['contact' => ['href' => '/kontakt']], 'entityCsrf' => 'tok', 'entityHash' => 'h4sh',
    'validator' => new StubValidator(), 'rawBlocks' => '',
];

echo "blueprint mode\n";
$bp   = new Blueprint('faq', [
    ['key' => 'intro', 'type' => 'intro', 'label' => 'Einleitung'],
    ['key' => 'cta',   'type' => 'cta',   'label' => 'Aufruf'],
]);
$html = renderEditor($base + ['blueprint' => $bp]);

check('slot cards in slot order with labels',
    ($a = strpos($html, 'Einleitung')) !== false && ($b = strpos($html, 'Aufruf')) !== false && $a < $b);
check('no block add bar', !str_contains($html, 'data-ce-add>') && !str_contains($html, 'data-ce-add-type'));
check('no move buttons', !str_contains($html, 'data-ce-up') && !str_contains($html, 'data-ce-down'));
check('no block remove button', !str_contains($html, 'data-ce-remove'));
check('cards carry data-key', str_contains($html, 'data-key="intro"') && str_contains($html, 'data-key="cta"'));
check('list bounds in markup', str_contains($html, 'data-min="1" data-max="3"'));
check('paragraph rows are textareas', str_contains($html, '<textarea data-bv rows="3" spellcheck="false">Eins</textarea>'));
check('inline hint lists bold, link and the action',
    str_contains($html, 'Erlaubt: **fett** · [Text](/seite) · [Text](action:contact)'));
check('orphan shown read-only', str_contains($html, 'nicht in der Struktur'));
check('lock hash in the form', str_contains($html, 'name="entity_hash" value="h4sh"'));
check('locked marker on the editor', str_contains($html, 'class="ce ce--locked"'));
check('action row between header and body',
    ($h = strpos($html, 'be-modal__header')) !== false && ($r = strpos($html, 'class="z77-form-actions"')) !== false
    && ($b = strpos($html, 'be-modal__body')) !== false && $h < $r && $r < $b);
check('no bottom footer left', !str_contains($html, 'be-modal__footer'));
check('Speichern before Abbrechen in the row',
    ($s = strpos($html, '>Speichern<')) !== false && ($c = strpos($html, 'data-popup-close>Abbrechen<')) !== false && $s < $c);

echo "free mode\n";
$html = renderEditor($base + ['blueprint' => null]);
check('add bar present', str_contains($html, 'data-ce-add-type'));
check('move + remove present', str_contains($html, 'data-ce-up') && str_contains($html, 'data-ce-remove'));
check('keys still written to cards', str_contains($html, 'data-key="intro"'));

echo "slot mode (page editor, ADR-045 §4)\n";
$slotUrl = '/backend/content/content/slot?slug=faq&language=de&variant=&slot=cta';
$html = renderEditor($base + ['blueprint' => $bp, 'slot' => $bp->slot('cta'), 'slotUrl' => $slotUrl, 'slotTarget' => '']);
check('only the one slot card', substr_count($html, 'data-ce-block ') === 1 && str_contains($html, 'data-key="cta"'));
check('no orphan, no other slot', !str_contains($html, 'nicht in der Struktur') && !str_contains($html, 'data-key="intro"'));
check('no title / active / slug fields',
    !str_contains($html, 'name="title"') && !str_contains($html, 'name="active"') && !str_contains($html, 'name="slug"'));
check('form posts to the slot URL', str_contains($html, 'class="ce-slot" data-fetch-post="' . htmlspecialchars($slotUrl) . '"'));
check('header names the slot', str_contains($html, 'Bearbeiten: Aufruf'));
check('cancel asks the parent, not the shell popup', str_contains($html, 'data-ce-slot-close') && !str_contains($html, 'data-popup-close'));
check('slot mode: the row sits under the header too',
    ($r = strpos($html, 'class="z77-form-actions"')) !== false && $r < strpos($html, 'be-modal__body')
    && strpos($html, 'data-ce-slot-close') > $r && strpos($html, 'data-ce-slot-close') < strpos($html, 'be-modal__body'));
check('lock hash + entity token in the form',
    str_contains($html, 'name="entity_hash" value="h4sh"') && str_contains($html, 'name="entity_csrf" value="tok"'));
$html = renderEditor($base + ['blueprint' => $bp, 'slot' => $bp->slot('cta'), 'slotUrl' => $slotUrl, 'slotTarget' => 'herbst-a7f3k2']);
check('a save into a preview set says so', str_contains($html, 'speichert in Variante herbst-a7f3k2'));

echo "list rows (in-place answers, ADR-047 addendum)\n";
$variant = new Content(['slug' => 'faq', 'language' => 'de', 'variant' => 'herbst-a7f3k2', 'title' => 'FAQ H', 'active' => false, 'blocks' => []]);
$list = (new StubRenderer())->render(
    __DIR__ . '/../packages/module-backend/res/view/templates/Content/ContentController/listAction.tpl.php',
    ['rows' => [
        ['content' => $content, 'id' => 'faq.de', 'name' => 'FAQ', 'meta' => 'de · 3 Blöcke', 'qs' => 'slug=faq&language=de&variant='],
        ['content' => $variant, 'id' => 'faq.de.herbst-a7f3k2', 'name' => 'FAQ H', 'meta' => 'Variante herbst-a7f3k2 · de', 'qs' => 'slug=faq&language=de&variant=herbst-a7f3k2'],
    ], 'editLanguage' => 'de', 'editLanguages' => ['de']]
);
check('live row carries data-entity content:<slug>.<lang>', str_contains($list, 'data-entity="content:faq.de"'));
check('variant row carries data-entity content:<slug>.<lang>.<variant>', str_contains($list, 'data-entity="content:faq.de.herbst-a7f3k2"'));
check('name + meta cells are addressable', str_contains($list, 'data-field="name">FAQ H<') && str_contains($list, 'data-field="meta">Variante herbst-a7f3k2 · de<'));
check('list container receives inserted rows', str_contains($list, 'data-entity-list="content"'));
check('rows come from the _row partial the insert answer renders',
    substr_count($list, 'data-entity="content:') === 2 && str_contains($list, 'data-fetch-get="/backend/content/content/actions?'));
$empty = (new StubRenderer())->render(
    __DIR__ . '/../packages/module-backend/res/view/templates/Content/ContentController/listAction.tpl.php',
    ['rows' => [], 'editLanguage' => 'de', 'editLanguages' => ['de']]
);
check('empty notice is removable by the first insert', str_contains($empty, 'data-entity-empty="content"'));
$aliasRow = (new StubRenderer())->partial('Content/NavigationAliasController/_row', ['row' => [
    'id' => 7, 'path' => '/home', 'navLabel' => '→ Home (/home)', 'flags' => '', 'active' => true,
]], 'Z77\\Module\\Backend');
check('alias row: data-entity + wired triggers in the insert markup',
    str_contains($aliasRow, 'data-entity="navigationAlias:7"') && str_contains($aliasRow, 'data-fetch-toggle=')
    && str_contains($aliasRow, 'data-fetch-get="/backend/content/navigation-alias/actions?id=7"'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
