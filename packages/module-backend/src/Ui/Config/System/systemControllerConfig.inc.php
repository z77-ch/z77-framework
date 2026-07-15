<?php
namespace Z77\Module\Backend\Ui\Config;

/**
 * Per-controller layout config (example).
 *
 * Loaded by LayoutManager after the module-level `Ui/Config/layoutConfig.inc.php`
 * and applied on top of it, so it can extend or override the module layout for
 * this single controller — e.g. swap the skeleton, add/remove partials, or
 * register controller-specific CSS/JS:
 *
 *   return [
 *       'documentTpl'   => ['name' => 'html-fullwidth-skeleton', 'nameSpace' => '...'],
 *       'levelElements' => ['body' => ['sidebar' => 'partials/system-sidebar']],
 *       'styleSheets'   => [['name' => 'system', 'nameSpace' => '...']],
 *   ];
 *
 * Convention: file lives at `src/Ui/Config/{Group}/{controller}Config.inc.php`
 * (group-nested, mirroring the controller — see ADR-005). Empty config = no
 * override; the module layout applies unchanged.
 */
return [];
