<?php

/**
 * Form actions harness (CLI, offline) — ADR-049 revision 2026-10-10 and the ADR-047 addendum:
 * the shared action row `partials/modalActions` renders the primary submit FIRST (Enter presses
 * it), the cancel closes the popup or follows a window link, `--end` appears only when asked;
 * the `FetchResponse` row helpers emit the existing commands on the `data-entity` address;
 * the backend modal CSS pins header and row before the body (the `.be-modal__footer` first aid
 * is retired since 2026-10-13 — no template writes a footer).
 *
 * The last block is a lint: no dialog template may keep `.be-modal__footer` as the home of
 * its actions once the migration is through. Run: php tests/form-actions.php
 */

require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
require_once __DIR__ . '/../packages/kernel/core/src/Http/Response/ResponseInterface.php';
require_once __DIR__ . '/../packages/kernel/core/src/Http/Response/EnvelopeFields.php';
require_once __DIR__ . '/../packages/kernel/core/src/Http/Response/FetchResponse.php';

use Z77\Core\Http\Response\FetchResponse;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

/** A stand-in for TemplateRenderer: `$this->partial()` inside the row resolves the errors link. */
final class RowRenderer
{
    private const ROOT = __DIR__ . '/../packages/kernel/shared/res/view/templates/';

    public function render(string $path, array $context): string
    {
        extract($context, EXTR_SKIP);
        ob_start();
        require self::ROOT . $path . '.tpl.php';

        return (string) ob_get_clean();
    }

    public function partial(string $path, array $context = [], ?string $nameSpace = null): string
    {
        return $this->render($path, $context);
    }
}

$row = static fn(array $ctx = []): string => (new RowRenderer())->render('partials/modalActions', $ctx);

echo "modalActions partial\n";
$html = $row(['submit' => 'Speichern']);
check('renders the shared row class',              str_contains($html, 'class="z77-form-actions"'));
check('no --end by default',                       !str_contains($html, 'z77-form-actions--end'));
check('the submit is the PRIMARY look',            str_contains($html, 'be-btn be-btn--primary'));
check('the submit stands FIRST in document order', strpos($html, 'type="submit"') < strpos($html, 'data-popup-close'));
check('the cancel closes the popup: «Schliessen», quiet (owner 2026-10-22)', str_contains($html, '<button type="button" class="be-btn be-btn--quiet" data-popup-close>Schliessen</button>'));
check('… right-aligned: spacer, then the thin rule, then the close',
    preg_match('/z77-form-actions__spacer.*?z77-form-actions__sep.*?data-popup-close/s', $html) === 1);
$withSpacer = $row(['submit' => 'Speichern', 'extra' => '<span class="z77-form-actions__spacer"></span><a class="x-right">PDF</a>']);
check('… an extra that brings its own spacer gets no second one', substr_count($withSpacer, 'z77-form-actions__spacer') === 1);
$ghost = $row(['submit' => 'Speichern', 'cancelKind' => 'ghost']);
check('cancelKind ghost keeps the outlined button without spacer', str_contains($ghost, 'class="be-btn be-btn--ghost" data-popup-close>Schliessen<') && !str_contains($ghost, '__spacer'));
check('no errors link without errors',             !str_contains($html, 'z77-form-actions__errors'));

$html = $row(['submit' => 'Löschen', 'kind' => 'danger', 'end' => true]);
check('end renders the --end modifier (field-less confirm)', str_contains($html, 'class="z77-form-actions z77-form-actions--end"'));
check('danger kind',                                         str_contains($html, 'be-btn--danger'));

$html = $row(['submit' => '', 'cancel' => 'Schliessen']);
check('hub: no submit, the close stands alone', !str_contains($html, 'type="submit"') && str_contains($html, '>Schliessen</button>'));

$html = $row(['submit' => 'Speichern', 'cancel' => 'Abbrechen', 'cancelHref' => '/backend/x/detail?id=4']);
check('window: cancel is a data-window-link to the read view', str_contains($html, '<a class="be-btn be-btn--quiet" href="/backend/x/detail?id=4" data-window-link>Abbrechen</a>'));
check('window: no popup close in a window row',               !str_contains($html, 'data-popup-close'));

$html = $row(['submit' => 'Speichern', 'submitAttrs' => ['name' => 'op', 'value' => 'save', 'form' => 'f1', 'on"click' => 'x']]);
check('submit attributes are written',          str_contains($html, ' name="op" value="save" form="f1"'));
check('an attribute name that is no name is dropped', !str_contains($html, 'on"click'));

$html = $row(['submit' => 'Speichern', 'errors' => ['count' => 2, 'target' => 'field-name'], 'extra' => '<span class="x-extra">more</span>']);
check('errors render the «2 Fehler» label for the first invalid field', str_contains($html, '<label class="z77-form-actions__errors" for="field-name">2 Fehler</label>'));
check('extra controls are passed through raw',                         str_contains($html, '<span class="x-extra">more</span>'));
check('extra stands between the submit and the cancel (journal shape)', strpos($html, 'type="submit"') < strpos($html, 'x-extra') && strpos($html, 'x-extra') < strpos($html, 'data-popup-close'));
check('errors link stands last',                                       strpos($html, 'data-popup-close') < strpos($html, 'z77-form-actions__errors'));

$html = $row(['submit' => '<b>x</b>', 'cancel' => '"q"']);
check('labels are escaped', str_contains($html, '&lt;b&gt;x&lt;/b&gt;') && str_contains($html, '&quot;q&quot;'));

echo "FetchResponse row helpers\n";
// send() writes headers — read the envelope the way send() builds it, without the output.
$envelope = static fn(FetchResponse $r): array => (new ReflectionMethod($r, 'build'))->invoke($r);
check('rowTarget is the data-entity address',   FetchResponse::rowTarget('invoice', 42) === '[data-entity="invoice:42"]');
check('listTarget is the data-entity-list address', FetchResponse::listTarget('invoice') === '[data-entity-list="invoice"]');

$env = $envelope((new FetchResponse())->replaceRow('invoice', 42, '<tr>x</tr>'));
check('replaceRow → replace-html on the row', $env['commands'][0] === ['action' => 'replace-html', 'target' => '[data-entity="invoice:42"]', 'html' => '<tr>x</tr>']);
check('no origin key without an origin',      !array_key_exists('origin', $env['commands'][0]));

$env = $envelope((new FetchResponse())->removeRow('invoice', 42, 'region:invoice-list'));
check('removeRow → remove-element, scoped to the origin', $env['commands'][0] === ['action' => 'remove-element', 'target' => '[data-entity="invoice:42"]', 'origin' => 'region:invoice-list']);

$env = $envelope((new FetchResponse())->insertRow('invoice', '<tr>n</tr>'));
check('insertRow → insert-html, append into the list', $env['commands'][0] === ['action' => 'insert-html', 'target' => '[data-entity-list="invoice"]', 'html' => '<tr>n</tr>', 'position' => 'append']);
$env = $envelope((new FetchResponse())->insertRow('invoice', '<tr>n</tr>', 'after', '', FetchResponse::rowTarget('invoice', 41)));
check('insertRow after a sibling row',                  $env['commands'][0]['target'] === '[data-entity="invoice:41"]' && $env['commands'][0]['position'] === 'after');
$env = $envelope((new FetchResponse())->replaceRow('a', 1, 'x')->addCommand('close-modal'));
check('helpers chain with addCommand, order kept',      $env['commands'][1] === ['action' => 'close-modal']);

echo "backend modal CSS order\n";
$scss = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/scss/components/_modal.scss');
check('header ordered first',                      preg_match('/\.be-modal \.be-modal__header\s*\{\s*order:\s*-2;/', $scss) === 1);
check('the retired footer first aid is gone (no .be-modal__footer rule)', preg_match('/\.be-modal__footer\s*\{/', $scss) === 0);
check('the shared row inside a dialog is ordered before the body', preg_match('/\.be \.be-modal \.z77-form-actions\s*\{\s*order:\s*-1;/', $scss) === 1);
check('--end keeps the bottom (order 1, wherever the template wrote it)', preg_match('/\.be \.be-modal \.z77-form-actions--end\s*\{\s*order:\s*1;/', $scss) === 1);
check('the toolbar slot has its data hook',        str_contains((string) file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php'), 'data-shell-slot="hc2"'));

echo "lint: no dialog keeps .be-modal__footer as the home of its actions\n";
$left = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../packages', FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (str_ends_with($f->getFilename(), '.tpl.php') && str_contains((string) file_get_contents($f->getPathname()), 'be-modal__footer')) {
        $left[] = $f->getPathname();
    }
}
$left = array_values(array_unique($left));
check('no template renders .be-modal__footer (' . count($left) . ' left)', $left === []);
foreach ($left as $file) {
    echo "       - " . substr($file, strlen(__DIR__ . '/../')) . "\n";
}

echo "lint: a row ⋮ of actions is a context menu, not a dialog (owner 2026-10-22)\n";
$pkg = __DIR__ . '/../packages/';
$menus = [
    'module-backend/res/view/templates/Content/ContentController/actions.tpl.php'           => 'module-backend/res/view/templates/Content/ContentController/_row.tpl.php',
    'module-backend/res/view/templates/Content/MetaDataController/actions.tpl.php'          => 'module-backend/res/view/templates/Content/MetaDataController/listAction.tpl.php',
    'module-backend/res/view/templates/Content/NavigationController/actions.tpl.php'        => 'module-backend/res/view/templates/Content/NavigationController/listAction.tpl.php',
    'module-backend/res/view/templates/Content/NavigationAliasController/actions.tpl.php'   => 'module-backend/res/view/templates/Content/NavigationAliasController/_row.tpl.php',
    'module-backend/res/view/templates/Content/TranslationController/actions.tpl.php'       => 'module-backend/res/view/templates/Content/TranslationController/listAction.tpl.php',
    'module-backend/res/view/templates/Service/BackupController/actions.tpl.php'            => 'module-backend/res/view/templates/Service/BackupController/_section.tpl.php',
    'module-backend/res/view/templates/Service/EmailSettingsController/actions.tpl.php'     => 'module-backend/res/view/templates/Service/EmailSettingsController/_row.tpl.php',
    'module-backend/res/view/templates/Service/JobController/actions.tpl.php'               => 'module-backend/res/view/templates/Service/JobController/_job.tpl.php',
    'module-backend/res/view/templates/System/BackendUserController/actions.tpl.php'        => 'module-backend/res/view/templates/System/BackendUserController/_row.tpl.php',
    'module-financial/res/view/templates/Backend/FiscalYearController/actions.tpl.php'      => 'module-financial/res/view/templates/Backend/FiscalYearController/listAction.tpl.php',    'module-dms/res/view/templates/Documents/DriveController/_actions.tpl.php'               => 'module-dms/res/view/templates/Documents/DriveController/_list.tpl.php',
];
$asDialog = [];
$badTrigger = [];
foreach ($menus as $hub => $list) {
    $h = (string) file_get_contents($pkg . $hub);
    $l = (string) file_get_contents($pkg . $list);
    if (!str_contains($h, '<div class="be-menu" role="menu"') || !str_contains($h, 'role="menuitem"')
        || preg_match('/be-modal__header|be-modal__body|modalActions|data-popup-close/', $h) === 1) {
        $asDialog[] = $hub;
    }
    if (preg_match('/aria-haspopup="menu" aria-expanded="false"\s+data-menu-open="[^"]*\/actions\?/', $l) !== 1
        || preg_match('/data-fetch-get="[^"]*\/actions\?/', $l) === 1) {
        $badTrigger[] = $list;
    }
}
// The Drive's folder tree opens the same menu (documents: the list above).
$tree = (string) file_get_contents($pkg . 'module-dms/res/view/templates/Documents/DriveController/_tree.tpl.php');
if (preg_match('/aria-haspopup="menu" aria-expanded="false" data-menu-open="[^"]*\/drive\/actions\?type=folder/', $tree) !== 1 || str_contains($tree, 'data-modal="<?= $base ?>/drive/actions')) {
    $badTrigger[] = 'module-dms/res/view/templates/Documents/DriveController/_tree.tpl.php';
}
check('the eleven action hubs (with the Drive) render only the menu: role="menu" + menuitems, no header, no action row, no close (' . count($asDialog) . ' left)', $asDialog === []);
check('their ⋮ opens the menu (data-menu-open + aria-haspopup/aria-expanded), none fetches a dialog any more (' . count($badTrigger) . ' left)', $badTrigger === []);
foreach (array_merge($asDialog, $badTrigger) as $file) {
    echo "       - packages/{$file}\n";
}
$job = (string) file_get_contents($pkg . 'module-backend/res/view/templates/Service/JobController/actions.tpl.php');
check('the job menu keeps «Jetzt starten» as its small data-fetch-post form', preg_match('/<form class="be-menu__form" role="none" data-fetch-post="\/backend\/service\/job\/run">.*?type="submit" class="be-menu__item"/s', $job) === 1);
check('contact and VAT hubs stay dialogs until the owner decides (addresses / rates inside)',
    str_contains((string) file_get_contents($pkg . 'module-contact/res/view/templates/Backend/ContactController/_row.tpl.php'), '/actions?id=')
    && preg_match('/data-fetch-get="[^"]*\/actions\?id=/', (string) file_get_contents($pkg . 'module-vat/res/view/templates/Backend/TaxCodeController/_row.tpl.php')) === 1);
$menuScss = (string) file_get_contents($pkg . 'module-backend/res/scss/components/_menu.scss');
check('_menu.scss: the fixed box above the windows, the card, the danger item; compiled into base.scss',
    preg_match('/\.z77-menu\s*\{[^}]*position:\s*fixed;[^}]*z-index:\s*var\(--z-overlay\)/s', $menuScss) === 1
    && str_contains($menuScss, '.be-menu__item--danger') && str_contains((string) file_get_contents($pkg . 'module-backend/res/scss/base.scss'), "@use 'components/menu';"));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
