<?php
namespace Z77\Module\Api\Ui\Config;

/**
 * Layout of the app shell `/app` (plan A6 / T6) — a phone page, not the website and not the
 * backend: its own skeleton, one stylesheet, one script (`_Z77.app`), nothing else. No `core.js`:
 * the shell talks to the stateless gateway (`/api`, its own headers), never to a session fetch
 * endpoint. The pages run under an ENFORCED CSP (`AppController::CSP`), so nothing here may be
 * inline — no `<script>` body, no `style=` attribute.
 *
 * The screens a module contributes (`appScreens`) add their own scripts per page.
 */
return [
    'documentTpl' => [
        'name'      => 'html-app-skeleton',
        'nameSpace' => 'Z77\\Module\\Api',
    ],
    'styleSheets' => [
        ['nameSpace' => 'Z77\\Module\\Api', 'name' => 'app', 'media' => ''],
    ],
    'levelElements' => [
        'head' => [],
        'body' => [],
    ],
    'javascripts' => [
        ['name' => 'app', 'nameSpace' => 'Z77\\Module\\Api', 'defer' => true],
    ],
];
