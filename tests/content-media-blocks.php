<?php

/**
 * DMS image blocks harness (CLI, offline, no DI) — media-picker bauplan P2.
 *
 * What is load-bearing here:
 *
 *   (A) the `document` field kind checks only the SHAPE: a positive integer id (int or its
 *       plain digit string — the block editor posts strings), empty only when not required;
 *       `0`, a sign, a fraction, letters, blanks around, an array are refused; a scalar list
 *       of documents too. `shared` knows nothing of the DMS;
 *   (B) the schema of `media-image` / `media-gallery`: the fields render() reads, alt
 *       required, the document field of kind `document`; both registered in dmsConfig;
 *   (C) a publicly delivered image renders with srcset over its variants of the same
 *       proportions (the s/m/l/xl ladder, a crop never), sizes, width/height of the src,
 *       lazy; the URLs come from publicUrl() (version token);
 *   (D) nothing renders for what /media would not hand out: no id, unknown, deleted, not an
 *       image, protected (folder or own mode), inactive (document or folder chain);
 *   (E) author text is escaped (alt, caption), a link is scheme-gated;
 *   (F) a preserved original (thumbnail only) joins the srcset; an image without measured
 *       size renders its original alone;
 *   (G) the gallery skips a bad item and renders '' when none is left.
 *
 * Run: php tests/content-media-blocks.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "content-media-blocks: vendor/autoload.php missing — run `composer install` in the monorepo root first.\n");
    exit(2);
}
require $autoload;

use Z77\Module\Dms\Content\Renderer\MediaBlockRenderer;
use Z77\Module\Dms\Content\Renderer\MediaGalleryRenderer;
use Z77\Module\Dms\Content\Renderer\MediaImageRenderer;
use Z77\Module\Dms\Entities\Document;
use Z77\Module\Dms\Entities\Folder;
use Z77\Module\Dms\Repositories\DocumentRepository;
use Z77\Module\Dms\Repositories\FolderRepository;
use Z77\Module\Dms\Services\DocumentService;
use Z77\Shared\Content\BlockSchemaValidator;
use Z77\Shared\Content\InlineMarkdown;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $got = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}" . ($got !== '' ? "\n       got: {$got}" : '') . "\n"; }
}

// ── (A) the `document` kind ─────────────────────────────────────────────────────────────

echo "(A) BlockSchemaValidator — kind document\n";
$v        = new BlockSchemaValidator();
$required = [['key' => 'documentId', 'label' => 'Bild', 'kind' => 'document', 'required' => true]];
$optional = [['key' => 'documentId', 'label' => 'Bild', 'kind' => 'document']];

foreach ([42, '42', 1, '9007199254'] as $ok) {
    check('id ' . var_export($ok, true) . ' is accepted', $v->errors(['documentId' => $ok], $required) === []);
}
foreach ([0, '0', -3, '-3', '+3', '4.2', 4.2, 'abc', '42a', ' 42', '42 ', '042', true, ['42'], '1234567890123456789'] as $bad) {
    $errors = $v->errors(['documentId' => $bad], $optional);
    check('value ' . json_encode($bad) . ' is refused, also when optional',
        count($errors) === 1 && str_contains($errors[0], 'keine gültige Dokument-Nummer'), implode(' | ', $errors));
}
$errors = $v->errors([], $required, 'Bild oben');
check('required: missing → «darf nicht leer sein» with context',
    $errors === ['Bild oben: «Bild» darf nicht leer sein.'], implode(' | ', $errors));
check('required: empty string → refused', count($v->errors(['documentId' => ''], $required)) === 1);
check('required: blanks only → refused', count($v->errors(['documentId' => '  '], $required)) === 1);
check('optional: missing / empty / null → no error',
    $v->errors([], $optional) === [] && $v->errors(['documentId' => ''], $optional) === []
    && $v->errors(['documentId' => null], $optional) === []);
check('documentId(): int, digit string, nothing else',
    BlockSchemaValidator::documentId(7) === 7 && BlockSchemaValidator::documentId('7') === 7
    && BlockSchemaValidator::documentId('x') === null && BlockSchemaValidator::documentId(0) === null);
$list = [['key' => 'ids', 'label' => 'Bilder', 'kind' => 'list', 'item' => 'document']];
$errors = $v->errors(['ids' => [3, '4', 'x', 0]], $list);
check('scalar list of documents: each item checked',
    count($errors) === 2 && str_contains($errors[0], 'Eintrag 3') && str_contains($errors[1], 'Eintrag 4'), implode(' | ', $errors));
$rows = [['key' => 'items', 'label' => 'Bilder', 'kind' => 'list', 'item' => $required]];
$errors = $v->errors(['items' => [['documentId' => '5'], ['documentId' => ''], ['documentId' => 'abc']]], $rows);
check('object list: required and shape per row',
    count($errors) === 2 && str_contains($errors[0], 'Eintrag 2, «Bild» darf nicht leer sein')
    && str_contains($errors[1], 'Eintrag 3, «Bild» ist keine gültige'), implode(' | ', $errors));
$shared = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/src/Content/BlockSchemaValidator.php');
check('shared validator knows nothing of the DMS', !str_contains($shared, 'Module\\Dms') && !str_contains($shared, 'DocumentService'));

// ── (B) schema + registration ───────────────────────────────────────────────────────────

echo "(B) schema() and registration\n";
$image   = new MediaImageRenderer();
$gallery = new MediaGalleryRenderer();
$keys    = static fn(array $schema): array => array_column($schema, 'kind', 'key');

check('media-image type', $image->type() === 'media-image');
check('media-image fields: documentId (document), alt, caption (text), link (url)',
    $keys($image->schema()) === ['documentId' => 'document', 'alt' => 'text', 'caption' => 'text', 'link' => 'url'],
    json_encode($keys($image->schema())));
$req = array_column($image->schema(), 'required', 'key');
check('media-image: documentId and alt required, caption and link not',
    !empty($req['documentId']) && !empty($req['alt']) && empty($req['caption']) && empty($req['link']));
check('media-image: the document field asks the picker for images', $image->schema()[0]['accept'] === 'image');
check('every descriptor has a German label', array_filter($image->schema(), static fn($f) => ($f['label'] ?? '') === '') === []);

check('media-gallery type', $gallery->type() === 'media-gallery');
$gs = $gallery->schema();
check('media-gallery: one list field `items`, at least one row',
    count($gs) === 1 && $gs[0]['key'] === 'items' && $gs[0]['kind'] === 'list' && $gs[0]['min'] === 1);
check('media-gallery item: documentId (document), alt, caption (text)',
    $keys($gs[0]['item']) === ['documentId' => 'document', 'alt' => 'text', 'caption' => 'text'], json_encode($keys($gs[0]['item'])));
$itemReq = array_column($gs[0]['item'], 'required', 'key');
check('media-gallery item: documentId and alt required', !empty($itemReq['documentId']) && !empty($itemReq['alt']) && empty($itemReq['caption']));

$errors = $v->errors(['documentId' => '10', 'alt' => '', 'link' => 'javascript:alert(1)'], $image->schema());
check('media-image block: missing alt and a bad link are reported', count($errors) === 2, implode(' | ', $errors));
$errors = $v->errors(['items' => [['documentId' => 10, 'alt' => 'A'], ['documentId' => '11', 'alt' => '']]], $gs);
check('media-gallery block: alt required per item', count($errors) === 1 && str_contains($errors[0], 'Eintrag 2, «Alt-Text'), implode(' | ', $errors));
check('media-gallery block: no item → refused', count($v->errors(['items' => []], $gs)) === 1);

$config = require __DIR__ . '/../packages/module-dms/src/App/Config/dmsConfig.inc.php';
check('dmsConfig registers both renderers under contentBlocks',
    ($config['contentBlocks'] ?? null) === [MediaImageRenderer::class, MediaGalleryRenderer::class]);
$frontend = require __DIR__ . '/../packages/module-frontend/src/App/Config/frontendConfig.inc.php';
check('… and the frontend does not', array_intersect($frontend['contentBlocks'], $config['contentBlocks']) === []);

// ── a DMS without DI: folders, documents, the service handed to the renderers ───────────

$folder = static function (int $id, ?int $parent, string $slug, ?string $mode, bool $active = true): Folder {
    $f = new Folder();
    (new ReflectionProperty(Folder::class, 'id'))->setValue($f, $id);
    $f->setParentId($parent);
    $f->setSlug($slug);
    $f->setDeliveryMode($mode);
    $f->setActive($active);
    return $f;
};
$folders = new class ([
    $folder(1, null, 'drive', null),
    $folder(2, 1, 'bilder', 'public'),
    $folder(3, 1, 'intern', 'protected'),
    $folder(4, 2, 'archiv', null, false),   // public by inheritance, but inactive
    $folder(5, 2, 'team', null),            // public by inheritance
]) extends FolderRepository {
    public function __construct(private array $rows) {}
    public function findAll(): array { return $this->rows; }
    public function findDriveRoot(): ?Folder { return $this->rows[0]; }
};

$ladder = [
    's'     => ['w' => 160,  'h' => 120,  'ext' => 'jpg'],
    'm'     => ['w' => 480,  'h' => 360,  'ext' => 'jpg'],
    'l'     => ['w' => 1024, 'h' => 768,  'ext' => 'jpg'],
    'xl'    => ['w' => 2048, 'h' => 1536, 'ext' => 'jpg'],
    'thumb' => ['w' => 300,  'h' => 300,  'ext' => 'jpg'],   // a project `cover` crop
    'wide'  => ['w' => 1600, 'h' => 1200, 'ext' => 'jpg'],   // a project width-only size
];
$doc = static function (int $id, int $folderId, string $slug, array $set = []): Document {
    $d = new Document();
    (new ReflectionProperty(Document::class, 'id'))->setValue($d, $id);
    $d->setFolderId($folderId);
    $d->setSlug($slug);
    $d->setOriginalName("{$slug}.jpg");
    $d->setExt($set['ext'] ?? 'jpg');
    $d->setMimeType($set['mime'] ?? 'image/jpeg');
    $d->setKind($set['kind'] ?? 'image');
    $d->setChecksum(str_repeat((string) ($id % 10), 64));
    $d->setWidth(array_key_exists('w', $set) ? $set['w'] : 4000);
    $d->setHeight(array_key_exists('h', $set) ? $set['h'] : 3000);
    $d->setVariants($set['variants'] ?? []);
    $d->setDeliveryMode($set['mode'] ?? null);
    $d->setActive($set['active'] ?? true);
    $d->setDeletedAt($set['deleted'] ?? null);
    return $d;
};
$documents = new class extends DocumentRepository {
    public array $rows = [];
    public function __construct() {}
    public function find(int|string $id): ?object { return $this->rows[(int) $id] ?? null; }
    public function findByFolder(?int $folderId): array { return array_values(array_filter($this->rows, static fn(Document $d) => $d->getFolderId() === $folderId)); }
};
foreach ([
    $doc(10, 2, 'see', ['variants' => $ladder]),
    $doc(11, 3, 'geheim', ['variants' => $ladder]),
    $doc(12, 2, 'weg', ['variants' => $ladder, 'deleted' => '2026-11-01T10:00:00+01:00']),
    $doc(13, 2, 'flyer', ['kind' => 'pdf', 'ext' => 'pdf', 'mime' => 'application/pdf', 'w' => null, 'h' => null]),
    $doc(14, 4, 'alt', ['variants' => $ladder]),
    $doc(15, 2, 'privat', ['variants' => $ladder, 'mode' => 'protected']),
    $doc(16, 5, 'original', ['w' => 3000, 'h' => 2000, 'variants' => ['s' => ['w' => 160, 'h' => 107, 'ext' => 'jpg']]]),
    $doc(17, 2, 'logo', ['ext' => 'svg', 'mime' => 'image/svg+xml', 'w' => null, 'h' => null]),
    $doc(18, 2, 'entwurf', ['variants' => $ladder, 'active' => false]),
    $doc(19, 2, 'klein', ['w' => 800, 'h' => 600, 'variants' => [
        's' => ['w' => 160, 'h' => 120, 'ext' => 'jpg'], 'm' => ['w' => 480, 'h' => 360, 'ext' => 'jpg'],
        'l' => ['w' => 800, 'h' => 600, 'ext' => 'jpg'], 'xl' => ['w' => 800, 'h' => 600, 'ext' => 'jpg']]]),
] as $d) {
    $documents->rows[$d->getId()] = $d;
}

$svc = (new ReflectionClass(DocumentService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(DocumentService::class, 'folders'))->setValue($svc, $folders);
(new ReflectionProperty(DocumentService::class, 'documents'))->setValue($svc, $documents);
(new ReflectionProperty(MediaBlockRenderer::class, 'documents'))->setValue(null, $svc);

$md = new InlineMarkdown();

// ── (C) a public image ──────────────────────────────────────────────────────────────────

echo "(C) media-image renders a public image\n";
$html = $image->render(['type' => 'media-image', 'documentId' => 10, 'alt' => 'Der See'], $md);
check('figure + img with the block classes',
    str_starts_with($html, '<figure class="media-image"><img class="media-image__img" ') && str_ends_with($html, '></figure>'), $html);
check('srcset: s/m/l/xl and the proportional project size, ascending, version token',
    str_contains($html, 'srcset="/media/bilder/see.s.jpg?v=00000000 160w, /media/bilder/see.m.jpg?v=00000000 480w, '
        . '/media/bilder/see.l.jpg?v=00000000 1024w, /media/bilder/see.wide.jpg?v=00000000 1600w, /media/bilder/see.xl.jpg?v=00000000 2048w"'), $html);
check('a crop (other proportions) stays out', !str_contains($html, 'see.thumb.'), $html);
check('the original (4000 px) stays out', !str_contains($html, '/see.jpg'), $html);
check('src = the l variant, width/height of it', str_contains($html, 'src="/media/bilder/see.l.jpg?v=00000000" ') && str_contains($html, ' width="1024" height="768"'), $html);
check('sizes, lazy, async', str_contains($html, ' sizes="(max-width: 1024px) 100vw, 1024px"') && str_contains($html, ' loading="lazy" decoding="async"'), $html);
check('alt as given', str_contains($html, ' alt="Der See"'), $html);
check('no caption, no link → neither', !str_contains($html, 'figcaption') && !str_contains($html, '<a '), $html);
check('the id as the editor posts it (digit string) renders the same',
    $image->render(['documentId' => '10', 'alt' => 'Der See'], $md) === $html);
$html = $image->render(['documentId' => 19, 'alt' => 'K'], $md);
check('a small original: one candidate per width, src the largest',
    str_contains($html, 'srcset="/media/bilder/klein.s.jpg?v=99999999 160w, /media/bilder/klein.m.jpg?v=99999999 480w, /media/bilder/klein.l.jpg?v=99999999 800w"')
    && str_contains($html, 'src="/media/bilder/klein.l.jpg?v=99999999"') && str_contains($html, 'width="800" height="600"'), $html);

// ── (D) nothing for what /media would not hand out ──────────────────────────────────────

echo "(D) media-image renders nothing\n";
foreach ([
    'no id'                         => [],
    'a non-id'                      => ['documentId' => 'abc'],
    'id 0'                          => ['documentId' => 0],
    'an unknown document'           => ['documentId' => 404],
    'a deleted document'            => ['documentId' => 12],
    'not an image (PDF)'            => ['documentId' => 13],
    'a protected folder'            => ['documentId' => 11],
    'its own mode protected'        => ['documentId' => 15],
    'an inactive folder in the chain' => ['documentId' => 14],
    'an inactive document'          => ['documentId' => 18],
] as $label => $block) {
    $out = $image->render($block + ['alt' => 'x', 'caption' => 'y', 'link' => '/z'], $md);
    check("{$label} → ''", $out === '', $out);
}
check('the shared predicate agrees: protected and inactive are not publicly delivered',
    $svc->isPubliclyDelivered($documents->rows[10]) && !$svc->isPubliclyDelivered($documents->rows[11])
    && !$svc->isPubliclyDelivered($documents->rows[14]) && !$svc->isPubliclyDelivered($documents->rows[18])
    && !$svc->isPubliclyDelivered($documents->rows[12]));

// ── (E) escaping and the link gate ──────────────────────────────────────────────────────

echo "(E) author text escaped, link gated\n";
$html = $image->render(['documentId' => 10, 'alt' => '"><script>alert(1)</script>', 'caption' => '<b>Ufer</b> & **Steg**'], $md);
check('alt escaped', str_contains($html, 'alt="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"') && !str_contains($html, '<script'), $html);
check('caption escaped, plain text (no markdown)',
    str_contains($html, '<figcaption class="media-image__caption">&lt;b&gt;Ufer&lt;/b&gt; &amp; **Steg**</figcaption>'), $html);
$html = $image->render(['documentId' => 10, 'alt' => 'A', 'link' => '/kontakt?a=1&b=2'], $md);
check('a site link wraps the image, escaped',
    str_contains($html, '<a class="media-image__link" href="/kontakt?a=1&amp;b=2"><img ') && str_contains($html, '></a></figure>'), $html);
check('https link', str_contains($image->render(['documentId' => 10, 'alt' => 'A', 'link' => 'https://example.com/x'], $md), 'href="https://example.com/x"'));
foreach (['javascript:alert(1)', '//evil.example', 'data:text/html,x', ' JaVaScRiPt:x'] as $bad) {
    $out = $image->render(['documentId' => 10, 'alt' => 'A', 'link' => $bad], $md);
    check("link '{$bad}' → no link, image stays", !str_contains($out, '<a ') && str_contains($out, '<img '), $out);
}

// ── (F) preserved original, unmeasured image ────────────────────────────────────────────

echo "(F) preserved original, image without size\n";
$html = $image->render(['documentId' => 16, 'alt' => 'O'], $md);
check('thumbnail only: the original joins the srcset and is the src',
    str_contains($html, 'srcset="/media/bilder/team/original.s.jpg?v=66666666 160w, /media/bilder/team/original.jpg?v=66666666 3000w"')
    && str_contains($html, 'src="/media/bilder/team/original.jpg?v=66666666"') && str_contains($html, 'width="3000" height="2000"'), $html);
$html = $image->render(['documentId' => 17, 'alt' => 'Logo'], $md);
check('no size, no variants (SVG): the original alone, no srcset, no width',
    $html === '<figure class="media-image"><img class="media-image__img" src="/media/bilder/logo.svg?v=77777777" alt="Logo" loading="lazy" decoding="async"></figure>', $html);

// ── (G) gallery ─────────────────────────────────────────────────────────────────────────

echo "(G) media-gallery\n";
$html = $gallery->render(['type' => 'media-gallery', 'items' => [
    ['documentId' => 10, 'alt' => 'See', 'caption' => 'Am <Ufer>'],
    ['documentId' => 11, 'alt' => 'geschützt'],
    ['documentId' => 12, 'alt' => 'gelöscht'],
    'kein Objekt',
    ['documentId' => '', 'alt' => 'leer'],
    ['documentId' => '16', 'alt' => 'Original'],
]], $md);
check('wrapper + exactly the two good items, in list order',
    str_starts_with($html, '<div class="media-gallery"><figure class="media-gallery__item"><img class="media-gallery__img" ')
    && substr_count($html, '<figure class="media-gallery__item">') === 2
    && strpos($html, '/see.') < strpos($html, '/original.'), $html);
check('bad items leave no trace', !str_contains($html, 'geheim') && !str_contains($html, 'weg') && !str_contains($html, 'leer') && !str_contains($html, 'geschützt'), $html);
check('gallery sizes and the m variant as src (target 480)',
    str_contains($html, 'sizes="(max-width: 600px) 100vw, (max-width: 1024px) 50vw, 33vw"') && str_contains($html, 'src="/media/bilder/see.m.jpg?v=00000000"'), $html);
check('caption per item, escaped', str_contains($html, '<figcaption class="media-gallery__caption">Am &lt;Ufer&gt;</figcaption>'), $html);
check('no item left → the whole gallery is empty',
    $gallery->render(['items' => [['documentId' => 11, 'alt' => 'a'], ['documentId' => 404, 'alt' => 'b']]], $md) === '');
check('no items at all / not a list → empty',
    $gallery->render([], $md) === '' && $gallery->render(['items' => 'x'], $md) === '');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
