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
 *   - without a blueprint the free editor is unchanged (add bar present).
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

function renderEditor(array $vars): string
{
    extract($vars);
    ob_start();
    include __DIR__ . '/../packages/module-backend/res/view/templates/Content/ContentController/edit.tpl.php';
    return (string)ob_get_clean();
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

echo "free mode\n";
$html = renderEditor($base + ['blueprint' => null]);
check('add bar present', str_contains($html, 'data-ce-add-type'));
check('move + remove present', str_contains($html, 'data-ce-up') && str_contains($html, 'data-ce-remove'));
check('keys still written to cards', str_contains($html, 'data-key="intro"'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
