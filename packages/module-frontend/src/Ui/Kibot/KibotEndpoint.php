<?php
namespace Z77\Module\Frontend\Ui\Kibot;

use Z77\KiBot\Bot,
    Z77\KiBot\Config,
    Z77\KiBot\Guard,
    Z77\KiBot\GuardException,
    Z77\KiBot\Log,
    Z77\KiBot\SessionStore,
    Z77\KiBot\Transport,
    Z77\Shared\Libraries\ConfigLocator
;

/**
 * The z77 side of KiBot (z77/kibot, docs/topics/kibot.md): reads the project's
 * one KiBot config file and runs the same sequence as the package's standalone
 * endpoint (packages/kibot/public/bot.php) on z77's request, session and CSRF
 * token. The controller ({@see \Z77\Module\Frontend\Ui\Controllers\Main\KibotController})
 * only maps HTTP onto it.
 *
 * Configuration: `config/client/kibot.inc.php` returns KiBot's Config overrides
 * unchanged (same shape as packages/kibot/kibot.config.example.php). Relative
 * paths resolve against the project root (ABS_BASE_PATH): `paths.sourceRoot`
 * defaults to `shared/kibot`, `paths.logDir` to `logs/kibot`. No file = the bot
 * is off and both routes answer 404. `paths.keyFile` has no default; without it
 * the endpoint answers the `noApiKey` text as an SSE error and logs the cause.
 */
final class KibotEndpoint
{
    public const CONFIG_FILE = 'kibot.inc.php';

    /** Session key of the topics of the last answer (follow-up questions). */
    public const TOPICS_KEY = 'kibotTopics';

    public function __construct(
        private Config $config,
        private string $baseDir,
        private ?Transport $transport = null,
    ) {}

    /**
     * The installation's endpoint, or null when the bot is off: z77/kibot not
     * installed, or no `config/client/kibot.inc.php`.
     *
     * @param string|null $baseDir project root; default ABS_BASE_PATH
     * @throws \RuntimeException when the config file does not return an array
     */
    public static function fromConfig(?string $baseDir = null): ?self
    {
        if (!class_exists(Config::class)) {
            return null;
        }
        $base = rtrim(str_replace('\\', '/', $baseDir ?? ABS_BASE_PATH), '/');
        $file = ConfigLocator::path(self::CONFIG_FILE, $base);
        if ($file === null) {
            return null;
        }
        $overrides = require $file;
        if (!is_array($overrides)) {
            throw new \RuntimeException("❌ {$file} must return an array of KiBot settings (see docs/topics/kibot.md).");
        }
        return new self(new Config($overrides), $base);
    }

    public function config(): Config
    {
        return $this->config;
    }

    /** A path setting resolved against the project root ('' = not configured). */
    public function path(string $key): string
    {
        return $this->config->path($key, $this->baseDir);
    }

    /** The widget configuration for the browser: texts, CTAs, max length — plus the CSRF token. */
    public function widget(string $csrfToken): array
    {
        $widget          = $this->config->widget();
        $widget['token'] = $csrfToken;
        return $widget;
    }

    /**
     * One question: Guard → Bot → answer as SSE frames handed to $write
     * (`data: {"text": …}` … `data: {"error": …}` … `data: [DONE]`). Every
     * failure ends as an error frame plus [DONE]; nothing is thrown at the
     * caller, because by then the stream is open.
     *
     * @param array                  $body  decoded POST body {messages, token, website}
     * @param callable(string): void $write receives each complete SSE frame
     */
    public function ask(array $body, string $ip, SessionStore $session, callable $write): void
    {
        $event = static function (array|string $data) use ($write): void {
            $write('data: ' . (is_string($data)
                ? $data
                : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n\n");
        };
        $fail = function (string $key) use ($event): void {
            $event(['error' => $this->config->message($key)]);
            $event('[DONE]');
        };

        // Request::getJsonBody() has no size cap; the re-encoded body stands in
        // for the raw length the standalone endpoint checks.
        $maxBytes = (int) $this->config->get('limits.maxBodyBytes');
        if ($body === [] || strlen((string) json_encode($body)) > $maxBytes) {
            $fail('invalidRequest');
            return;
        }

        try {
            $messages = (new Guard($this->config, $session, $this->path('rateDir')))->check($body, $ip);
        } catch (GuardException $e) {
            $fail($e->key);
            return;
        }

        try {
            $bot = Bot::fromConfig($this->config, $this->baseDir, $this->transport);
        } catch (\RuntimeException $e) { // topics file or source root missing
            (new Log($this->config, $this->path('logDir')))->error(0, $e->getMessage());
            $fail('genericError');
            return;
        }

        $previous = $session->get(self::TOPICS_KEY);
        $result   = $bot->ask($messages, is_array($previous) ? $previous : [], static function (string $text) use ($event): void {
            $event(['text' => $text]);
        }, $ip);

        $session->set(self::TOPICS_KEY, $result->topics);

        if (!$result->ok()) {
            $event(['error' => $result->error]);
        }
        $event('[DONE]');
    }
}
