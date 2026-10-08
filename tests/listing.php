<?php

/**
 * Standard list harness (CLI) — `Z77\Shared\Listing` (listing.md): the
 * column definition, the state read from the address, the strict parsers,
 * and the two kernel partials `partials/listHead` / `partials/listFind`
 * rendered through the real TemplateRenderer and FileFinder.
 *
 * What is load-bearing here:
 *
 *   - a definition refuses what would break the address: a reserved extra,
 *     a field not shaped `f_…`, a sort declared twice, a default sort no
 *     column offers;
 *   - the tracks per drop stage come from the columns — no hand-kept triple;
 *   - the state is read strictly: unknown sort / dir / view fall back, a
 *     value is cut to 80 characters and parsed by its column, an unreadable
 *     one is INVALID and does not narrow the search;
 *   - every link keeps the rest of the state, defaults are left out, a
 *     change but the page starts on page 1, the sort link flips;
 *   - the head carries one cell per column in order (slot, find, plain),
 *     the inputs belong to the form, the sorted column says its direction;
 *   - the find form carries the non-default state and the page's `keep`.
 *
 * Run: php tests/listing.php
 */

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';

use Z77\Core\DI;
use Z77\Core\Libraries\CacheManager;
use Z77\Core\Libraries\ConfigManager;
use Z77\Core\Libraries\FileFinder;
use Z77\Core\Services\TemplateRenderer;
use Z77\Shared\Listing\Column;
use Z77\Shared\Listing\ListDefinition;
use Z77\Shared\Listing\Parsers;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
}
function throws(callable $fn, string $class): bool
{
    try { $fn(); } catch (\Throwable $e) { return $e instanceof $class; }
    return false;
}

// ── a throwaway application root so the FileFinder resolves the kernel's partials ──
$base = str_replace('\\', '/', sys_get_temp_dir()) . '/z77-listing-' . getmypid();
define('ABS_BASE_PATH', $base);
define('DEBUG', false);
$write = function (string $rel, string $content) use ($base): void {
    $path = $base . '/' . $rel;
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);
};
$rm = function (string $dir) use (&$rm): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
        if (basename($f) === '.' || basename($f) === '..') { continue; }
        is_dir($f) ? $rm($f) : @unlink($f);
    }
    @rmdir($dir);
};
register_shutdown_function(static fn() => $rm($base));
$kernel = str_replace('\\', '/', dirname(__DIR__)) . '/packages/kernel';
$write('config/vendor/fileFinder.inc.php', "<?php return ['resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'], 'namespaces' => [\n"
    . "'Z77\\\\Shared\\\\' => ['sourcePaths' => ['{$kernel}/shared']],\n]];");
$write('var/cache/.keep', '');
DI::getInstance(true)
    ->set('CacheManager', CacheManager::class, true)
    ->set('FileFinder', fn($c) => new FileFinder($c->get('CacheManager')), true)
    ->set('ConfigManager', fn($c) => new ConfigManager($c->get('FileFinder'), $c->get('CacheManager')), true);
DI::getCacheManager()->setCacheDir($base . '/var/cache');

$columns = static fn() => [
    Column::slot('Status'),
    Column::search('f_nr', 'Nr.', '5rem', 'number', Parsers::integer(9), numeric: true, descendingFirst: true),
    Column::search('f_date', 'Datum', '6.5rem', 'date', Parsers::dateRange(), priority: '3', descendingFirst: true),
    Column::search('f_name', 'Name', 'minmax(10rem, 2fr)', 'name', Parsers::text()),
    Column::plain('Fällig', '6.5rem', priority: '2'),
    Column::search('f_amount', 'Betrag', '8rem', 'amount', Parsers::amount('CHF'), numeric: true, descendingFirst: true, inputMode: 'decimal'),
];
$definition = new ListDefinition('doc-find', $columns(), 'number', 50, ['view' => ['invoicing', 'final', 'credit'], 'all' => ['', '1']]);

echo "A. The definition: what it refuses, what it derives\n";
check('A1 a search field is f_<name>, a drop priority is 2 or 3, a column needs a track',
    throws(fn() => Column::search('nr', 'Nr.', '5rem'), \InvalidArgumentException::class)
    && throws(fn() => Column::plain('x', '5rem', priority: '1'), \InvalidArgumentException::class)
    && throws(fn() => Column::plain('x', ' '), \InvalidArgumentException::class));
check('A2 a reserved or f_-shaped extra, a field or sort declared twice, a default sort no column offers, a page of 0 — refused',
    throws(fn() => new ListDefinition('x', $columns(), 'number', 50, ['sort' => ['a']]), \InvalidArgumentException::class)
    && throws(fn() => new ListDefinition('x', $columns(), 'number', 50, ['f_x' => ['a']]), \InvalidArgumentException::class)
    && throws(fn() => new ListDefinition('x', array_merge($columns(), [Column::search('f_nr', 'Nr.', '5rem')]), 'number', 50), \InvalidArgumentException::class)
    && throws(fn() => new ListDefinition('x', array_merge($columns(), [Column::plain('Y', '5rem', 'name')]), 'number', 50), \InvalidArgumentException::class)
    && throws(fn() => new ListDefinition('x', $columns(), 'open', 50), \InvalidArgumentException::class)
    && throws(fn() => new ListDefinition('x', $columns(), 'number', 0), \InvalidArgumentException::class)
    && throws(fn() => new ListDefinition('Doc_Find', $columns(), 'number', 50), \InvalidArgumentException::class));
check('A3 the tracks per drop stage come from the columns: all · without 3 · without 3 and 2; the style carries the three',
    $definition->tracks() === ['2rem 5rem 6.5rem minmax(10rem, 2fr) 6.5rem 8rem', '2rem 5rem minmax(10rem, 2fr) 6.5rem 8rem', '2rem 5rem minmax(10rem, 2fr) 8rem']
    && str_starts_with($definition->style(), '--be-list-cols: 2rem 5rem') && str_contains($definition->style(), '--be-list-cols-xs: 2rem 5rem minmax(10rem, 2fr) 8rem'));
check('A4 the names: inputs `{id}-{key}`, the region `{id}-list`, the query keys are the fields, the extras and sort/dir/page',
    $definition->inputId('f_nr') === 'doc-find-f_nr' && $definition->region() === 'doc-find-list'
    && $definition->queryKeys() === ['f_nr', 'f_date', 'f_name', 'f_amount', 'view', 'all', 'sort', 'dir', 'page']
    && $definition->sorts() === ['number', 'date', 'name', 'amount'] && $definition->descendingFirst('number') && !$definition->descendingFirst('name'));

echo "B. The state out of the address\n";
$s = $definition->read([]);
check('B1 nothing given: the default sort in its first direction, page 1, the extras at their default, nothing active',
    $s->sort === 'number' && $s->descending && $s->page === 1 && $s->extra('view') === 'invoicing' && !$s->flag('all') && !$s->isActive() && $s->query() === '');
$s = $definition->read(['sort' => 'bogus', 'dir' => 'sideways', 'page' => '-3', 'view' => 'paid', 'all' => 'yes']);
check('B2 unknown sort / dir / page / extra fall back — never a 500, never a guess', $s->sort === 'number' && $s->descending && $s->page === 1 && $s->extra('view') === 'invoicing' && !$s->flag('all'));
$s = $definition->read(['sort' => 'name', 'view' => 'final', 'all' => '1', 'page' => '3']);
check('B3 a known sort starts in ITS first direction (names A–Z), the extras are kept', $s->sort === 'name' && !$s->descending && $s->extra('view') === 'final' && $s->flag('all') && $s->page === 3);
$s = $definition->read(['f_nr' => ' 17 ', 'f_date' => '11.2032', 'f_name' => str_repeat('x', 100), 'f_amount' => "1'250,5"]);
check('B4 values are trimmed, cut to 80, parsed by the column: 17, the month range, the text, the decimal',
    $s->parsed('f_nr') === 17 && $s->parsed('f_date') === ['2032-11-01', '2032-11-30'] && $s->parsed('f_name') === str_repeat('x', 80) && $s->value('f_name') === str_repeat('x', 80)
    && $s->parsed('f_amount') === '1250.50' && $s->isActive() && !$s->isInvalid('f_nr'));
$s = $definition->read(['f_nr' => '17a', 'f_date' => '31.02.2032', 'f_amount' => '99999999999999999999']);
check('B5 an unreadable value is INVALID — kept for the field, not searched; a 20-digit amount is invalid, not an overflow',
    $s->isInvalid('f_nr') && $s->isInvalid('f_date') && $s->isInvalid('f_amount') && $s->parsed('f_nr') === null && $s->value('f_nr') === '17a' && $s->isActive());
check('B6 the parsers alone: a day, an ISO day, a year; a bad month, a bad day, a word → null; integer digits only; amount shapes',
    Parsers::dateRange()('15.11.2032') === ['2032-11-15', '2032-11-15'] && Parsers::dateRange()('2032-11-15') === ['2032-11-15', '2032-11-15'] && Parsers::dateRange()('2032') === ['2032-01-01', '2032-12-31']
    && Parsers::dateRange()('13.2032') === null && Parsers::dateRange()('2032-02-30') === null && Parsers::dateRange()('heute') === null
    && Parsers::integer(3)('1234') === null && Parsers::integer(3)('123') === 123 && Parsers::integer()('1.5') === null
    && Parsers::amount('CHF')('-30') === '-30.00' && Parsers::amount('CHF')('1 234.5') === '1234.50' && Parsers::amount('CHF')('1.234') === null && Parsers::amount('CHF')('abc') === null);

echo "C. The links: the state travels, defaults stay out, a change starts on page 1\n";
$s = $definition->read(['f_name' => 'Müller', 'view' => 'final', 'sort' => 'date', 'dir' => 'asc', 'page' => '2']);
check('C1 query() reproduces the state without defaults; page is kept only when it is the change',
    $s->query(['page' => 3]) === 'f_name=M%C3%BCller&view=final&sort=date&dir=asc&page=3'
    && $s->query() === 'f_name=M%C3%BCller&view=final&sort=date&dir=asc'
    && $s->query(['view' => 'credit']) === 'f_name=M%C3%BCller&view=credit&sort=date&dir=asc');
check('C2 null / false / \'\' remove a key, true is 1; a default extra or sort or direction is left out',
    $s->query(['f_name' => null, 'all' => true]) === 'view=final&all=1&sort=date&dir=asc'
    && $s->query(['view' => 'invoicing', 'sort' => 'number', 'dir' => 'desc']) === 'f_name=M%C3%BCller'
    && $s->query(['sort' => 'name', 'dir' => 'asc']) === 'f_name=M%C3%BCller&view=final&sort=name');
check('C3 the sort link: the current column flips, another starts in its first direction; the reset drops every field and keeps the rest',
    $s->sortQuery('date') === 'f_name=M%C3%BCller&view=final&sort=date' && $s->sortQuery('amount') === 'f_name=M%C3%BCller&view=final&sort=amount'
    && $s->sortQuery('name') === 'f_name=M%C3%BCller&view=final&sort=name' && $s->resetQuery() === 'view=final&sort=date&dir=asc'
    && $s->sortDirection('date') === 'asc' && $s->sortDirection('name') === null);
check('C4 the hidden state of the search form: the non-default extras, sort and direction — never a field, never the page',
    $s->hiddenState() === ['view' => 'final', 'sort' => 'date', 'dir' => 'asc'] && $definition->read([])->hiddenState() === []
    && $definition->read(['sort' => 'name', 'dir' => 'desc'])->hiddenState() === ['sort' => 'name', 'dir' => 'desc']);
check('C5 paging() clamps the page into the count and offsets by the page size', $s->paging(120)->page === 2 && $s->paging(120)->offset() === 50 && $s->paging(10)->page === 1 && $s->paging(10)->isBeyondLast());
$req = new class { public function getGetParameter(string $k): mixed { return ['f_nr' => '5', 'sort' => 'amount', 'all' => '1'][$k] ?? null; } };
check('C6 readRequest() reads every query key through getGetParameter() (Rule 4); isFetch() without getMode() is false',
    ($r = $definition->readRequest($req))->parsed('f_nr') === 5 && $r->sort === 'amount' && $r->flag('all') && !ListDefinition::isFetch($req));

echo "D. The partials: one head cell per column, the inputs of the form, the find form\n";
$renderer = new TemplateRenderer('Z77\\Shared');
$s    = $definition->read(['f_name' => 'Müller', 'f_nr' => 'x', 'sort' => 'date', 'view' => 'final']);
$head = $renderer->partial('partials/listHead', ['definition' => $definition, 'state' => $s, 'action' => '/backend/x/list', 'keep' => ['mode' => 'sammel']]);
check('D1 the head: the slot with its aria-label, four find cells with inputs of the form, the plain column with no input, in column order',
    substr_count($head, 'class="be-list__col') === 6 && str_contains($head, 'aria-label="Status"') && substr_count($head, 'class="be-list__find-input"') === 4
    && substr_count($head, 'form="doc-find"') === 4 && str_contains($head, 'id="doc-find-f_nr"') && str_contains($head, '>Fällig<') && !str_contains($head, 'name="f_due"')
    && strpos($head, 'Nr.') < strpos($head, 'Datum') && strpos($head, 'Datum') < strpos($head, 'Name') && strpos($head, 'Fällig') < strpos($head, 'Betrag'));
check('D2 the sort links carry the state and the keep, the sorted column says its direction, the others offer theirs; the find marks active and invalid; priorities and inputmode are on',
    str_contains($head, 'href="/backend/x/list?mode=sammel&amp;f_nr=x&amp;f_name=M%C3%BCller&amp;view=final&amp;sort=date&amp;dir=asc"') && str_contains($head, 'data-sort="desc">Datum</a>')
    && str_contains($head, 'data-sort>Nr.</a>') && str_contains($head, 'be-list__find--active') && str_contains($head, 'aria-invalid="true"') && str_contains($head, 'value="Müller"')
    && str_contains($head, 'data-priority="3"') && str_contains($head, 'data-priority="2"') && str_contains($head, 'inputmode="decimal"') && str_contains($head, 'data-fetch-region-link'));
$find = $renderer->partial('partials/listFind', ['definition' => $definition, 'state' => $s, 'action' => '/backend/x/list', 'keep' => ['mode' => 'sammel']]);
check('D3 the find form: GET to the list, the id the inputs name, the keep and the non-default state hidden (a sort in its first direction carries no dir), no field, the region form marker',
    str_contains($find, '<form id="doc-find" method="get" action="/backend/x/list" role="search" data-fetch-region-form>')
    && str_contains($find, 'name="mode" value="sammel"') && str_contains($find, 'name="view" value="final"') && str_contains($find, 'name="sort" value="date"') && !str_contains($find, 'name="dir"')
    && !str_contains($find, 'name="f_name"') && !str_contains($find, 'name="page"') && str_contains($find, 'be-list__find-submit'));
check('D4 no script, no inline handler in either partial (Rule 7)', !preg_match('/<script|\son[a-z]+\s*=/i', $head . $find));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
