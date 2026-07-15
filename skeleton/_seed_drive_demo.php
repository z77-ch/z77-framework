<?php
/** Demo seed for the DMS Drive (ADR-020) — one partition root (key `backend`) + folders +
 *  text documents. Removable: delete data/documents + data/blobs to clear.
 *  Run: php _seed_drive_demo.php */
define('ABS_BASE_PATH', str_replace('\\', '/', __DIR__));
define('ABS_INDEX_PATH', str_replace('\\', '/', __DIR__ . '/public'));
require_once ABS_BASE_PATH . '/vendor/autoload.php';

use Z77\Core\Bootstrap;
use Z77\Core\DI;
use Z77\Module\Dms\Entities\Folder;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Module\Dms\Services\SaveRequest;
use Z77\Module\Dms\Services\SaveService;

(new Bootstrap())->pullUp();

$em   = DI::getUnifiedEntityManager();
$save = SaveService::create();
$sort = 0;

// Direct EM persists (trusted system path — the domain gates need a session principal).
$mkFolder = function (string $name, ?int $parentId, bool $active = true, ?string $key = null, bool $system = false) use ($em, &$sort): int {
    $f = new Folder();
    $f->setName($name);
    $f->setKey($key);
    $f->setParentId($parentId ?? 0);
    $f->setSlug(Naming::toSlug($name));
    $f->setSortKey($sort += 10);
    $f->setSystem($system);
    $f->setActive($active);
    $f->setOwnerId(1);
    $em->persist($f);
    $em->flush();
    return (int) $f->getId();
};

$mkDoc = function (string $name, int $folderId, string $body, ?string $mode = null, bool $active = true) use ($save, $em): int {
    $doc = $save->save($body, new SaveRequest(
        originalName: $name, mimeType: 'text/plain', folderId: $folderId, createdBy: 1,
    ));
    if ($mode !== null || !$active) {
        if ($mode !== null) { $doc->setDeliveryMode($mode); }
        $doc->setActive($active);
        $doc->setUpdatedAt(gmdate('c'));
        $em->persist($doc);
        $em->flush();
    }
    return (int) $doc->getId();
};

// Partition root (module-declared: key + system → rename/move/delete-locked in the Drive)
$ablage = $mkFolder('Ablage', null, true, 'backend', true);

// Folder tree under the root
$vertraege  = $mkFolder('Verträge', $ablage);
$v2024      = $mkFolder('2024', $vertraege);
$v2025      = $mkFolder('2025', $vertraege);
$rechnungen = $mkFolder('Rechnungen', $ablage);
$bilder     = $mkFolder('Bilder', $ablage);
$marketing  = $mkFolder('Marketing', $ablage, false);

// Documents (text — no image variants/thumbnails without GD)
$mkDoc('readme.txt', $ablage, "Willkommen in der Ablage.\n");
$mkDoc('vertrag-muster.txt', $vertraege, "Mustervertrag …\n");
$mkDoc('agb-2025.txt', $vertraege, "AGB 2025 …\n", 'sealed');
$mkDoc('nebenkosten.txt', $vertraege, "Nebenkostenabrechnung …\n", 'public');
$mkDoc('altvertrag.txt', $v2024, "Vertrag 2024 …\n");
$mkDoc('rechnung-2025-01.txt', $rechnungen, "Rechnung Januar …\n", 'public');
$mkDoc('rechnung-2025-02.txt', $rechnungen, "Rechnung Februar …\n");
$mkDoc('intern-notiz.txt', $rechnungen, "Interne Notiz …\n", null, false);

echo "Seeded: Ablage(#{$ablage}, key=backend) Verträge(#{$vertraege}) 2024(#{$v2024}) 2025(#{$v2025}) Rechnungen(#{$rechnungen}) Bilder(#{$bilder}) Marketing(#{$marketing})\n";
echo "Open: /backend/documents/drive/list?folder={$vertraege}\n";
echo "Public URL shape: /media/ablage/rechnungen/rechnung-2025-01.txt\n";
