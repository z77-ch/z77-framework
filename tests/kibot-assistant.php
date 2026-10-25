<?php

/**
 * Backend KI-Assistent harness (CLI) — ADR-055: the help index generator
 * (Z77\Core\Kibot\HelpKnowledge), the backend wiring (BackendAssistant,
 * AssistantController) and the opener in the shell, without network and key.
 *
 * What is load-bearing here:
 *
 *   - the generator finds every `*.help.tpl.php` of every namespace, an override
 *     earlier in the lookup order WINS over the package's template (Rule 1), a
 *     template that needs its controller's variables falls back to its static text,
 *     one that does not parse is skipped and logged; ids are HelpService::topicIdFor();
 *     the result is a valid KiBot topic set;
 *   - lazy rebuild: fresh after a build, stale when a template is newer or the set of
 *     templates changed; ensureFresh() rebuilds exactly then;
 *   - BackendAssistant::config(): from the shared project file only key/log/api/limits —
 *     never the website's texts, prompt, CTAs or sources; the `backend` section wins;
 *   - off (classic help) without config file, without key, with a broken file;
 *   - every logged-in backend user (EDITOR) may call config/ask, a guest may not;
 *   - ask: bad CSRF refused before the API, the screen's help topic reaches the routing
 *     prompt as context, an unknown context is ignored, topics stored under the backend's
 *     own session key;
 *   - the controller and the shell keep the rules (Fetch, typed helpers, no superglobals,
 *     trigger falls back to «Hilfe»), the widget builds no HTML from data.
 *
 * Run: php tests/kibot-assistant.php
 * No DB, no composer install, no API key, no network. Throwaway dir in the system temp.
 */

declare(strict_types=1);

$work = sys_get_temp_dir() . '/z77-kibot-assistant-' . bin2hex(random_bytes(4));
mkdir($work, 0777, true);
define('ABS_BASE_PATH', $work);

require __DIR__ . '/../packages/kibot/autoload.php';
require_once __DIR__ . '/../packages/kernel/core/src/autoload/prod/php/Helper.php';
spl_autoload_register(static function (string $class): void {
    $map = [
        'Z77\\Core\\'            => __DIR__ . '/../packages/kernel/core/src/',
        'Z77\\Shared\\'          => __DIR__ . '/../packages/kernel/shared/src/',
        'Z77\\Module\\Backend\\' => __DIR__ . '/../packages/module-backend/src/',
    ];
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

use Z77\Core\Config\AuthRole;
use Z77\Core\Http\Security\CsrfService;
use Z77\Core\Kibot\HelpKnowledge;
use Z77\Core\Kibot\KibotEndpoint;
use Z77\Core\Kibot\KibotSession;
use Z77\Core\Services\HelpService;
use Z77\Core\Services\TemplateRenderer;
use Z77\Core\Session\SessionManager;
use Z77\KiBot\HttpResult;
use Z77\KiBot\Topics;
use Z77\KiBot\Transport;
use Z77\Module\Backend\Ui\Assistant\BackendAssistant;
use Z77\Shared\Libraries\Convention\Naming;
use Z77\Shared\Services\AuthService;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok   {$label}\n"; }
    else     { $fail++; echo "  FAIL {$label}\n"; }
}

final class FakeTransport implements Transport
{
    public array $requests = [];
    /** @var list<HttpResult> */
    public array $queue = [];

    public function send(string $url, array $headers, string $body, ?callable $onChunk = null): HttpResult
    {
        $this->requests[] = json_decode($body, true);
        $result = array_shift($this->queue) ?? new HttpResult(500, '{"type":"error","error":{"type":"api_error","message":"queue empty"}}');
        if ($onChunk !== null && $result->status !== 0) {
            foreach (str_split($result->body, 7) as $chunk) {
                $onChunk($chunk);
            }
        }
        return $result;
    }
}

function sseAnswer(string $text): string
{
    $events = [
        ['type' => 'message_start', 'message' => ['id' => 'msg_2', 'model' => 'claude-sonnet-5-5', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 900, 'output_tokens' => 1]]],
        ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
        ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $text]],
        ['type' => 'content_block_stop', 'index' => 0],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 42]],
        ['type' => 'message_stop'],
    ];
    $out = '';
    foreach ($events as $e) {
        $out .= 'event: ' . $e['type'] . "\n" . 'data: ' . json_encode($e, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    return $out;
}

function routingTo(array $ids): HttpResult
{
    return new HttpResult(200, json_encode([
        'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-haiku-5-5',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lies_themen', 'input' => ['ids' => $ids]]],
        'stop_reason' => 'tool_use', 'usage' => ['input_tokens' => 300, 'output_tokens' => 20],
    ], JSON_UNESCAPED_UNICODE));
}

function ask(KibotEndpoint $endpoint, array $body, KibotSession $session): array
{
    $frames = [];
    $endpoint->ask($body, '198.51.100.7', $session, static function (string $frame) use (&$frames): void {
        $raw      = substr($frame, 6, -2);
        $frames[] = $raw === '[DONE]' ? '[DONE]' : json_decode($raw, true);
    });
    return $frames;
}

function put(string $file, string $content, ?int $mtime = null): void
{
    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }
    file_put_contents($file, $content);
    if ($mtime !== null) {
        touch($file, $mtime);
        clearstatcache(true, $file);
    }
}

function rmTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

$base = rtrim(str_replace('\\', '/', $work), '/');

// ── topic ids ───────────────────────────────────────────────────────────────
echo "topic id\n";
check('id from namespace + path, vendor prefix and .help dropped',
    HelpService::topicIdFor('Backend/OrderController/edit.help', 'Z77\\Module\\Order') === 'module-order-backend-order-controller-edit');
check('trailing namespace backslash and .tpl.php make no difference',
    HelpService::topicIdFor('Backend/OrderController/edit.help.tpl.php', 'Z77\\Module\\Order\\') === 'module-order-backend-order-controller-edit');
$long = HelpService::topicIdFor('Backend/AVeryLongControllerNameForTestingPurposes/anEvenLongerActionNameThatGoesOn.help', 'Z77\\Module\\Something');
check('an id longer than 64 characters is cut and hashed, still a valid KiBot id',
    strlen($long) <= 64 && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $long) === 1);

// ── generator ───────────────────────────────────────────────────────────────
echo "generator\n";
$pkgA = $work . '/vendor/z77/module-alpha/res/view/templates';
$ovrA = $work . '/override/z77/module/alpha/res/view/templates';
$pkgB = $work . '/vendor/z77/module-beta/res/view/templates';
$past = time() - 3600;

put($pkgA . '/Backend/ThingController/list.help.tpl.php',
    "<?php /** doc */ ?>\n<h3>Dinge</h3>\n<p>Alle Dinge in einer Liste. Der Reiter «Aktiv» zeigt die aktiven.</p>\n<section data-help-field=\"name\">\n<h3>Name</h3>\n<p>Der Name des Dings.</p>\n</section>\n", $past);
put($pkgA . '/Backend/ThingController/edit.help.tpl.php', "<h3>Ding (Paket)</h3><p>Paket-Text.</p>", $past);
put($ovrA . '/Backend/ThingController/edit.help.tpl.php', "<h3>Ding</h3><p>Projekt-Text aus dem Override.</p><ul><li>Erster Punkt</li></ul>", $past);
put($pkgB . '/Backend/OtherController/form.help.tpl.php',
    "<?php \$vat = \$liableToVat ?? true; ?>\n<p>Formular <?= \$form->kind ?> mit Feldern.</p>\n<section data-help-field=\"amount\"><h3>Betrag</h3><p>In Franken.</p></section>\n", $past);
put($pkgB . '/Backend/BrokenController/list.help.tpl.php', "<h3>Kaputt</h3>\n<?php if ( ?>\n<p>nie</p>\n", $past);
put($pkgB . '/Backend/OtherController/form.tpl.php', '<p>no help template</p>', $past);

$logLines = [];
$roots    = [
    'Z77\\Module\\Alpha\\' => [$ovrA, $pkgA],
    'Z77\\Module\\Beta\\'  => [$pkgB],
];
$outDir    = $base . '/data/kibot/help';
$knowledge = new HelpKnowledge($roots, $outDir, static function (string $line) use (&$logLines): void { $logLines[] = $line; });

$found = $knowledge->discover();
$idList  = 'module-alpha-backend-thing-controller-list';
$idEdit  = 'module-alpha-backend-thing-controller-edit';
$idForm  = 'module-beta-backend-other-controller-form';
$idBroke = 'module-beta-backend-broken-controller-list';
check('discovers every *.help.tpl.php (and nothing else)', array_keys($found) === [$idEdit, $idList, $idBroke, $idForm]);
check('override wins over the package template (Rule 1)', str_starts_with($found[$idEdit]['file'], str_replace('\\', '/', $ovrA)));
check('stale before the first build', $knowledge->isStale($found));

$report = $knowledge->build($found);
check('build reports 3 topics', ($report['topics'] ?? 0) === 3);
check('broken template skipped', in_array($found[$idBroke]['file'], $report['skipped'], true));
check('broken template logged', (bool) array_filter($logLines, static fn ($l) => str_contains($l, 'BrokenController') && str_contains($l, 'does not parse')));
check('template needing its context → static text, logged', in_array($found[$idForm]['file'], $report['static'], true)
    && (bool) array_filter($logLines, static fn ($l) => str_contains($l, 'OtherController') && str_contains($l, 'static text')));

$index = json_decode((string) file_get_contents($outDir . '/topics.json'), true);
$byId  = array_column($index['themen'] ?? [], null, 'id');
check('index lists the three topics by their ids', array_keys($byId) === [$idEdit, $idList, $idForm]);
check('titel = the first heading of the general part', ($byId[$idList]['titel'] ?? '') === 'Dinge' && ($byId[$idEdit]['titel'] ?? '') === 'Ding');
check('titel without a general heading → from the path', ($byId[$idForm]['titel'] ?? '') === 'Other – form');
check('beschreibung = opening sentence + section headings + screen',
    str_contains($byId[$idList]['beschreibung'], 'Alle Dinge in einer Liste.') && str_contains($byId[$idList]['beschreibung'], 'Abschnitte: Name')
    && str_contains($byId[$idList]['beschreibung'], 'Bildschirm Backend/ThingController/list'));
check('quellen = the generated text file', ($byId[$idList]['quellen'] ?? null) === [$idList . '.txt']);
$editText = (string) file_get_contents($outDir . '/' . $idEdit . '.txt');
check('text of the override, not of the package', str_contains($editText, 'Projekt-Text aus dem Override') && !str_contains($editText, 'Paket-Text'));
check('text keeps headings and list items readable', str_contains($editText, '## Ding') && str_contains($editText, '- Erster Punkt'));
$formText = (string) file_get_contents($outDir . '/' . $idForm . '.txt');
check('static text: the HTML outside PHP, no code', str_contains($formText, 'In Franken.') && !str_contains($formText, '$form') && !str_contains($formText, '<?'));
check('no text file for the skipped template', !is_file($outDir . '/' . $idBroke . '.txt'));
$topics = new Topics($outDir . '/topics.json', $outDir);
check('the index is a valid KiBot topic set', $topics->ids() === [$idEdit, $idList, $idForm]);
check('KiBot reads the generated source', str_contains($topics->read([$idList], 5000)['text'], 'Der Name des Dings.'));

echo "lazy rebuild\n";
check('fresh right after the build', !$knowledge->isStale());
check('ensureFresh() does nothing when fresh', $knowledge->ensureFresh() === false);
put($ovrA . '/Backend/ThingController/edit.help.tpl.php', "<h3>Ding</h3><p>Geänderter Projekt-Text.</p>", time() + 120);
check('a template newer than the index → stale', $knowledge->isStale());
check('ensureFresh() rebuilds', $knowledge->ensureFresh() === true);
check('the changed text is in the knowledge', str_contains((string) file_get_contents($outDir . '/' . $idEdit . '.txt'), 'Geänderter Projekt-Text'));
touch($outDir . '/topics.json', time() + 300);
clearstatcache();
check('fresh again', !$knowledge->isStale());
put($pkgB . '/Backend/NewController/list.help.tpl.php', '<h3>Neu</h3><p>Neue Hilfe.</p>', $past);
check('a new template (older mtime) → stale through the signature', $knowledge->isStale());
unlink($pkgB . '/Backend/NewController/list.help.tpl.php');
check('removed again → fresh', !$knowledge->isStale());

// ── backend config ──────────────────────────────────────────────────────────
echo "backend config\n";
check('no config/client/kibot.inc.php → assistant off', BackendAssistant::fromConfig() === null && !BackendAssistant::enabled());
put($work . '/config/client/kibot.inc.php', "<?php\nreturn 'nope';\n");
check('broken config file → off, enabled() does not throw', BackendAssistant::enabled() === false);

$keyDir = $work . '-keys';
mkdir($keyDir, 0777, true);
$keyPath = str_replace('\\', '/', $keyDir) . '/anthropic.key';
put($work . '/config/client/kibot.inc.php', "<?php\nreturn " . var_export([
    'paths'       => ['keyFile' => $keyPath, 'sourceRoot' => 'shared/kibot', 'logDir' => 'logs/kibot'],
    'widget'      => ['botName' => 'Website-Bot', 'welcomeMsg' => 'Grüezi Website'],
    'prompt'      => ['intro' => 'Du bist der Website-Bot der Muster GmbH.'],
    'contactCtas' => [['label' => 'Kontakt', 'url' => '/kontakt']],
    'limits'      => ['maxRequests' => 7],
    'backend'     => ['widget' => ['heroTitle' => 'Projekt-Titel']],
], true) . ";\n");
check('configured but no key file → off (classic help)', BackendAssistant::fromConfig() instanceof KibotEndpoint && !BackendAssistant::enabled());
file_put_contents($keyPath, 'sk-test-FAKE-' . bin2hex(random_bytes(8)) . "\n"); // not a real key
check('configured with a readable key → on', BackendAssistant::enabled());

$endpoint = BackendAssistant::fromConfig();
$config   = $endpoint->config();
check('key file shared with the website bot', $endpoint->path('keyFile') === $keyPath);
check('limits shared', $config->get('limits.maxRequests') === 7);
check('the website texts are NOT taken', $config->get('widget.botName') === 'KI-Assistent' && $config->get('widget.welcomeMsg') !== 'Grüezi Website');
check('the website prompt and CTAs are NOT taken', !str_contains((string) $config->get('prompt.intro'), 'Muster GmbH') && $config->get('contactCtas') === []);
check('the website sources are NOT taken: knowledge under data/kibot/help', $endpoint->path('sourceRoot') === $base . '/data/kibot/help'
    && $endpoint->path('topicsFile') === $base . '/data/kibot/help/topics.json');
check('the backend section wins', $config->get('widget.heroTitle') === 'Projekt-Titel');
check('own conversation log', $config->get('log.conversationFile') === 'kibot-backend-chat');
check('system prompt: Swiss German, no ß, no invented menu paths, no data',
    (bool) array_filter($config->get('prompt.rules'), static fn ($r) => str_contains($r, 'kein ß'))
    && (bool) array_filter($config->get('prompt.rules'), static fn ($r) => str_contains($r, 'Menüpfade'))
    && (bool) array_filter($config->get('prompt.rules'), static fn ($r) => str_contains($r, 'keinen Zugriff auf Daten')));
check('screen label drops the «Hilfe:» lead', BackendAssistant::screenLabel('Hilfe: Aufträge') === 'Aufträge' && BackendAssistant::screenLabel('Einzelbuchung') === 'Einzelbuchung');

echo "config action\n";
$_SESSION       = [];
$sessionManager = new SessionManager();
$csrf           = new CsrfService($sessionManager);
$session        = new KibotSession($sessionManager, $csrf);
$token          = $csrf->getToken();
$widget         = $endpoint->widget($token);
check('config carries the z77 token and the panel texts', ($widget['token'] ?? '') === $token
    && $widget['botName'] === 'KI-Assistent' && $widget['fallbackLabel'] === 'Hilfetext anzeigen' && str_contains($widget['contextWelcome'], '%s'));
check('config never shows paths, prompt or the key', !isset($widget['paths'], $widget['prompt']) && !str_contains(json_encode($widget), 'anthropic.key'));

echo "access\n";
$cfg  = require __DIR__ . '/../packages/module-backend/src/App/Config/backendConfig.inc.php';
$role = static fn (string $action): string => AuthService::requiredRole($cfg['moduleRole'], $cfg['controllers'], 'system',
    Naming::toCamelCase('assistant') . 'Controller', Naming::toActionMethod($action));
check('config and ask need EDITOR, the lowest backend role', $role('config') === AuthRole::EDITOR && $role('ask') === AuthRole::EDITOR);
check('a logged-in editor may, an admin may', AuthRole::rolesSatisfy([AuthRole::EDITOR], $role('ask')) && AuthRole::rolesSatisfy([AuthRole::ADMIN], $role('ask')));
check('a guest (logged out) may not', !AuthRole::rolesSatisfy([AuthRole::GUEST], $role('config')) && !AuthRole::rolesSatisfy([AuthRole::GUEST], $role('ask')));
check('no list action: the bare controller URL stays a 404', !isset($cfg['controllers']['system']['AssistantController']['defaultAction']));

echo "ask\n";
// The knowledge for the endpoint: the generator writes into the endpoint's sourceRoot.
(new HelpKnowledge($roots, $endpoint->path('sourceRoot')))->build();
$t        = new FakeTransport();
$endpoint = BackendAssistant::fromConfig(null, $t);
$question = [['role' => 'user', 'content' => 'Was zeigt der Reiter Aktiv?']];

$frames = ask($endpoint, ['messages' => $question, 'token' => 'wrong', 'website' => '', 'context' => $idList], $session);
check('bad CSRF → sessionExpired, nothing sent', ($frames[0]['error'] ?? '') === $endpoint->config()->message('sessionExpired') && $t->requests === []);

$t->queue = [routingTo([$idList]), new HttpResult(200, sseAnswer('Der Reiter «Aktiv» zeigt die aktiven Dinge.'))];
$frames   = ask($endpoint, ['messages' => $question, 'token' => $token, 'website' => '', 'context' => $idList], $session);
$text     = implode('', array_map(static fn ($f) => is_array($f) ? ($f['text'] ?? '') : '', $frames));
check('answer streamed', $text === 'Der Reiter «Aktiv» zeigt die aktiven Dinge.' && end($frames) === '[DONE]');
$system = (string) ($t->requests[0]['system'] ?? '');
check('routing prompt lists the generated help topics', str_contains($system, $idList . ' | Dinge') && str_contains($system, $idForm));
check('the screen\'s help topic reaches the routing prompt as context', str_contains($system, 'Bildschirm mit dem Hilfetext ' . $idList . ' (Dinge)'));
check('… with room for 2 more topics', str_contains($system, 'höchstens 2 weitere'));
check('the help text reached the answer call', str_contains(json_encode($t->requests[1], JSON_UNESCAPED_UNICODE), 'Der Name des Dings.'));
check('only question, history and help texts are sent — no session data',
    !str_contains(json_encode($t->requests), $token) && array_keys($t->requests[0]) === ['model', 'max_tokens', 'system', 'messages', 'tools', 'tool_choice']);
check('topics stored under the backend\'s own session key', $sessionManager->get(BackendAssistant::TOPICS_KEY) === [$idList]
    && $sessionManager->get(KibotEndpoint::TOPICS_KEY) === null);

$t->queue = [routingTo([$idEdit]), new HttpResult(200, sseAnswer('Ok.'))];
ask($endpoint, ['messages' => $question, 'token' => $token, 'website' => '', 'context' => 'no-such-topic'], $session);
check('an unknown context is ignored', !str_contains((string) ($t->requests[2]['system'] ?? ''), 'Bildschirm mit dem Hilfetext'));
$t->queue = [routingTo([$idEdit]), new HttpResult(200, sseAnswer('Ok.'))];
ask($endpoint, ['messages' => $question, 'token' => $token, 'website' => '', 'context' => ['x']], $session);
check('a non-string context is ignored', !str_contains((string) ($t->requests[4]['system'] ?? ''), 'Bildschirm mit dem Hilfetext'));

// ── controller, shell, widget ───────────────────────────────────────────────
echo "controller + shell\n";
$src = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Controllers/System/AssistantController.php');
check('config: Fetch GET, json()', str_contains($src, "#[Fetch, HttpMethod('GET')]") && str_contains($src, 'return $this->json('));
check('ask: Fetch POST, refresh the index, stream, void()', str_contains($src, "#[Fetch, HttpMethod('POST')]")
    && strpos($src, 'BackendAssistant::refresh(') < strpos($src, '->stream(') && str_contains($src, 'return $this->void();'));
check('unconfigured → NotFoundException', str_contains($src, 'throw new NotFoundException('));
check('no superglobals, no direct response', preg_match('/\$_(SERVER|POST|GET|SESSION)|new \w*Response\(/', $src) === 0);

$baseCtl = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Controllers/BackendAbstractController.php');
check('shell: assistant only when enabled, page mode only', str_contains($baseCtl, 'BackendAssistant::enabled()') && str_contains($baseCtl, 'RequestMode::Page'));

$trigger  = __DIR__ . '/../packages/kernel/shared/res/view/templates/partials/helpTrigger.tpl.php';
$renderer = new TemplateRenderer('Z77\\Shared');
$classic  = $renderer->render($trigger, ['assistant' => null]);
$bot      = $renderer->render($trigger, ['assistant' => ['topic' => $idList, 'label' => 'Dinge <b>']]);
check('without the assistant: the classic «Hilfe» trigger', str_contains($classic, '>Hilfe<') && !str_contains($classic, 'data-kibot-open'));
check('with the assistant: «KI-Assistent», opener with context and fallback', str_contains($bot, '>KI-Assistent<')
    && str_contains($bot, 'data-kibot-open') && str_contains($bot, 'data-kibot-fallback') && str_contains($bot, 'data-kibot-context="' . $idList . '"'));
check('… still a help trigger (fallback while bot.js is not ready, F1)', str_contains($bot, 'data-help-open') && str_contains($bot, 'data-help-trigger'));
check('… the label is escaped', str_contains($bot, 'Dinge &lt;b&gt;'));

$skeleton = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/html-shell-skeleton.tpl.php');
check('skeleton loads bot.js in panel mode from the own origin', str_contains($skeleton, 'src="/assets/kibot/js/bot.js" data-mode="panel"'));

$js = (string) file_get_contents(__DIR__ . '/../packages/kibot/res/assets/js/bot.js');
check('bot.js: no innerHTML / eval', preg_match('/\.innerHTML\s*=|\beval\(|insertAdjacentHTML/', $js) === 0);
check('bot.js: panel mode without own button', str_contains($js, "getAttribute('data-mode')") && str_contains($js, "if (MODE === 'float')"));
check('bot.js: context sent with the question', str_contains($js, 'context: state.context'));
check('bot.js: opener click taken in the capture phase, fallback hands it back', str_contains($js, '}, true);') && str_contains($js, 'state.bypass = true'));
check('bot.js: Esc closes', str_contains($js, "e.key === 'Escape' && state.open"));

$core = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.js');
check('core.js: a window\'s help button becomes the assistant opener when bot.js is ready',
    str_contains($core, "hasAttribute('data-kibot-ready')") && str_contains($core, "setAttribute('data-kibot-context', tpl.getAttribute('data-help-topic')"));
$help = (string) file_get_contents(__DIR__ . '/../packages/kernel/core/src/Services/HelpService.php');
check('help block carries data-help-topic', str_contains($help, 'data-help-topic="'));

rmTree($work);
rmTree($keyDir);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
