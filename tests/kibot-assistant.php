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
 *     trigger falls back to «Hilfe»), the widget builds no HTML from data;
 *   - «Hilfe ergänzen» (owner 2026-11-15): HelpAddition stores plain text under
 *     data/help/<topic id>.txt (id = slug, no path; ≤ 20'000 chars; normalized; empty
 *     removes), the help block shows it escaped below the help, the edit address only via
 *     editAt(); HelpKnowledge appends it to the topic and its catalog line, a save makes the
 *     index stale; HelpController refuses a non-admin and an unknown / invalid help id, saves
 *     and answers close-window + help-addition; core.js / core.min.js carry the pencil and
 *     the command.
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
use Z77\Core\Services\HelpAddition;
use Z77\Core\Services\HelpService;
use Z77\Core\Services\TemplateRenderer;
use Z77\Core\Session\SessionManager;
use Z77\KiBot\HttpResult;
use Z77\KiBot\Topics;
use Z77\KiBot\Transport;
use Z77\Module\Backend\Ui\Assistant\BackendAssistant;
use Z77\Module\Backend\Ui\Controllers\System\HelpController;
use Z77\Shared\Auth\AuthUser;
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

// A base-knowledge help (a workflow across screens) attached to no screen: the other
// modules rely on `res/view/templates/Backend/Guide/<name>.help.tpl.php` being indexed.
echo "guide help (no screen)\n";
$pkgG   = $work . '/vendor/z77/module-gamma/res/view/templates';
$outG   = $base . '/data/kibot/help-guide';
put($pkgG . '/Backend/Guide/invoiceWorkflow.help.tpl.php',
    "<?php /** guide */ ?>\n<h3>Ablauf: Auftrag bis Zahlung</h3>\n<p>Wie aus einem Auftrag eine Rechnung wird und wie du eine Zahlung erfasst. Beantwortet: Rechnung stellen, Zahlung erfassen, Mahnung.</p>\n<h4>Rechnung stellen</h4>\n<p>…</p>\n<h4>Zahlung erfassen</h4>\n<p>…</p>\n", $past);
$guide  = new HelpKnowledge(['Z77\\Module\\Gamma\\' => [$pkgG]], $outG);
$idG    = 'module-gamma-backend-guide-invoice-workflow';
check('a Backend/Guide help is discovered', array_keys($guide->discover()) === [$idG]);
check('and built into the topic set', ($guide->build()['topics'] ?? 0) === 1);
$lineG  = array_column(json_decode((string) file_get_contents($outG . '/topics.json'), true)['themen'] ?? [], null, 'id')[$idG] ?? [];
check('its catalog line: titel = first heading, beschreibung = first <p> + further headings + screen',
    ($lineG['titel'] ?? '') === 'Ablauf: Auftrag bis Zahlung'
    && str_starts_with((string) ($lineG['beschreibung'] ?? ''), 'Wie aus einem Auftrag eine Rechnung wird')
    && str_contains((string) $lineG['beschreibung'], 'Abschnitte: Rechnung stellen, Zahlung erfassen.')
    && str_contains((string) $lineG['beschreibung'], '(Bildschirm Backend/Guide/invoiceWorkflow)'));

// Reported 2026-11-14 (KIBOT-CATALOG-LINE): describe() dropped the first heading of the file
// blindly — without a general heading that was a field heading — and the 600-char cut ate
// the screen tag of a help with many headings.
echo "catalog line\n";
check('the title is not repeated in Abschnitte', !str_contains($byId[$idList]['beschreibung'], 'Dinge,') && !str_contains($byId[$idList]['beschreibung'], ': Dinge'));
check('title from the path → the first (field) heading stays in Abschnitte', str_contains($byId[$idForm]['beschreibung'] ?? '', 'Abschnitte: Betrag.'));
$pkgL  = $work . '/vendor/z77/module-delta/res/view/templates';
$outL  = $base . '/data/kibot/help-long';
$many  = '';
for ($i = 1; $i <= 60; $i++) {
    $many .= "<h4>Abschnitt Nummer {$i} mit einem langen Namen</h4><p>…</p>\n";
}
put($pkgL . '/Backend/LongController/list.help.tpl.php', "<h3>Lang</h3>\n<p>Eine Hilfe mit sehr vielen Abschnitten.</p>\n" . $many, $past);
$longK = new HelpKnowledge(['Z77\\Module\\Delta\\' => [$pkgL]], $outL);
$longK->build();
$lineL = (string) (json_decode((string) file_get_contents($outL . '/topics.json'), true)['themen'][0]['beschreibung'] ?? '');
check('a long line keeps its screen tag at the end', str_ends_with($lineL, '… (Bildschirm Backend/LongController/list)'));
check('and stays within the 600-char line limit', mb_strlen($lineL) <= 600 && mb_strlen($lineL) > 550);
check('the first section heading is kept', str_contains($lineL, 'Abschnitte: Abschnitt Nummer 1 mit'));

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

// The classic trigger's label is `t('common.help')` (ADR-056, the reader's language — the member
// shell renders it too): a real Translator over kernel/shared's dictionary, as other harnesses do.
require_once __DIR__ . '/support/TestTranslator.php';
\Z77\Tests\Support\TestTranslator::install(\Z77\Tests\Support\TestTranslator::packages('kernel/shared'));

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
check('skeleton loads bot.js in panel mode from the own origin — versioned (`?v=` mtime, owner 2026-11-15), bot.css too',
    str_contains($skeleton, "src=\"<?= e(\$kibotAssistant['script'] ?? '/assets/kibot/js/bot.js') ?>\" data-mode=\"panel\"")
    && str_contains($skeleton, "href=\"<?= e(\$kibotAssistant['style'] ?? '/assets/kibot/css/bot.css') ?>\""));
$verDir = sys_get_temp_dir() . '/z77-kibot-ver-' . getmypid();
$verUrl = '/assets/kibot/js/bot.js';
@mkdir($verDir . '/assets/kibot/js', 0777, true);
file_put_contents($verDir . $verUrl, '//');
touch($verDir . $verUrl, 1763200000);
clearstatcache();
check('assetUrl(): own-origin path + `?v=<mtime>` of the published file; no file → the bare URL',
    BackendAssistant::assetUrl(BackendAssistant::SCRIPT_URL, $verDir) === $verUrl . '?v=1763200000'
    && BackendAssistant::assetUrl('/assets/kibot/js/does-not-exist.js', $verDir) === '/assets/kibot/js/does-not-exist.js'
    && BackendAssistant::SCRIPT_URL === $verUrl && BackendAssistant::STYLE_URL === '/assets/kibot/css/bot.css');
@unlink($verDir . $verUrl);
@rmdir($verDir . '/assets/kibot/js'); @rmdir($verDir . '/assets/kibot'); @rmdir($verDir . '/assets'); @rmdir($verDir);
$ctlSrc = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Controllers/BackendAbstractController.php');
check('… the shell hands the versioned URLs', str_contains($ctlSrc, "'script'   => BackendAssistant::assetUrl(BackendAssistant::SCRIPT_URL)") && str_contains($ctlSrc, "'style'    => BackendAssistant::assetUrl(BackendAssistant::STYLE_URL)"));

$js = (string) file_get_contents(__DIR__ . '/../packages/kibot/res/assets/js/bot.js');
check('bot.js: no innerHTML / eval', preg_match('/\.innerHTML\s*=|\beval\(|insertAdjacentHTML/', $js) === 0);
check('bot.js: panel mode without own button', str_contains($js, "getAttribute('data-mode')") && str_contains($js, "if (MODE === 'float')"));
check('bot.js: context sent with the question', str_contains($js, 'context: state.context'));
check('bot.js: opener click taken in the capture phase, fallback hands it back', str_contains($js, '}, true);') && str_contains($js, 'state.bypass = true'));
check('bot.js: Esc closes', str_contains($js, "e.key === 'Escape' && state.open"));

$core = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.js');
check('core.js: a window\'s help button becomes the assistant opener when bot.js is ready',
    str_contains($core, "hasAttribute('data-kibot-ready')") && str_contains($core, "setAttribute('data-kibot-context', tpl.getAttribute('data-help-topic')"));
check('the assistant panel stays usable while a window is open (owner 2026-11-15): bot.js marks its widget data-z77-stay-live, core.js leaves such a node out of the inert sweep',
    str_contains($js, "'data-z77-stay-live': ''") && str_contains($core, "c.hasAttribute('data-z77-stay-live')"));
$help = (string) file_get_contents(__DIR__ . '/../packages/kernel/core/src/Services/HelpService.php');
check('help block carries data-help-topic', str_contains($help, 'data-help-topic="'));

// ── «Hilfe ergänzen» (owner 2026-11-15) ────────────────────────────────────
// An admin adds the installation's own text to a help: plain text under data/help/<id>.txt,
// shown below the help (escaped), part of the assistant's knowledge, the index goes stale.
echo "help addition: store\n";
$addDir    = $base . '/data/help';
$additions = new HelpAddition($addDir);
check('a topic id is a valid addition id', HelpAddition::isValidId($idList) && HelpAddition::isValidId($long));
check('no path, no dot, no capital, no slash, not empty, not longer than 64',
    !HelpAddition::isValidId('../../config/client/kibot.inc')
    && !HelpAddition::isValidId('a/b') && !HelpAddition::isValidId('a.txt') && !HelpAddition::isValidId('Abc')
    && !HelpAddition::isValidId('') && !HelpAddition::isValidId('-x') && !HelpAddition::isValidId(str_repeat('a', 65))
    && !HelpAddition::isValidId("a\0b"));
$threw = false;
try { $additions->file('../evil'); } catch (\InvalidArgumentException) { $threw = true; }
check('file() of an invalid id throws — no file name is ever built from it', $threw);
$threw = false;
try { $additions->save('../evil', 'x'); } catch (\InvalidArgumentException) { $threw = true; }
check('save() of an invalid id throws and writes nothing', $threw && !is_dir($addDir));
check('no addition → load() is empty', $additions->load($idList) === '' && $additions->load('../evil') === '');

$additions->save($idList, "  Hausregel: Rechnungen gehen immer an die Buchhaltung.  \r\nZweite Zeile.\r\n\r\n\r\n\r\nZweiter Absatz\x07 mit Glocke.\n\n");
$stored = (string) file_get_contents($addDir . '/' . $idList . '.txt');
check('saved as plain text under data/help/<topic id>.txt', is_file($addDir . '/' . $idList . '.txt'));
check('normalized: \\n line ends, no trailing blanks, one blank line between paragraphs, no control characters',
    $stored === "Hausregel: Rechnungen gehen immer an die Buchhaltung.\nZweite Zeile.\n\nZweiter Absatz mit Glocke.\n");
check('load() returns the normalized text', $additions->load($idList) === "Hausregel: Rechnungen gehen immer an die Buchhaltung.\nZweite Zeile.\n\nZweiter Absatz mit Glocke.");
check('paragraphs: a blank line starts a new one, a line break stays inside',
    HelpAddition::paragraphs($additions->load($idList)) === ["Hausregel: Rechnungen gehen immer an die Buchhaltung.\nZweite Zeile.", 'Zweiter Absatz mit Glocke.']);
$threw = false;
try { $additions->save($idList, str_repeat('ä', HelpAddition::MAX_CHARS + 1)); } catch (\InvalidArgumentException) { $threw = true; }
check('more than ' . HelpAddition::MAX_CHARS . ' characters → refused, the stored text stays', $threw && str_contains($additions->load($idList), 'Hausregel'));
$additions->save($idEdit, str_repeat('ä', HelpAddition::MAX_CHARS));
check('exactly ' . HelpAddition::MAX_CHARS . ' characters (multibyte) are allowed', mb_strlen($additions->load($idEdit)) === HelpAddition::MAX_CHARS);
$threw = false;
try { $additions->save($idEdit, "kaputt \xC3\x28"); } catch (\InvalidArgumentException) { $threw = true; }
check('not UTF-8 → refused', $threw);
$additions->save($idEdit, "  \n\n ");
check('an empty (blank) save removes the addition', !is_file($addDir . '/' . $idEdit . '.txt') && $additions->load($idEdit) === '');
check('no temp file left behind', glob($addDir . '/*.tmp') === []);

echo "help addition: help block\n";
// A FileFinder over the harness packages: kernel/shared (the partial, the dictionary) and the
// alpha package (its help templates) — getFirstTplMatch as in production, override first.
final class AdditionFinder extends \Z77\Core\Libraries\FileFinder
{
    /** @param array<string, list<string>> $ns namespace => source roots */
    public function __construct(private array $ns) {}
    public function getAllNamespaces(): array
    {
        return array_map(static fn (array $roots): array => ['sourcePaths' => $roots], $this->ns);
    }
    public function getBasePaths(string $ns, string $resourceType): array
    {
        return $this->getAllNamespaces()[rtrim($ns, '\\') . '\\'][$resourceType] ?? [];
    }
    public function getTplDir(): string
    {
        return 'res/view/templates';
    }
    public function getFirstTplMatch(string $fileName, string $nameSpace, bool $throwError = true, bool $cachePersist = true): ?string
    {
        foreach ($this->getBasePaths($nameSpace, 'sourcePaths') as $root) {
            if (is_file($root . '/res/view/templates/' . $fileName)) {
                return $root . '/res/view/templates/' . $fileName;
            }
        }
        if ($throwError) {
            throw new \RuntimeException('template not found: ' . $fileName);
        }
        return null;
    }
}
$finder = new AdditionFinder([
    'Z77\\Shared\\'       => [str_replace('\\', '/', dirname(__DIR__)) . '/packages/kernel/shared'],
    'Z77\\Module\\Alpha\\' => [dirname($ovrA, 3), dirname($pkgA, 3)],
]);
// DI::set() never overwrites: a fresh container, this finder first, then the translator over it.
\Z77\Core\DI::getInstance(true)->set('FileFinder', $finder, true);
\Z77\Tests\Support\TestTranslator::install([]);

$additions->save($idList, "Hausregel <script>alert(1)</script> & «Ablage»\nZweite Zeile\n\nZweiter Absatz");
$service = new HelpService($additions);
$service->attach('Backend/ThingController/list.help', 'Z77\\Module\\Alpha', [], 'Hilfe: Dinge');
$block = $service->render();
check('the help block carries the addition section after the help text',
    str_contains($block, 'data-help-addition') && strpos($block, 'Alle Dinge in einer Liste.') < strpos($block, 'data-help-addition')
    && strpos($block, 'data-help-addition') < strpos($block, '</template>'));
check('… under the heading «Ergänzung» (t(common.helpAddition))', str_contains($block, '>Ergänzung</h4>'));
check('the user\'s text is escaped — no markup from the addition', !str_contains($block, '<script>') && str_contains($block, '&lt;script&gt;alert(1)&lt;/script&gt; &amp; «Ablage»'));
check('paragraphs become <p>, a line break inside one <br> (after escaping)', substr_count($block, '<p>') >= 3
    && str_contains($block, '&amp; «Ablage»<br>') && str_contains($block, '<p>Zweiter Absatz</p>'));
check('without editAt(): no edit address on the block', !str_contains($block, 'data-help-edit'));
$service->editAt('/backend/system/help/edit');
check('with editAt(): the edit window\'s URL with the topic', str_contains($service->render(), ' data-help-edit="/backend/system/help/edit?topic=' . $idList . '"'));
$plain = new HelpService($additions);
$plain->attach('Backend/ThingController/edit.help', 'Z77\\Module\\Alpha', [], 'Hilfe: Ding');
check('a help without addition: no section', !str_contains($plain->render(), 'data-help-addition'));
check('additionHtml() is the section of the block; none → \'\'',
    str_contains($block, trim($service->additionHtml($idList))) && $service->additionHtml($idEdit) === '');

echo "help addition: knowledge\n";
$known = HelpKnowledge::templates($finder);
check('the template discovery names the helps an addition may be written for', isset($known[$idList], $known[$idEdit]) && !isset($known['module-alpha-backend-nope-controller-list']));
check('staticTitle(): the help\'s title without rendering it', HelpKnowledge::staticTitle($known[$idList]) === 'Dinge');
$outAdd = $base . '/data/kibot/help-addition';
$withAdd = new HelpKnowledge($roots, $outAdd, null, $additions);
$withAdd->build();
$listText = (string) file_get_contents($outAdd . '/' . $idList . '.txt');
check('the topic text ends with the addition under «## Ergänzung»', str_contains($listText, "Der Name des Dings.") && str_contains($listText, "## Ergänzung\nHausregel <script>alert(1)</script> & «Ablage»")
    && strpos($listText, 'Der Name des Dings.') < strpos($listText, '## Ergänzung'));
$lineAdd = array_column(json_decode((string) file_get_contents($outAdd . '/topics.json'), true)['themen'] ?? [], null, 'id')[$idList] ?? [];
check('the catalog line names the addition (round 1 sees it), the screen stays at the end',
    str_contains((string) ($lineAdd['beschreibung'] ?? ''), 'Ergänzung: Hausregel') && str_ends_with((string) $lineAdd['beschreibung'], '(Bildschirm Backend/ThingController/list)'));
check('… within the 600 characters of a catalog line', mb_strlen((string) $lineAdd['beschreibung']) <= 600);
$editLine = array_column(json_decode((string) file_get_contents($outAdd . '/topics.json'), true)['themen'] ?? [], null, 'id')[$idEdit] ?? [];
check('a help without addition: catalog line unchanged', !str_contains((string) ($editLine['beschreibung'] ?? ''), 'Ergänzung'));
// The override template above carries a FUTURE mtime (lazy rebuild test): the index is moved
// past it after each build, so only the signature can make it stale here.
$freshen = static function () use ($outAdd): void { touch($outAdd . '/topics.json', time() + 600); clearstatcache(); };
$freshen();
check('fresh after the build', !$withAdd->isStale());
$additions->save($idList, "Hausregel geändert.");
check('a saved addition makes the index stale (content hash, not mtime)', $withAdd->isStale());
check('ensureFresh() rebuilds, the new text is in the knowledge', $withAdd->ensureFresh() === true
    && str_contains((string) file_get_contents($outAdd . '/' . $idList . '.txt'), 'Hausregel geändert.'));
$freshen();
check('fresh again', !$withAdd->isStale());
$additions->save($idEdit, 'Neu für Ding.');
check('a NEW addition on another help → stale', $withAdd->isStale());
$withAdd->ensureFresh();
$freshen();
$additions->save($idEdit, '');
check('a removed addition → stale', $withAdd->isStale());
$withAdd->ensureFresh();
check('… and gone from the knowledge', !str_contains((string) file_get_contents($outAdd . '/' . $idEdit . '.txt'), 'Neu für Ding.'));
$additions->save($idList, str_repeat('Ein langer Satz der Hausregel. ', 640));
$withAdd->build();
$budgetLine = array_column(json_decode((string) file_get_contents($outAdd . '/topics.json'), true)['themen'] ?? [], null, 'id')[$idList] ?? [];
$budgetRead = (new Topics($outAdd . '/topics.json', $outAdd))->read([$idList, $idEdit, $idForm], 40000);
check('a long addition: the catalog line keeps its cap, round 2 keeps its budget',
    mb_strlen((string) $budgetLine['beschreibung']) <= 600 && mb_strlen($budgetRead['text']) <= 40000);
check('the assistant\'s knowledge() reads the installation\'s additions (data/help)',
    str_contains((string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Assistant/BackendAssistant.php'), 'HelpAddition::forInstallation()'));

echo "help addition: access + action\n";
$roleH = static fn (string $action): string => AuthService::requiredRole($cfg['moduleRole'], $cfg['controllers'], 'system',
    Naming::toCamelCase('help') . 'Controller', Naming::toActionMethod($action));
check('the edit route needs ADMIN (module baseline, no lower deviation)', $roleH('edit') === AuthRole::ADMIN && !isset($cfg['controllers']['system']['HelpController']));
$user = static fn (array $roles, string $realm = 'backend', int $id = 7): AuthUser => new AuthUser(['id' => $id, 'user_name' => 'u', 'roles' => $roles, 'realm' => $realm]);
check('isEditor(): admin and superUser yes', HelpController::isEditor($user([AuthRole::ADMIN])) && HelpController::isEditor($user([AuthRole::SUPER_USER])));
check('isEditor(): editor, guest (not logged in), a member-realm account no',
    !HelpController::isEditor($user([AuthRole::EDITOR])) && !HelpController::isEditor(new AuthUser())
    && !HelpController::isEditor($user([AuthRole::ADMIN], 'member')));

// The action itself, in fetch mode, with doubles for request and auth.
$GLOBALS['hcUser'] = $user([AuthRole::EDITOR]);
$GLOBALS['hcGet']  = ['topic' => $idList];
$GLOBALS['hcPost'] = null;
\Z77\Core\DI::getInstance()
    ->set('Request', fn () => new class {
        public function getMode(): \Z77\Core\Http\RequestMode { return \Z77\Core\Http\RequestMode::Fetch; }
        public function getGetParameter(string $p): mixed { return $GLOBALS['hcGet'][$p] ?? null; }
        public function isPost(): bool { return $GLOBALS['hcPost'] !== null; }
        public function getPostParameters(): array { return $GLOBALS['hcPost'] ?? []; }
    }, true)
    ->set('AuthService', fn () => new class { public function getCurrentUser(): AuthUser { return $GLOBALS['hcUser']; } }, true);
$runEdit = static function () use ($additions, $sessionManager): array {
    $ctl = new HelpController('editAction');
    (function () use ($additions, $sessionManager): void {
        $this->messageService = new \Z77\Core\Services\MessageService($sessionManager);
        $this->help           = new HelpService($additions);
    })->call($ctl);
    $response = (fn () => $this->editAction())->call($ctl);
    $env = $response instanceof \Z77\Core\Http\Response\FetchResponse ? (fn () => $this->build())->call($response) : [];
    return [$response, $env];
};
$before = $additions->load($idList);
$GLOBALS['hcPost'] = ['addition' => 'vom Editor'];
[$r, $env] = $runEdit();
check('an editor is refused (error envelope), nothing written', ($env['status'] ?? '') === 'error' && $additions->load($idList) === $before
    && str_contains(json_encode($env['flashes'] ?? [], JSON_UNESCAPED_UNICODE), 'Nur ein Admin'));
$GLOBALS['hcUser'] = $user([AuthRole::ADMIN]);
foreach (['../../config/client/kibot.inc', 'module-alpha-backend-nope-controller-list', ''] as $bad) {
    $GLOBALS['hcGet'] = ['topic' => $bad];
    [$r, $env] = $runEdit();
    check('admin, topic «' . $bad . '» (no path / unknown help / none) → refused, no file', ($env['status'] ?? '') === 'error'
        && glob($addDir . '/*') === [$addDir . '/' . $idList . '.txt']);
}
$GLOBALS['hcGet']  = ['topic' => $idList];
$GLOBALS['hcPost'] = ['addition' => "Von der Chefin: <b>immer</b> zuerst prüfen.\n\nZweitens."];
[$r, $env] = $runEdit();
$commands = array_column($env['commands'] ?? [], null, 'action');
check('admin save → success, the addition is stored', ($env['status'] ?? '') === 'success' && $additions->load($idList) === "Von der Chefin: <b>immer</b> zuerst prüfen.\n\nZweitens.");
check('… the answer closes the window and hands core.js the texts for the topic — no markup (core.js builds the nodes)',
    isset($commands['close-window']) && ($commands['help-addition']['topic'] ?? '') === $idList
    && ($commands['help-addition']['title'] ?? '') === 'Ergänzung' && !isset($commands['help-addition']['html'])
    && ($commands['help-addition']['paragraphs'] ?? null) === ['Von der Chefin: <b>immer</b> zuerst prüfen.', 'Zweitens.']);
$GLOBALS['hcPost'] = ['addition' => ''];
[$r, $env] = $runEdit();
$commands = array_column($env['commands'] ?? [], null, 'action');
check('an empty save removes it; the command carries no paragraphs (core.js removes the section)', ($env['status'] ?? '') === 'success'
    && $additions->load($idList) === '' && ($commands['help-addition']['paragraphs'] ?? null) === []);

$editTpl = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/System/HelpController/edit.tpl.php');
check('edit window: a notice ABOVE the text field says the addition goes to the AI service — no customer, personal or access data (owner 2026-11-15)',
    str_contains($editTpl, 'data-help-addition-notice') && str_contains($editTpl, 'KI-Dienst') && str_contains($editTpl, 'Keine Kunden-, Personen- oder Zugangsdaten')
    && strpos($editTpl, 'data-help-addition-notice') < strpos($editTpl, '<textarea'));

$hc = (string) file_get_contents(__DIR__ . '/../packages/module-backend/src/Ui/Controllers/System/HelpController.php');
check('controller: the rights check comes first, the id is checked against the known helps, #[Csrf]',
    strpos($hc, 'self::isEditor(') < strpos($hc, "getGetParameter('topic')") && str_contains($hc, 'HelpKnowledge::templates(') && str_contains($hc, '#[Csrf]'));
check('controller: no superglobals, no direct response, no file write of its own (only HelpAddition)',
    preg_match('/\$_(SERVER|POST|GET|SESSION)|new \w*Response\(|file_put_contents|fopen\(/', $hc) === 0);
check('shell: the edit address only for an editor who can reach the route', str_contains($baseCtl, 'HelpController::isEditor($user)')
    && str_contains($baseCtl, "\$auth->canReach(\$user, 'backend', 'system', 'help', 'edit')") && str_contains($baseCtl, '$this->help->editAt(HelpController::EDIT_URL)'));
$tplHc = (string) file_get_contents(__DIR__ . '/../packages/module-backend/res/view/templates/System/HelpController/edit.tpl.php');
check('edit window: a window per help, a textarea with the limit, everything escaped', str_contains($tplHc, 'data-window="help-addition"')
    && str_contains($tplHc, 'name="addition"') && str_contains($tplHc, 'maxlength="<?= (int) $maxChars ?>"') && str_contains($tplHc, '<?= e($text) ?></textarea>'));
check('core.js: the pencil left of ⤢ only with a same-origin edit address, opened as a window',
    str_contains($core, "head.insertBefore(btn, head.querySelector('[data-help-full]'))") && str_contains($core, "if (!/^\\/(?!\\/)/.test(url))")
    && str_contains($core, "btn.setAttribute('data-window-open', url)"));
check('core.js: the help-addition command swaps the section in the templates of the topic and the open help',
    str_contains($core, "registerCommand('help-addition'") && str_contains($core, "getAttribute('data-help-topic') === topic") && str_contains($core, '_win._z77Topic === topic')
    && str_contains($core, 'p.appendChild(document.createTextNode(line))'));
preg_match('/_Z77\.core\.help = \(function \(\) \{(.*?)
\}\)\(\);/s', $core, $helpModule);
check('core.js: the help module still builds no markup from strings (no innerHTML)', isset($helpModule[1]) && !str_contains($helpModule[1], 'innerHTML'));
$coreMin = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/assets/js/core.min.js');
check('core.min.js is rebuilt with it', str_contains($coreMin, 'help-addition') && str_contains($coreMin, 'data-help-edit-open'));
$partial = (string) file_get_contents(__DIR__ . '/../packages/kernel/shared/res/view/templates/partials/helpAddition.tpl.php');
check('partial: every paragraph through e(), <br> only after escaping', str_contains($partial, 'nl2br(e($paragraph), false)'));

rmTree($work);
rmTree($keyDir);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
