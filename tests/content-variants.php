<?php

/**
 * Content variants harness (CLI) — ADR-044 addendum "Variants".
 *
 * What is load-bearing here:
 *
 *   - the live copy keeps its old filename (<slug>.<lang>.json): an empty
 *     optional key is left out, so existing files are not renamed; a variant is
 *     <slug>.<lang>.<variant>.json; an empty REQUIRED key still throws;
 *   - ContentPreview: one canonical key form, a random tail on new keys, and
 *     carry() adds `preview=` once, before a #fragment, never for a null key;
 *   - ContentVariantService (real ContentRepository over an in-memory store):
 *     createVariant() copies a live document (without stamps) and refuses a
 *     second copy in the same set and a copy of a variant; publish() writes the
 *     variant as live, archives every previous live copy as a version under ONE
 *     `v-YYYYMMDD-HHMMSS` key for the run (`-2` if a document already has it),
 *     deletes the variant documents;
 *   - the version rule (ADR-045): saveLive() archives the stored live copy with
 *     ITS changedBy/changedAt and stamps the new one; a same-second second save
 *     gets `-2`; a first save archives nothing; restore() of a version makes it
 *     live again (archiving the current live copy first) and removes it;
 *     saving a variant writes no version; versions are recognised by key only.
 *
 * Run: php tests/content-variants.php
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

use Z77\Persistence\File\Storage\DocumentPath;
use Z77\Persistence\File\Storage\RecordStore;
use Z77\Shared\Attributes\Entity as EntityAttr;
use Z77\Shared\Content\ContentPreview;
use Z77\Shared\Entities\Content;
use Z77\Shared\Repositories\ContentRepository;
use Z77\Shared\Services\ContentVariantService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

function doc(string $slug, string $lang, string $variant, string $title, bool $active = true): Content
{
    $c = new Content();
    $c->setSlug($slug);
    $c->setLanguage($lang);
    $c->setVariant($variant);
    $c->setTitle($title);
    $c->setActive($active);
    $c->setBlocks([['type' => 'text', 'content' => $title]]);
    return $c;
}

// ── DocumentPath ────────────────────────────────────────────────────────────
echo "DocumentPath\n";
$attr = new EntityAttr('file', 'content', invalidatesCache: true, perRecord: true,
    keyBy: ['slug', 'language', 'variant'], optionalKeys: ['variant']);
$real = (new ReflectionClass(Content::class))->getAttributes(EntityAttr::class)[0]->newInstance();
check('Content declares variant as optional key', $real->optionalKeys === ['variant'] && $real->keyBy === ['slug', 'language', 'variant']);

$p = DocumentPath::forEntity($attr, doc('home', 'de', '', 'x'));
check('live copy keeps <slug>.<lang>.json', $p === 'content/home.de.json', $p);
$p = DocumentPath::forEntity($attr, doc('home', 'de', 'herbst-a7f3k2', 'x'));
check('variant is <slug>.<lang>.<variant>.json', $p === 'content/home.de.herbst-a7f3k2.json', $p);
$p = DocumentPath::forCriteria($attr, ['slug' => 'home', 'language' => 'fr', 'variant' => '']);
check('criteria with empty variant → live file', $p === 'content/home.fr.json', $p);
$p = DocumentPath::forCriteria($attr, ['slug' => 'home', 'language' => 'fr']);
check('criteria without variant → live file', $p === 'content/home.fr.json', $p);
$p = DocumentPath::forCriteria($attr, ['slug' => 'home', 'language' => 'fr', 'variant' => 'x-1']);
check('criteria with variant → variant file', $p === 'content/home.fr.x-1.json', $p);
$threw = false;
try { DocumentPath::forCriteria($attr, ['slug' => '', 'language' => 'de', 'variant' => 'x']); }
catch (RuntimeException) { $threw = true; }
check('empty required key still throws', $threw);
$old = new EntityAttr('file', 'content', perRecord: true, keyBy: ['slug', 'language']);
$p = DocumentPath::forCriteria($old, ['slug' => 'home', 'language' => 'de']);
check('entity without optional keys unchanged', $p === 'content/home.de.json', $p);

// ── ContentPreview ──────────────────────────────────────────────────────────
echo "ContentPreview\n";
check('normalize lowercases and strips', ContentPreview::normalize(' Herbst_2026 ä! ') === 'herbst2026', ContentPreview::normalize(' Herbst_2026 ä! '));
check('normalize trims dashes', ContentPreview::normalize('--a-b--') === 'a-b');
check('normalize caps at 64', strlen(ContentPreview::normalize(str_repeat('a', 100))) === 64);
check('normalize empty stays empty', ContentPreview::normalize('äöü') === '');

$k = ContentPreview::newKey('Herbst Lieferung');
check('newKey = <name>-<6 hex>', (bool)preg_match('/^herbstlieferung-[0-9a-f]{6}$/', $k), $k);
check('newKey is already normalized', ContentPreview::normalize($k) === $k, $k);
check('newKey empty name → satz-<6 hex>', (bool)preg_match('/^satz-[0-9a-f]{6}$/', ContentPreview::newKey('')));
check('newKey differs between calls', ContentPreview::newKey('a') !== ContentPreview::newKey('a'));
check('newKey name part max 40', strlen(ContentPreview::newKey(str_repeat('b', 80))) === 47);

$c = ContentPreview::carry('/fr/wohnen', 'herbst-a7f3k2');
check('carry without query', $c === '/fr/wohnen?preview=herbst-a7f3k2', $c);
$c = ContentPreview::carry('/wohnen?a=1', 'k');
check('carry with query', $c === '/wohnen?a=1&preview=k', $c);
$c = ContentPreview::carry('/wohnen?a=1#top', 'k');
check('carry before fragment', $c === '/wohnen?a=1&preview=k#top', $c);
$c = ContentPreview::carry('/wohnen#top', 'k');
check('carry before fragment, no query', $c === '/wohnen?preview=k#top', $c);
$c = ContentPreview::carry('/wohnen?preview=other', 'k');
check('carry not twice', $c === '/wohnen?preview=other', $c);
$c = ContentPreview::carry('/wohnen?', 'k');
check('carry after bare ?', $c === '/wohnen?preview=k', $c);
check('carry null key → unchanged', ContentPreview::carry('/wohnen', null) === '/wohnen');
check('carry empty key → unchanged', ContentPreview::carry('/wohnen', '') === '/wohnen');

$w = ContentPreview::withoutPreview('/wohnen?preview=k');
check('withoutPreview drops the only param and the ?', $w === '/wohnen', $w);
$w = ContentPreview::withoutPreview('/wohnen?a=1&preview=k&b=2#top');
check('withoutPreview keeps other params + fragment', $w === '/wohnen?a=1&b=2#top', $w);
$w = ContentPreview::withoutPreview('/?preview=k');
check('withoutPreview on the root', $w === '/', $w);
$w = ContentPreview::withoutPreview('/fr/habiter');
check('withoutPreview without query → unchanged', $w === '/fr/habiter', $w);
$w = ContentPreview::withoutPreview('/x?previewed=1');
check('withoutPreview matches the name exactly', $w === '/x?previewed=1', $w);
$w = ContentPreview::withoutPreview(ContentPreview::carry('/wohnen?a=1#t', 'k'));
check('withoutPreview reverses carry', $w === '/wohnen?a=1#t', $w);

$_GET = ['preview' => ' Herbst-A7 '];
check('key() reads + normalizes ?preview=', ContentPreview::key() === 'herbst-a7');
$_GET = ['preview' => ['x']];
check('key() ignores a non-string', ContentPreview::key() === null);
$_GET = [];
check('key() null without parameter', ContentPreview::key() === null);

// ── ContentVariantService over an in-memory store ───────────────────────────
echo "ContentVariantService\n";

/** DocumentStore semantics in memory: rows keyed by DocumentPath. */
final class MemoryStore implements RecordStore
{
    /** @var array<string, array> path => row */
    public array $files = [];

    public function __construct(private EntityAttr $attr) {}

    public function all(): array { return array_values($this->files); }

    public function byKey(array $criteria): ?array
    {
        try { $path = DocumentPath::forCriteria($this->attr, $criteria); }
        catch (\RuntimeException) { return null; }
        return $this->files[$path] ?? null;
    }

    public function keyFields(): array { return $this->attr->keyBy; }

    public function persistAll(array $entities): void
    {
        foreach ($entities as $e) {
            $this->files[DocumentPath::forEntity($this->attr, $e)] = $e->mapToArray();
        }
    }

    public function delete(object $entity): void
    {
        unset($this->files[DocumentPath::forEntity($this->attr, $entity)]);
    }
}

/** FileEntityManager semantics: persist deferred until flush, remove immediate. */
final class MemoryEm
{
    private array $pending = [];
    public function __construct(private MemoryStore $store) {}
    public function persist(object $e): void { $this->pending[] = $e; }
    public function flush(): void { $this->store->persistAll($this->pending); $this->pending = []; }
    public function remove(object $e): void { $this->store->delete($e); }
}

$store = new MemoryStore($real);
$repo  = new ContentRepository(Content::class, $store);
$em    = new MemoryEm($store);
$svc   = new ContentVariantService($repo, $em);

$store->persistAll([doc('home', 'de', '', 'Home alt'), doc('home', 'fr', '', 'Accueil alt'), doc('faq', 'de', '', 'FAQ')]);

$v = $svc->createVariant($repo->findBySlug('home', 'de'), 'Herbst-A7');
check('createVariant writes <slug>.<lang>.<key>.json', isset($store->files['content/home.de.herbst-a7.json']));
check('createVariant copies title/blocks', $v->getTitle() === 'Home alt' && $v->getBlocks() === [['type' => 'text', 'content' => 'Home alt']]);
check('live copy untouched', $repo->findBySlug('home', 'de')->getTitle() === 'Home alt');
check('findBySlug with variant', $repo->findBySlug('home', 'de', 'herbst-a7')?->getVariant() === 'herbst-a7');

$threw = '';
try { $svc->createVariant($repo->findBySlug('home', 'de'), 'herbst-a7'); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('createVariant refuses a second copy in the set', $threw !== '', $threw);
$threw = '';
try { $svc->createVariant($repo->findBySlug('home', 'de', 'herbst-a7'), 'other'); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('createVariant refuses a variant as source', $threw !== '', $threw);
$threw = '';
try { $svc->createVariant($repo->findBySlug('faq', 'de'), '!!'); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('createVariant refuses an empty key', $threw !== '', $threw);

$svc->createVariant($repo->findBySlug('home', 'fr'), 'herbst-a7');
// A set may also bring a document that has no live copy yet.
$store->persistAll([doc('neu', 'de', 'herbst-a7', 'Neu', false)]);
check('variantKeys', $repo->variantKeys() === ['herbst-a7']);
check('findByVariant spans languages', count($repo->findByVariant('herbst-a7')) === 3);

// Edit the variants, then publish.
$hv = $repo->findBySlug('home', 'de', 'herbst-a7'); $hv->setTitle('Home neu'); $store->persistAll([$hv]);
$fv = $repo->findBySlug('home', 'fr', 'herbst-a7'); $fv->setTitle('Accueil neu'); $store->persistAll([$fv]);
check('variant save writes no version and no stamp', array_filter($repo->variantKeys(), [ContentPreview::class, 'isVersionKey']) === []
    && $repo->findBySlug('home', 'de', 'herbst-a7')->getChangedBy() === '');

// The live copies carry a stamp from an earlier save (whose text they are).
$hl = $repo->findBySlug('home', 'de'); $hl->setChangedBy('anna'); $hl->setChangedAt('2026-09-20T10:00:00+02:00'); $store->persistAll([$hl]);

// home.de already holds a version with the key of that second → the run must take -2.
$now = new DateTimeImmutable('2026-09-22 14:30:00');
$store->persistAll([doc('home', 'de', 'v-20260922-143000', 'Home noch älter')]);

$report = $svc->publish('herbst-a7', 'bruno', $now);
check('publish: one version key for the run, -2 on collision', $report['archive'] === 'v-20260922-143000-2', $report['archive']);
check('publish: report lists 3 documents', count($report['published']) === 3);
check('publish: report flags the doc without live copy',
    array_column($report['published'], 'archived', 'slug')['neu'] === false);
check('publish: live has the variant text', $repo->findBySlug('home', 'de')->getTitle() === 'Home neu'
    && $repo->findBySlug('home', 'fr')->getTitle() === 'Accueil neu');
check('publish: live stamped with publisher + time', $repo->findBySlug('home', 'de')->getChangedBy() === 'bruno'
    && $repo->findBySlug('home', 'de')->getChangedAt() === '2026-09-22T14:30:00' . $now->format('P'));
check('publish: new live document, active flag from the variant',
    ($n = $repo->findBySlug('neu', 'de')) !== null && $n->isLive() && !$n->isActive());
check('publish: variant documents gone', $repo->findByVariant('herbst-a7') === []);
check('publish: previous live copies are versions of one run',
    $repo->findBySlug('home', 'de', 'v-20260922-143000-2')?->getTitle() === 'Home alt'
    && $repo->findBySlug('home', 'fr', 'v-20260922-143000-2')?->getTitle() === 'Accueil alt'
    && count($repo->findByVariant('v-20260922-143000-2')) === 2);
check('publish: a version keeps ITS stamp, not the publisher\'s',
    $repo->findBySlug('home', 'de', 'v-20260922-143000-2')->getChangedBy() === 'anna'
    && $repo->findBySlug('home', 'de', 'v-20260922-143000-2')->getChangedAt() === '2026-09-20T10:00:00+02:00');
check('publish: no alt- archive set any more', array_filter($repo->variantKeys(), fn($k) => str_starts_with($k, 'alt-')) === []);
check('publish: untouched document stays', $repo->findBySlug('faq', 'de')->getTitle() === 'FAQ');
check('publish: live file names unchanged', isset($store->files['content/home.de.json'], $store->files['content/home.fr.json']));

$threw = '';
try { $svc->publish('gibtsnicht', 'x', $now); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('publish refuses an unknown set', $threw !== '', $threw);
$threw = '';
try { $svc->publish('', 'x', $now); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('publish refuses the empty key (live)', $threw !== '', $threw);

// ── versions (ADR-045) ──────────────────────────────────────────────────────
echo "Versions\n";
check('isVersionKey: plain', ContentPreview::isVersionKey('v-20260922-153000'));
check('isVersionKey: collision suffix', ContentPreview::isVersionKey('v-20260922-153000-2'));
check('isVersionKey: newKey never matches', !ContentPreview::isVersionKey(ContentPreview::newKey(''))
    && !ContentPreview::isVersionKey('v-20260922-153000-123456') && !ContentPreview::isVersionKey('herbst-a7'));
check('versionKey format', ContentPreview::versionKey(new DateTimeImmutable('2026-09-22 15:30:05')) === 'v-20260922-153005'
    && ContentPreview::versionKey(new DateTimeImmutable('2026-09-22 15:30:05'), 3) === 'v-20260922-153005-3');
check('Content::isVersion', doc('x', 'de', 'v-20260922-153000', 'x')->isVersion() && !doc('x', 'de', 'herbst-a7', 'x')->isVersion()
    && !doc('x', 'de', '', 'x')->isVersion());

// First save of a document that has no live copy: nothing to archive.
$t1  = new DateTimeImmutable('2026-09-23 09:00:00');
$new = doc('kontakt', 'de', '', 'Kontakt 1');
$new->setChangedBy('crafted'); // a caller's value never survives: the service stamps
check('saveLive: first save archives nothing', $svc->saveLive($new, 'anna', $t1) === '');
$live = $repo->findBySlug('kontakt', 'de');
check('saveLive: stamps user + ISO time', $live->getChangedBy() === 'anna' && $live->getChangedAt() === $t1->format(DATE_ATOM));

// Second save (the loaded document, changed — as the backend editor does).
$t2   = new DateTimeImmutable('2026-09-23 09:15:00');
$live = $repo->findBySlug('kontakt', 'de'); $live->setTitle('Kontakt 2');
$k2   = $svc->saveLive($live, 'bruno', $t2);
check('saveLive: archives the stored copy as v-<time>', $k2 === 'v-20260923-091500', $k2);
$v = $repo->findBySlug('kontakt', 'de', $k2);
check('saveLive: version holds the OLD text + ITS stamp', $v?->getTitle() === 'Kontakt 1'
    && $v->getChangedBy() === 'anna' && $v->getChangedAt() === $t1->format(DATE_ATOM));
check('saveLive: live holds the new text + new stamp', $repo->findBySlug('kontakt', 'de')->getTitle() === 'Kontakt 2'
    && $repo->findBySlug('kontakt', 'de')->getChangedBy() === 'bruno');

// Third save in the same second → -2.
$live = $repo->findBySlug('kontakt', 'de'); $live->setTitle('Kontakt 3');
$k3   = $svc->saveLive($live, 'bruno', $t2);
check('saveLive: same second → -2', $k3 === 'v-20260923-091500-2', $k3);
check('saveLive: -2 holds the second state', $repo->findBySlug('kontakt', 'de', $k3)?->getTitle() === 'Kontakt 2');
check('saveLive: the other language is untouched', $repo->findBySlug('kontakt', 'fr') === null);

$threw = '';
try { $svc->saveLive(doc('kontakt', 'de', 'herbst-a7', 'x'), 'anna', $t2); } catch (\LogicException $e) { $threw = $e->getMessage(); }
check('saveLive refuses a variant', $threw !== '', $threw);

// Restore = the version becomes live, the current live copy becomes a version.
$t3      = new DateTimeImmutable('2026-09-23 10:00:00');
$version = $repo->findBySlug('kontakt', 'de', 'v-20260923-091500');
$back    = $svc->restore($version, 'carla', $t3);
check('restore: live has the version text', $repo->findBySlug('kontakt', 'de')->getTitle() === 'Kontakt 1');
check('restore: stamped by the restorer', $repo->findBySlug('kontakt', 'de')->getChangedBy() === 'carla');
check('restore: the replaced live copy is a new version', $back === 'v-20260923-100000'
    && $repo->findBySlug('kontakt', 'de', $back)?->getTitle() === 'Kontakt 3'
    && $repo->findBySlug('kontakt', 'de', $back)->getChangedBy() === 'bruno', $back);
check('restore: the restored version is gone', $repo->findBySlug('kontakt', 'de', 'v-20260923-091500') === null);
check('restore: other versions stay', $repo->findBySlug('kontakt', 'de', 'v-20260923-091500-2') !== null);

// Restoring in the very second the version was made → the new version takes -2.
$store->persistAll([doc('agb', 'de', '', 'AGB jetzt')]);
$store->persistAll([doc('agb', 'de', 'v-20260923-110000', 'AGB früher')]);
$k = $svc->restore($repo->findBySlug('agb', 'de', 'v-20260923-110000'), 'carla', new DateTimeImmutable('2026-09-23 11:00:00'));
check('restore: collision with the restored key → -2', $k === 'v-20260923-110000-2'
    && $repo->findBySlug('agb', 'de')->getTitle() === 'AGB früher'
    && $repo->findBySlug('agb', 'de', 'v-20260923-110000-2')?->getTitle() === 'AGB jetzt', $k);

$threw = '';
try { $svc->restore($repo->findBySlug('faq', 'de'), 'x', $t3); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('restore refuses the live copy', $threw !== '', $threw);
$store->persistAll([doc('faq', 'de', 'herbst-b1', 'FAQ Herbst')]);
$threw = '';
try { $svc->restore($repo->findBySlug('faq', 'de', 'herbst-b1'), 'x', $t3); } catch (\DomainException $e) { $threw = $e->getMessage(); }
check('restore refuses an ordinary variant', $threw !== '', $threw);

// Publishing a version key as a set works too (a version is an ordinary variant).
$pub = $svc->publish('v-20260922-143000-2', 'dora', new DateTimeImmutable('2026-09-23 12:00:00'));
check('publish of a version run restores all its documents', $repo->findBySlug('home', 'de')->getTitle() === 'Home alt'
    && $repo->findBySlug('home', 'fr')->getTitle() === 'Accueil alt' && $pub['archive'] === 'v-20260923-120000', $pub['archive']);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
