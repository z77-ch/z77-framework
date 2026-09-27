<?php
/**
 * The backend's browser-tab title. Two questions, in the order a truncated tab
 * answers them: WHICH installation, then WHICH screen.
 *
 * @var \Z77\Shared\Entities\MetaData|null            $metaData
 * @var \Z77\Module\Backend\Ui\BackendMenu|null       $backendMenu
 * @var string|null                                   $installationName
 */
// The screen: a MetaData title when a screen set one, otherwise the entry under
// the UI cursor — the same trail the crumb renders (BackendMenu::activeTrail),
// so the tab and the crumb can never disagree. Neither: the login, the first-run
// setup and any screen outside the menu keep «Backend».
$page = trim((string)($metaData?->getTitle() ?? ''));
if ($page === '') {
    $trail = $backendMenu?->activeTrail() ?? [];
    $page  = $trail === [] ? 'Backend' : $trail[count($trail) - 1]->getName();
}

// The installation FIRST: a browser cuts a tab title from the right, and with
// several projects open the part that must survive the cut is WHICH project
// this is. Empty on an installation with neither a name nor a canonicalBaseUrl.
$install = trim((string)($installationName ?? ''));
?>
<title><?= e($install !== '' ? $install . ' · ' . $page : $page) ?></title>
