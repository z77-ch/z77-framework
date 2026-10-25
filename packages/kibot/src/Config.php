<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * All settings of the bot in one place: texts, CTAs, models, budgets, limits, paths,
 * system prompt. Shape follows the poolmanufaktur ChatConfig.
 *
 * A project adapts the bot by passing overrides to the constructor (usually the array
 * returned by its own kibot.config.php). Overrides merge per key; a LIST (CTAs, prompt
 * rules) is replaced as a whole, never merged by index.
 *
 * Paths may be relative; they are resolved against the base directory the caller
 * passes to path() (the standalone endpoint uses the package root).
 */
final class Config
{
    private const DEFAULTS = [
        // ── Widget texts (sent to the browser) ──────────────────────────────
        'widget' => [
            'botName'          => 'Assistent',
            'heroTitle'        => 'Fragen? Ich helfe gerne.',
            'placeholder'      => 'Ihre Frage...',
            'welcomeMsg'       => 'Guten Tag, ich bin ein KI-Assistent. Wie kann ich Ihnen helfen?',
            'widgetLabel'      => 'Chat-Widget',
            'toggleOpenLabel'  => 'Chat öffnen',
            'toggleCloseLabel' => 'Chat schliessen',
            'toggleTitle'      => 'Chat öffnen / schliessen',
            'printLabel'       => 'Chat drucken',
            'expandLabel'      => 'Vergrössern',
            'collapseLabel'    => 'Verkleinern',
            'closeLabel'       => 'Chat schliessen',
            'inputLabel'       => 'Nachricht',
            'sendLabel'        => 'Senden',
            'connectionError'  => 'Verbindungsfehler. Bitte versuchen Sie es später erneut.',
            'printTitle'       => 'Chat-Protokoll',
            'printCompany'     => '',
            'printAddress'     => '',
            'printContact'     => '',
            'printUserLabel'   => 'Sie',
            'avatarSrc'        => '',
            'avatarAlt'        => 'Bot',
            // Console output of the widget. Off in production (chat-review 2.1).
            'debug'            => false,
        ],

        // ── Contact CTAs: the model writes {{CONTACT_CTA}}, the widget renders buttons
        'contactCtas' => [
            ['label' => 'Kontakt aufnehmen', 'url' => '/kontakt'],
        ],

        // ── API ─────────────────────────────────────────────────────────────
        // IMPORTANT: Anthropic retires old models. A retired model answers HTTP 404 and
        // the bot goes silent (poolmanufaktur, 15.06.2026: claude-sonnet-4-20250514).
        // The 404 is logged as "MODEL RETIRED?" in the API error log. Check the current
        // ids at https://docs.claude.com/en/docs/about-claude/models/overview
        'api' => [
            'endpoint'           => 'https://api.anthropic.com/v1/messages',
            'version'            => '2026-10-21',
            'answerModel'        => 'claude-sonnet-5-5', // active (as of 10.2026)
            'routingModel'       => 'claude-haiku-5-5',  // active (as of 10.2026)
            'answerMaxTokens'    => 4000,
            'routingMaxTokens'   => 1000,
            // Server-side fallback for the answer call (beta header + "fallbacks").
            'serverSideFallback' => true,
            'fallbackBeta'       => 'server-side-fallback-2026-07-01',
            'timeoutSeconds'     => 120,
            // How often the ANSWER model may still ask for more topics before it must answer.
            'maxToolRounds'      => 2,
        ],

        // ── Context budget per answer ───────────────────────────────────────
        'budget' => [
            'maxChars'  => 40000,
            'maxTopics' => 3,
        ],

        // ── Abuse limits ────────────────────────────────────────────────────
        'limits' => [
            'maxRequests'     => 30,     // per session and window
            'windowSeconds'   => 3600,
            'ipMaxRequests'   => 60,     // per IP and window (file based); 0 = off
            'ipWindowSeconds' => 3600,
            'maxMsgLength'    => 2000,   // characters per message
            'maxMsgCount'     => 20,     // messages kept in the history
            'maxBodyBytes'    => 200000, // raw POST body
        ],

        // ── User-facing error texts ─────────────────────────────────────────
        'messages' => [
            'overloaded'       => 'Bitte entschuldigen Sie, das System ist zur Zeit überlastet. Versuchen Sie es in wenigen Minuten erneut.',
            'genericError'     => 'Es ist ein Fehler aufgetreten. Bitte versuchen Sie es später erneut.',
            'sessionExpired'   => 'Sitzung abgelaufen. Bitte laden Sie die Seite neu.',
            'rateLimited'      => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.',
            'noMessages'       => 'Keine gültigen Nachrichten.',
            'noApiKey'         => 'Der Assistent ist zur Zeit nicht verfügbar.',
            'invalidRequest'   => 'Ungültige Anfrage.',
            'connectionFailed' => 'Verbindung zum KI-Service fehlgeschlagen.',
            'refusal'          => 'Dazu kann ich leider keine Auskunft geben. Bitte wenden Sie sich direkt an uns.',
        ],

        // ── Paths (relative = relative to the base dir given to path()) ─────
        // No default for the key file: it lives outside public/ and outside the repo,
        // e.g. HOSTING_ROOT/.z77/<project>/keys/anthropic.key (plan section 7.1).
        'paths' => [
            'keyFile'    => '',
            'sourceRoot' => 'shared/kibot',
            'topicsFile' => '',          // '' = <sourceRoot>/topics.json
            'logDir'     => 'logs/kibot',
            'rateDir'    => '',          // '' = <logDir>/rate
        ],

        // ── Logs ────────────────────────────────────────────────────────────
        'log' => [
            'conversationFile' => 'kibot-chat',          // -> kibot-chat_YYYY-MM.jsonl
            'errorFile'        => 'kibot-api-error.log',
            'ip'               => 'masked',              // full | masked | none
            'retentionMonths'  => 12,                    // conversation files older than this are deleted; 0 = keep
        ],

        // ── System prompt ───────────────────────────────────────────────────
        'prompt' => [
            'intro' => 'Du bist ein freundlicher Assistent für diese Website.',
            'rules' => [
                'Beantworte Fragen AUSSCHLIESSLICH anhand der Quellen, die dir das Werkzeug lies_themen liefert.',
                'Wenn eine Information nicht in den Quellen steht, sage klar: "Diese Information liegt mir leider nicht vor."',
                'Erfinde KEINE Preise, Masse, Daten oder andere Angaben.',
                'Antworte freundlich, sachlich und auf Deutsch, in schlichtem Text ohne Markdown-Formatierung.',
            ],
            'toolRules' => [
                'Rufe zu jeder Sachfrage zuerst das Werkzeug lies_themen mit 1 bis 3 passenden Themen-Ids aus der Themenliste auf.',
                'Wenn kein Thema passt, rufe das Werkzeug nicht auf und antworte direkt.',
                'Sobald die Quellen geliefert sind, beantworte die Frage damit; rufe das Werkzeug nur erneut auf, wenn die gelieferten Themen die Frage offensichtlich nicht abdecken.',
            ],
            'ctaRule'       => 'Wenn du eine Kontaktaufnahme empfiehlst, schreibe genau den Platzhalter {{CONTACT_CTA}} (ohne Link oder URL). Das System ersetzt ihn durch Buttons.',
            'previousTopics' => 'Themen der letzten Antwort (für Folgefragen wiederverwenden, wenn sie passen): %s',
            // %1$s = "id (titel)" of the context topic, %2$d = how many more topics may join it.
            'contextTopic'  => 'Die Frage kommt aus diesem Zusammenhang: Thema %1$s. Lies dieses Thema zu jeder Sachfrage mit, dazu höchstens %2$d weitere, ausser die Frage betrifft offensichtlich etwas anderes.',
            'noTopic'       => 'Für die aktuelle Frage wurde kein passendes Thema gefunden; es liegen keine Quellen vor. Sage das freundlich und erfinde nichts.',
        ],

        // ── Tool ────────────────────────────────────────────────────────────
        'tool' => [
            'name'        => 'lies_themen',
            'description' => 'Liest die Quellen zu 1 bis 3 Themen aus der Themenliste. Gib die Ids der Themen an, deren Beschreibung zur Frage passt.',
        ],
    ];

    private array $values;

    public function __construct(array $overrides = [])
    {
        $this->values = self::merge(self::DEFAULTS, $overrides);
    }

    /** Dot access: get('api.answerModel'). */
    public function get(string $key, mixed $default = null): mixed
    {
        $node = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($node) || !array_key_exists($part, $node)) {
                return $default;
            }
            $node = $node[$part];
        }
        return $node;
    }

    public function message(string $key): string
    {
        return (string) ($this->values['messages'][$key] ?? $this->values['messages']['genericError']);
    }

    /** What the browser may see: widget texts plus CTAs. Never paths, models or limits. */
    public function widget(): array
    {
        $widget = $this->values['widget'];
        $widget['contactCtas'] = array_values($this->values['contactCtas']);
        $widget['maxLength']   = (int) $this->values['limits']['maxMsgLength'];
        return $widget;
    }

    /**
     * Resolves a path setting against $baseDir. Returns '' when the setting is empty,
     * so callers can tell "not configured" apart from a real path.
     */
    public function path(string $key, string $baseDir): string
    {
        $value = (string) ($this->values['paths'][$key] ?? '');
        if ($value === '' && $key === 'topicsFile') {
            $root = $this->path('sourceRoot', $baseDir);
            return $root === '' ? '' : $root . '/topics.json';
        }
        if ($value === '' && $key === 'rateDir') {
            $logDir = $this->path('logDir', $baseDir);
            return $logDir === '' ? '' : $logDir . '/rate';
        }
        if ($value === '' || self::isAbsolute($value)) {
            return $value;
        }
        return rtrim($baseDir, '/\\') . '/' . $value;
    }

    public static function isAbsolute(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1);
    }

    /**
     * The merge rule of the constructor, for a caller that layers several override sets
     * before it builds a Config (e.g. a host with two bots on one config file): maps merge
     * per key, a list replaces as a whole.
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])
                && !array_is_list($value) && !array_is_list($base[$key])) {
                $base[$key] = self::merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }
}
