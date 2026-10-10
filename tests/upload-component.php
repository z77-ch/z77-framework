<?php

/**
 * Upload harness (CLI, offline) — the upload component's contract (UPLOAD-001, owner
 * 2026-10-09): what the policy lets in, what the partial writes, and that the client's
 * hooks and the server's attributes are the SAME names.
 *
 * The three things that cannot be checked here need a browser and are listed in
 * `docs/topics/fetch.md`: the drag states, the progress bars and the parallel queue.
 * What IS checked is the part that silently rots — a renamed attribute on one side only.
 *
 * Run: php tests/upload-component.php
 */

spl_autoload_register(static function (string $class): void {
    $map = ['Z77\\Shared\\' => __DIR__ . '/../packages/kernel/shared/src/'];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

use Z77\Shared\Upload\UploadPolicy;
use Z77\Shared\ValueObjects\UploadedFile;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

function throws(string $label, callable $fn): void
{
    try {
        $fn();
        check($label . ' (throws)', false);
    } catch (\Throwable) {
        check($label . ' (throws)', true);
    }
}

/** A real temp file, so sniffMime() has something to look at. */
function fileWith(string $name, string $content, ?int $size = null, int $error = UPLOAD_ERR_OK): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'z77up');
    file_put_contents($tmp, $content);

    return new UploadedFile($name, $tmp, $size ?? filesize($tmp), 'application/octet-stream', $error);
}

$xml = "<?xml version=\"1.0\"?><Document xmlns=\"urn:iso:std:iso:20022\"/>";
// A REAL 1x1 PNG: the sniffer reads the IHDR, so hand-made «PNG» bytes come back as
// application/octet-stream and would prove nothing about an `image/*` rule.
$png = (string) base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
);

echo "\npolicy — what comes in\n";
$camt = new UploadPolicy(
    endpoint: '/backend/finance/bank-import/upload',
    field:    'file',
    accept:   ['.xml'],
    maxBytes: 5 * 1024 * 1024,
    onConflict: UploadPolicy::CONFLICT_ERROR,
);
check('an .xml passes',              $camt->check(fileWith('camt054.xml', $xml)) === null);
check('a .jpg is refused by name',   $camt->check(fileWith('foto.jpg', $xml)) !== null);
check('too big names the limit',     str_contains((string) $camt->check(fileWith('big.xml', $xml, 9 * 1024 * 1024)), '5 MB'));
check('an empty file is refused',    $camt->check(fileWith('leer.xml', '')) !== null);
check('a transport error is refused', $camt->check(fileWith('x.xml', $xml, null, UPLOAD_ERR_INI_SIZE)) !== null);
check('no accept list = anything',   (new UploadPolicy(endpoint: '/u'))->check(fileWith('foto.jpg', $png)) === null);

$images = new UploadPolicy(endpoint: '/u', accept: ['image/*']);
check('image/* matches the SNIFFED type', $images->check(fileWith('logo.png', $png)) === null);
check('image/* refuses a renamed xml',     $images->check(fileWith('logo.png', $xml)) !== null);

echo "\npolicy — the limits\n";
check('maxBytes never exceeds the transport cap',
    (new UploadPolicy(endpoint: '/u', maxBytes: PHP_INT_MAX))->effectiveMaxBytes() === UploadPolicy::transportMaxBytes());
check('no maxBytes = the transport cap',
    (new UploadPolicy(endpoint: '/u'))->effectiveMaxBytes() === UploadPolicy::transportMaxBytes());
check('5 MB formats as «5 MB»',   UploadPolicy::formatBytes(5 * 1024 * 1024) === '5 MB');
check('900 KB formats as KB',     UploadPolicy::formatBytes(900 * 1024) === '900 KB');
throws('an empty endpoint',       static fn() => new UploadPolicy(endpoint: ''));
throws('an unknown shape',        static fn() => new UploadPolicy(endpoint: '/u', shape: 'balloon'));
throws('an unknown conflict mode', static fn() => new UploadPolicy(endpoint: '/u', onConflict: 'maybe'));

echo "\npolicy — one endpoint, two shapes\n";
$cell = $camt->withShape('cell');
check('withShape keeps the endpoint',  $cell->endpoint === $camt->endpoint);
check('withShape keeps the limit',     $cell->effectiveMaxBytes() === $camt->effectiveMaxBytes());
check('withShape keeps the conflict mode', $cell->onConflict === $camt->onConflict);
check('withShape changes the shape',   $cell->shape === 'cell' && $camt->shape === 'drop');

echo "\nthe attributes the client reads\n";
$attributes = $camt->attributes();
check('action is the endpoint',   ($attributes['action'] ?? '') === '/backend/finance/bank-import/upload');
check('data-upload names the shape', ($attributes['data-upload'] ?? '') === 'drop');
check('data-upload-max is bytes',  ($attributes['data-upload-max'] ?? '') === (string) (5 * 1024 * 1024));
check('data-upload-accept is joined', ($attributes['data-upload-accept'] ?? '') === '.xml');
check('data-upload-conflict travels', ($attributes['data-upload-conflict'] ?? '') === 'error');
check('single upload writes no multiple',
    !array_key_exists('data-upload-multiple', (new UploadPolicy(endpoint: '/u', multiple: false))->attributes()));

echo "\nboth sides use the same names\n";
$js      = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/upload.js');
$partial = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/view/templates/partials/upload.tpl.php');
$scss    = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/scss/components/_upload.scss');

foreach (['data-upload', 'data-upload-max', 'data-upload-accept', 'data-upload-multiple', 'data-upload-conflict'] as $name) {
    check("{$name}: policy → js", str_contains($js, $name));
}
foreach (['data-upload-list', 'data-upload-summary', 'data-upload-bar', 'data-upload-count', 'data-upload-submit'] as $hook) {
    check("{$hook}: partial → js", str_contains($partial, $hook) && str_contains($js, $hook));
}

echo "\nthe fallback, and what the styles must carry\n";
check('the partial is a real multipart form',
    str_contains($partial, 'method="post"') && str_contains($partial, 'enctype="multipart/form-data"'));
check('a submit button exists without the script', str_contains($partial, 'data-upload-submit'));
check('the script hides it (is-scripted)',
    str_contains($js, 'is-scripted') && str_contains($scss, '.z77-upload.is-scripted .z77-upload__submit'));
check('the file input is a real input, labelled',
    str_contains($partial, 'type="file"') && str_contains($partial, '<label') && str_contains($partial, 'for="'));
check('the input is visually hidden, not display:none', str_contains($scss, 'clip-path: inset(50%)'));
foreach (['is-over', 'is-over-bad', 'is-busy'] as $state) {
    check("state .{$state} is styled", str_contains($scss, '.z77-upload.' . $state));
}
check('the row carries an icon AND a message slot',
    str_contains($scss, '.z77-upload-row__icon') && str_contains($scss, '.z77-upload-row__message')
    && str_contains($js, 'z77-upload-row__icon'));
check('reduced motion is honoured', str_contains($scss, 'prefers-reduced-motion'));

echo "\nthe queue's rules\n";
check('three files at a time',            str_contains($js, 'var PARALLEL = 3'));
check('XHR (progress needs it)',          str_contains($js, 'new XMLHttpRequest'));
check('the CSRF header is sent',          str_contains($js, 'X-CSRF-Token'));
check('commands run once, at the end',    str_contains($js, 'this.commands') && str_contains($js, 'handleEnvelope'));
check('a nested drop does not double up', str_contains($js, 'stopPropagation'));
check('the four conflict modes are handled',
    str_contains($js, "'overwrite'") && str_contains($js, "'skip'") && str_contains($js, "'error'") && str_contains($js, "item.state = 'asking'"));

echo "\nthe backend host binding\n";
$host = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/scss/components/_upload-host.scss');
$base = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/scss/base.scss');
$css  = __DIR__ . '/../packages/module-backend/res/assets/css/base.css';
check('the host binds the page accent, not the island one',
    str_contains($host, '--z77-up-accent:      var(--be-accent-page)'));
check('both partials are compiled into the bundle',
    str_contains($base, 'components/upload') && str_contains($base, "@use 'components/upload-host'"));
check('the compiled css carries the component', is_file($css) && str_contains((string) file_get_contents($css), 'z77-upload'));
check('the script is registered in the backend layout',
    str_contains((string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Config/layoutConfig.inc.php'), "'name' => 'upload'"));

echo "\nfirst user: Zahlungseingänge\n";
$trait = (string) file_get_contents(__DIR__ . '/../packages/module-debtor/src/Ui/BankImportControllerTrait.php');
$act   = (string) file_get_contents(__DIR__ . '/../packages/module-debtor/res/view/templates/Backend/BankImportController/act.tpl.php');
$list  = (string) file_get_contents(__DIR__ . '/../packages/module-debtor/res/view/templates/Backend/BankImportController/listAction.tpl.php');
check('the policy lives in the controller',   str_contains($trait, 'function bankUploadPolicy'));
check('the server checks with it',            str_contains($trait, '$policy->check($file)'));
check('fetch mode answers an envelope',       str_contains($trait, 'RequestMode::Fetch') && str_contains($trait, "'status'   => 'ok'"));
check('page mode keeps flash + redirect',     str_contains($trait, 'pushFlashAfterRedirect') && str_contains($trait, '303'));
check('a camt.054 already imported is an error, never overwritten',
    str_contains($trait, 'CONFLICT_ERROR'));
check('the action cell is the cell shape',    str_contains($act, "withShape('cell')"));
// Owner 2026-10-10, after the live look: no upload box in the middle. The page keeps the
// drop AREA and the place the queue is moved to; the button in the cell is the surface.
check('the page has NO upload box of its own',   !str_contains($list, "partial('partials/upload'"));
check('the work area is still the drop target',  str_contains($list, 'data-upload-area'));
check('the progress has a place with room',      str_contains($list, 'data-upload-progress')
    && str_contains($js, "[data-upload-progress]") && str_contains($js, 'z77-upload-queue'));
check('the moved queue keeps its tokens',
    str_contains($scss, '.z77-upload-queue') && str_contains($host, '.be .z77-upload-queue'));
check('the cell label is short',                 str_contains($trait, "'camt.054 Upload'"));
check('the phone hides the label the shell way', str_contains($partial, 'be-btn__label'));
check('the form fills the action cell',          str_contains($host, '.be-shell-band__slot--1 > .z77-upload--cell'));
check('the old hand-written file field is gone', !str_contains($list, 'id="bank-file"'));

echo "\ntwo caps (the Drive's case)\n";
$drive = new UploadPolicy(
    endpoint: '/backend/documents/drive/upload',
    field:    'files[]',
    maxBytes: 64 * 1024 * 1024,
    maxBytesPer: ['image/*' => 8 * 1024 * 1024],
);
$transport = UploadPolicy::transportMaxBytes();
check('a non-image keeps the general cap',
    $drive->maxBytesFor('film.mp4', 'video/mp4') === min(64 * 1024 * 1024, $transport));
check('an image gets the smaller cap',
    $drive->maxBytesFor('bild.png', 'image/png') === min(8 * 1024 * 1024, $transport));
check('the per-kind cap never exceeds the transport cap',
    (new UploadPolicy(endpoint: '/u', maxBytesPer: ['image/*' => PHP_INT_MAX]))->maxBytesFor('b.png', 'image/png') === $transport);
check('an extension pattern works too',
    (new UploadPolicy(endpoint: '/u', maxBytes: 9 * 1024 * 1024, maxBytesPer: ['.png' => 1024]))->maxBytesFor('b.png', '') === 1024);
check('the map travels as JSON for the client',
    json_decode((string) ($drive->attributes()['data-upload-max-per'] ?? ''), true) !== null);
check('withShape keeps the per-kind caps', $drive->withShape('cell')->maxBytesPer === $drive->maxBytesPer);
check('the image cap is what the row reports',
    str_contains((string) $drive->check(fileWith('bild.png', $png, 9 * 1024 * 1024)), '8 MB'));
check('the client resolves the same map', str_contains($js, 'data-upload-max-per') && str_contains($js, 'limitFor'));

echo "\nwhat only a module can add per file\n";
check('the component offers a field provider', str_contains($js, 'function provider(name, fn)') && str_contains($js, 'data-upload-extra'));
check('a provider failure is not the upload\'s', str_contains($js, ".catch(function () { return null; })"));
check('the partial passes the provider name', str_contains($partial, 'data-upload-extra'));
check('the partial renders the consumer\'s own fields inside the form', str_contains($partial, '$fields'));

echo "\nthe house envelope\n";
foreach (['duplicate', 'conflict', 'validation'] as $status) {
    check("status {$status} is handled", str_contains($js, "'" . $status . "'"));
}
check('data.message is read',            str_contains($js, 'data.message'));
check('flashes/messages reach core',     str_contains($js, 'answer.flashes') && str_contains($js, 'handleEnvelope'));
check('commands are held back on a failed run', str_contains($js, 'if (failed === 0 && commands.length'));

echo "\nthe Drive gave up its own uploader\n";
$driveTrait = (string) file_get_contents(__DIR__ . '/../packages/module-dms/src/Ui/DriveControllerTrait.php');
$driveModal = (string) file_get_contents(__DIR__ . '/../packages/module-dms/res/view/templates/Documents/DriveController/_upload.tpl.php');
$posterFile = __DIR__ . '/../packages/module-dms/res/assets/js/documents/upload-poster.js';
check('documents/upload.js is gone',        !is_file(__DIR__ . '/../packages/module-dms/res/assets/js/documents/upload.js'));
check('its minified copy is gone',          !is_file(__DIR__ . '/../packages/module-dms/res/assets/js/documents/upload.min.js'));
check('its styles are gone',                !is_file(__DIR__ . '/../packages/module-dms/res/scss/components/_upload.scss'));
check('and are no longer imported',         !str_contains((string) file_get_contents(__DIR__ . '/../packages/module-dms/res/scss/dms.scss'), "components/upload"));
check('the Drive has an upload policy',     str_contains($driveTrait, 'function driveUploadPolicy'));
check('with both caps',                    str_contains($driveTrait, 'serverMaxBytes()') && str_contains($driveTrait, "'image/*' => UploadService::effectiveMaxUploadBytes()"));
check('conflicts still ASK in the Drive',   str_contains($driveTrait, 'CONFLICT_ASK'));
check('the modal renders the component',    str_contains($driveModal, "partial('partials/upload'"));
check('folder + original ride inside the form',
    str_contains($driveModal, "name=\"folder_id\"") && str_contains($driveModal, "name=\"show_original\"") && str_contains($driveModal, "'fields' => \$fields"));
check('the poster provider is the Drive\'s own', is_file($posterFile)
    && str_contains((string) file_get_contents($posterFile), "provider('dms-poster'"));
check('the modal asks for it',              str_contains($driveModal, "'extra'  => 'dms-poster'"));
check('the poster script is lazy-loaded + the popup is bound',
    str_contains($driveTrait, "documents/upload-poster") && str_contains($driveTrait, "'bind-upload'"));
check('success refreshes the panes and closes the modal', str_contains($driveTrait, 'paneRefresh($folderId'));
check('the component knows the bind-upload command', str_contains($js, "registerCommand('bind-upload'"));

echo "\n{$pass} ok, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
