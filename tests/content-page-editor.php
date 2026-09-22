<?php

/**
 * Page editor harness (CLI) — ADR-045 §4, the pure parts.
 *
 * What is load-bearing here:
 *
 *   - a view that PageContent did not hand to an editor prints NO marker:
 *     editAttribute() is '' for every slot, so a visitor's HTML is unchanged;
 *   - forEditor() marks only blueprint slots; a key that is no slot, a slug
 *     without blueprint, a shown VERSION and a version preview key give '';
 *   - the marker names the document actually shown (slug, language, variant —
 *     after language fallback and preview) plus the slot and the preview key;
 *     the value is escaped for an attribute and parses back to exactly those
 *     query parameters (what ContentController::slotAction reads);
 *   - forEditor() returns a copy: the view it was called on stays unmarked;
 *   - PageEditing (the ONE decision PageContent and AbstractFrontendController
 *     both read): markers only for editor-and-up on a full page WITH the switch
 *     «Seite bearbeiten» on for that view area — off is the default, also for
 *     an admin; the preference round-trips through UserPreferences.
 *
 * Run: php tests/content-page-editor.php
 * No DI: the classes are required through a PSR-4 map of this checkout.
 */

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

use Z77\Core\Config\AuthRole;
use Z77\Shared\Auth\AuthUser;
use Z77\Shared\Content\Blueprint;
use Z77\Shared\Content\ContentView;
use Z77\Shared\Content\PageEditing;
use Z77\Shared\ValueObjects\UserPreferences;
use Z77\Shared\Entities\Content;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function doc(string $slug, string $language, string $variant = ''): Content
{
    $content = new Content();
    $content->setSlug($slug);
    $content->setLanguage($language);
    $content->setVariant($variant);
    $content->setTitle('T');
    $content->setBlocks([['type' => 'intro', 'key' => 'intro', 'title' => 'x']]);
    return $content;
}

/** The query parameters of a marker, read back the way a browser + PHP would. */
function markerQuery(string $attribute): array
{
    if (!preg_match('/ data-content-edit="([^"]*)"/', $attribute, $m)) {
        return [];
    }
    $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
    return ['path' => parse_url($url, PHP_URL_PATH)] + $query;
}

$blueprint = new Blueprint('faq', [
    ['key' => 'intro', 'type' => 'intro', 'label' => 'Einleitung & "Titel"'],
    ['key' => 'rows',  'type' => 'faqTable', 'label' => 'Tabelle A–Z'],
]);

echo "visitor (no forEditor)\n";
$view = new ContentView(doc('faq', 'de'), []);
check('no marker for a slot', $view->editAttribute('intro') === '');
check('no marker for an unknown key', $view->editAttribute('nope') === '');

echo "editor, live copy, no preview\n";
$ed   = $view->forEditor($blueprint, null);
$attr = $ed->editAttribute('intro');
check('marker starts with a space', str_starts_with($attr, ' data-content-edit="'), $attr);
check('label escaped', str_contains($attr, 'data-content-edit-label="Einleitung &amp; &quot;Titel&quot;"'), $attr);
$q = markerQuery($attr);
check('parses back to slug/language/variant/slot, no preview',
    $q === ['path' => ContentView::SLOT_EDITOR, 'slug' => 'faq', 'language' => 'de', 'variant' => '', 'slot' => 'intro'],
    json_encode($q));
check('forEditor() returns a copy — original stays unmarked', $view->editAttribute('intro') === '');
check('key that is no slot → no marker', $ed->editAttribute('cta') === '');

echo "editor, preview key, document shown from live (set does not hold it)\n";
$q = markerQuery($view->forEditor($blueprint, 'herbst-a7f3k2')->editAttribute('rows'));
check('variant empty, preview carried',
    $q['variant'] === '' && $q['preview'] === 'herbst-a7f3k2' && $q['slot'] === 'rows', json_encode($q));

echo "editor, preview key, document shown from the set\n";
$q = markerQuery((new ContentView(doc('faq', 'de', 'herbst-a7f3k2'), []))->forEditor($blueprint, 'herbst-a7f3k2')->editAttribute('intro'));
check('variant = the shown variant', $q['variant'] === 'herbst-a7f3k2' && $q['preview'] === 'herbst-a7f3k2', json_encode($q));

echo "language fallback: /fr page showing the German document\n";
$q = markerQuery((new ContentView(doc('legal', 'de'), []))->forEditor(new Blueprint('legal', [['key' => 'hero', 'type' => 'h']]), null)->editAttribute('hero'));
check('language = the document shown (de)', $q['language'] === 'de' && $q['slug'] === 'legal', json_encode($q));

echo "nothing to edit\n";
check('no blueprint → no marker', $view->forEditor(null, null)->editAttribute('intro') === '');
$version = new ContentView(doc('faq', 'de', 'v-20260922-153000'), []);
check('shown document is a version → no marker', $version->forEditor($blueprint, 'v-20260922-153000')->editAttribute('intro') === '');
check('preview key is a version key → no marker (live shown)', $view->forEditor($blueprint, 'v-20260922-153000')->editAttribute('intro') === '');

echo "slotEditorUrl\n";
$url = ContentView::slotEditorUrl('a b', 'de', '', 'x&y', null);
parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
check('special characters survive the round trip', $q['slug'] === 'a b' && $q['slot'] === 'x&y' && !isset($q['preview']), $url);

echo "PageEditing — the switch «Seite bearbeiten»\n";
$editor = new AuthUser(['id' => 2, 'user_name' => 'anna', 'roles' => [AuthRole::EDITOR]]);
$admin  = new AuthUser(['id' => 1, 'user_name' => 'admin', 'roles' => [AuthRole::ADMIN]]);
$member = new AuthUser(['id' => 3, 'user_name' => 'm', 'roles' => [AuthRole::MEMBER]]);
$guest  = new AuthUser();
$off    = new UserPreferences();
$on     = new UserPreferences(['content_edit' => ['frontend' => true]]);

check('editor, preference off (default) → no markers', !PageEditing::activeFor(true, $editor, $off, 'frontend'));
check('editor, preference on → markers', PageEditing::activeFor(true, $editor, $on, 'frontend'));
check('admin without preference → no markers', !PageEditing::activeFor(true, $admin, $off, 'frontend'));
check('admin, preference on → markers', PageEditing::activeFor(true, $admin, $on, 'frontend'));
check('preference is per view area', !PageEditing::activeFor(true, $editor, $on, 'shop'));
check('fetch fragment (not Page) → no markers even when on', !PageEditing::activeFor(false, $editor, $on, 'frontend'));
check('member with a stray preference → no markers', !PageEditing::activeFor(true, $member, $on, 'frontend'));
check('guest → no markers', !PageEditing::activeFor(true, $guest, $on, 'frontend') && !PageEditing::activeFor(true, null, $on, 'frontend'));
check('available: editor on a page, not a member, not a fragment',
    PageEditing::availableFor(true, $editor) && !PageEditing::availableFor(true, $member) && !PageEditing::availableFor(false, $admin));

$prefs = new UserPreferences(['partial_labels' => ['frontend' => true]]);
check('preference off by default', !$prefs->isContentEditEnabled('frontend'));
$prefs->setContentEditEnabled('frontend', true);
$stored = $prefs->toArray();
check('switched on → stored as content_edit, partial labels kept',
    ($stored['content_edit'] ?? null) === ['frontend' => true] && ($stored['partial_labels'] ?? null) === ['frontend' => true],
    json_encode($stored));
check('round trip', (new UserPreferences($stored))->isContentEditEnabled('frontend'));
$prefs->setContentEditEnabled('frontend', false);
check('switched off → key absent (deviation-only storage)', !array_key_exists('content_edit', $prefs->toArray()), json_encode($prefs->toArray()));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
