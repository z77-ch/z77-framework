<?php

declare(strict_types=1);

/**
 * KiBot in the console — steps a to g without a browser.
 *
 *   php bin/ask.php [options] "Frage"     one question
 *   php bin/ask.php [options]             conversation: one question per line, empty line ends
 *
 * Options (each also as environment variable):
 *   --key=PATH      key file              KIBOT_KEY_FILE
 *   --root=PATH     source root           KIBOT_SOURCE_ROOT
 *   --topics=PATH   topics.json           KIBOT_TOPICS_FILE   (default <root>/topics.json)
 *   --config=PATH   config overrides      KIBOT_CONFIG        (PHP file returning an array)
 *
 * The answer streams to STDOUT; chosen topics and errors go to STDERR.
 * Example: php bin/ask.php --key=/secure/anthropic.key --root=example --topics=topics.example.json "Wann habt ihr offen?"
 */

use Z77\KiBot\Bot;
use Z77\KiBot\Config;
use Z77\KiBot\Guard;
use Z77\KiBot\GuardException;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$baseDir = dirname(__DIR__);
require is_file($baseDir . '/vendor/autoload.php') ? $baseDir . '/vendor/autoload.php' : $baseDir . '/autoload.php';

$options = getopt('', ['key:', 'root:', 'topics:', 'config:'], $rest);
$args    = array_slice($argv, $rest);
$opt     = static fn (string $name, string $env): string => (string) ($options[$name] ?? (getenv($env) ?: ''));

$configFile = $opt('config', 'KIBOT_CONFIG');
$overrides  = $configFile !== '' ? (array) require $configFile : [];
$cwd        = (string) getcwd();
// Command line paths are relative to the current directory, like any CLI argument.
foreach (['keyFile' => ['key', 'KIBOT_KEY_FILE'], 'sourceRoot' => ['root', 'KIBOT_SOURCE_ROOT'], 'topicsFile' => ['topics', 'KIBOT_TOPICS_FILE']] as $key => [$name, $env]) {
    $value = $opt($name, $env);
    if ($value !== '') {
        $overrides['paths'][$key] = Config::isAbsolute($value) ? $value : $cwd . '/' . $value;
    }
}
$config = new Config($overrides);

try {
    $bot = Bot::fromConfig($config, $configFile !== '' ? dirname((string) realpath($configFile)) : $cwd);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}

$guard    = new Guard($config, new class implements \Z77\KiBot\SessionStore {
    public function get(string $key): mixed { return null; }
    public function set(string $key, mixed $value): void {}
});
$history  = [];
$previous = [];
$question = implode(' ', $args);
$single   = $question !== '';

do {
    if (!$single) {
        fwrite(STDERR, "\n> ");
        $question = trim((string) fgets(STDIN));
        if ($question === '') {
            break;
        }
    }
    $history[] = ['role' => 'user', 'content' => $question];
    try {
        $messages = $guard->messages($history); // same length limits as the endpoint
    } catch (GuardException $e) {
        fwrite(STDERR, $config->message($e->key) . "\n");
        exit(3);
    }

    $result = $bot->ask($messages, $previous, static function (string $text): void {
        fwrite(STDOUT, $text);
    });
    fwrite(STDOUT, "\n");
    fwrite(STDERR, '[topics: ' . ($result->topics === [] ? '-' : implode(', ', $result->topics))
        . ' | stop: ' . ($result->stopReason ?? '-') . "]\n");

    if (!$result->ok()) {
        fwrite(STDERR, '[error ' . $result->errorKey . '] ' . $result->error . " (details: API error log in paths.logDir)\n");
        array_pop($history);
        if ($single) {
            exit(4);
        }
        continue;
    }
    $history[] = ['role' => 'assistant', 'content' => $result->answer];
    $previous  = $result->topics;
} while (!$single);
