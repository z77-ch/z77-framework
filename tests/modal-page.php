<?php

/**
 * MODAL-PAGE-001 harness (CLI, offline, no HTTP, no DB) — a dialog action
 * reached as a PAGE answers 303 to its list instead of 500 (fetch.md).
 *
 * What is load-bearing here:
 *
 *   - LayoutManager::hasPage() — the one decision: false when no config set
 *     `body.main` and the action has no `{action}.tpl.php`, false when a
 *     fragment pin (the list) is still in `main` and the action only appended
 *     its dialog to it; true for the list itself, for an action that keeps
 *     the pin alone (the mandator form), for one that replaced `main`
 *     (journal / invoice / report pages), and before / outside page mode;
 *   - AbstractBaseController::dialogPageFallback() — the requested URL with
 *     its action segment replaced by `list`, status 303, query dropped;
 *     `confirm-delete` matches `confirmDeleteAction`; an alias spelling or the
 *     list itself stays the error it is (no redirect loop);
 *   - run() asks hasPage() for every HtmlResponse.
 *
 * Verified live as well (2026-10-13, z77.ch on 8077, page vs fetch mode):
 * payment-terms, payment-target, dunning-level, tax-code, contact, navigation,
 * journal, drive — fetch.md MODAL-PAGE-001.
 *
 * Run: php tests/modal-page.php
 */

spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'   => '/../packages/kernel/core/src/',
        'Z77\\Shared\\' => '/../packages/kernel/shared/src/',
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

use Z77\Core\Controller\AbstractBaseController;
use Z77\Core\DI;
use Z77\Core\Exception\LayoutManagerException;
use Z77\Core\Http\Response\RedirectResponse;
use Z77\Core\Services\LayoutManager;
use Z77\Core\Services\MessageService;

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, mixed $got = null): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; return; }
    $fail++;
    echo "  FAIL {$label}" . ($got !== null ? "\n       got: " . var_export($got, true) : '') . "\n";
}

/** A LayoutManager in the state initialize() + the action left it. */
function layout(array $configuredMain, array $main, bool $templateMissing = false): LayoutManager
{
    $ref = new ReflectionClass(LayoutManager::class);
    $lm  = $ref->newInstanceWithoutConstructor();
    $ref->getProperty('configuredMain')->setValue($lm, $configuredMain);
    $ref->getProperty('actionTemplateMissing')->setValue($lm, $templateMissing);
    $ref->getProperty('partialFilePaths')->setValue($lm, $main === [] ? [] : ['body' => ['main' => $main]]);
    return $lm;
}

$pin  = '/m/Backend/PaymentTermsController/listAction.tpl.php';
$edit = '/m/Backend/PaymentTermsController/edit.tpl.php';
$page = '/m/Backend/JournalController/detail.tpl.php';

echo "hasPage()\n";
check('H1 fragment list: the pin alone is the page', layout([$pin], [$pin])->hasPage() === true);
check('H2 fragment dialog: pin + appended edit form → no page', layout([$pin], [$pin, $edit])->hasPage() === false);
check('H3 fragment page action that replaced main → page', layout([$pin], [$page])->hasPage() === true);
check('H4 fragment page that replaced main with the list again + more (journal capture) → page',
    layout([$pin], [$page, $pin])->hasPage() === true);
check('H5 a pin kept alone by another action (mandator edit) → page', layout(['/m/edit.tpl.php'], ['/m/edit.tpl.php'])->hasPage() === true);
check('H6 own action template resolved → page', layout([], ['/m/Content/NavigationController/listAction.tpl.php'])->hasPage() === true);
check('H7 no action template, dialog appended → no page', layout([], ['/m/edit.tpl.php'], true)->hasPage() === false);
check('H8 no action template, nothing added → no page', layout([], [], true)->hasPage() === false);
$lm = layout([$pin], [$pin]);
$lm->removeSection('main');
$ref = new ReflectionProperty(LayoutManager::class, 'partialFilePaths');
$ref->setValue($lm, ['body' => ['main' => [$pin, $page]]]);
check('H10 an action that dropped main and rebuilt it (list + more) → page', $lm->hasPage() === true);
check('H9 fetch mode / never initialized → page (the question does not arise)',
    (new ReflectionClass(LayoutManager::class))->newInstanceWithoutConstructor()->hasPage() === true);

echo "\ndialogPageFallback()\n";
$request = new class {
    public string $uri = '';
    public function getRawRequestUri(): string { return $this->uri; }
};
DI::getInstance()->set('Request', $request, true);

function fallback(string $action, string $uri): RedirectResponse|string
{
    global $request;
    $request->uri = $uri;
    $controller = new class($action) extends AbstractBaseController {};
    $ref = new ReflectionClass(AbstractBaseController::class);
    // Outside a session pushFlashAfterRedirect() does nothing — enough here.
    $ref->getProperty('messageService')->setValue($controller, (new ReflectionClass(MessageService::class))->newInstanceWithoutConstructor());
    try {
        return $ref->getMethod('dialogPageFallback')->invoke($controller);
    } catch (LayoutManagerException $e) {
        return 'exception: ' . $e->getMessage();
    }
}
function target(RedirectResponse|string $r): string
{
    if (is_string($r)) return $r;
    $ref = new ReflectionClass(RedirectResponse::class);
    return $ref->getProperty('status')->getValue($r) . ' ' . $ref->getProperty('url')->getValue($r);
}

$t = target(fallback('editAction', '/backend/finance/payment-terms/edit?id=1'));
check('P1 edit?id=1 → 303 to …/list, the query dropped', $t === '303 /backend/finance/payment-terms/list', $t);
$t = target(fallback('confirmDeleteAction', '/backend/content/navigation/confirm-delete?id=4'));
check('P2 confirm-delete matches confirmDeleteAction', $t === '303 /backend/content/navigation/list', $t);
$t = target(fallback('addAction', '/backend/contact/contact/add/'));
check('P3 a trailing slash is ignored', $t === '303 /backend/contact/contact/list', $t);
$t = target(fallback('editAction', '/kontakt-bearbeiten'));
check('P4 an alias spelling (no action segment) stays an error', str_starts_with($t, 'exception: '), $t);
$t = target(fallback('listAction', '/backend/finance/payment-terms/list'));
check('P5 the list itself without a page stays an error (no redirect loop)', str_starts_with($t, 'exception: '), $t);

echo "\nwiring\n";
$base = (string) file_get_contents(__DIR__ . '/../packages/kernel/core/src/Controller/AbstractBaseController.php');
check('W1 run() answers an HtmlResponse without a page by the fallback',
    (bool) preg_match('/\$response instanceof HtmlResponse && !\$this->layoutManager->hasPage\(\)\)\s*\{\s*return \$this->dialogPageFallback\(\);/', $base));
check('W2 the flashes html() took for the page are put back for the list',
    str_contains($base, 'pushFlashAfterRedirect($flash[\'type\'], $flash[\'text\'])'));

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
