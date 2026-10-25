<?php
namespace Z77\Module\Backend\Ui\Assistant;

use Z77\Core\DI,
    Z77\Core\Kibot\HelpKnowledge,
    Z77\Core\Kibot\KibotEndpoint,
    Z77\Core\Libraries\FileFinder,
    Z77\KiBot\Config,
    Z77\KiBot\Log,
    Z77\KiBot\Transport
;

/**
 * The backend KI-Assistent (ADR-055): KiBot over the help texts of the installation.
 *
 * Configuration: the SAME project file as the website bot, `config/client/kibot.inc.php`.
 * The backend takes from it only what holds for both bots — `paths.keyFile`, `paths.logDir`,
 * `paths.rateDir`, `api`, `budget`, `limits`, `log` — and NOT the website's texts, prompt,
 * CTAs or sources. Its own texts and prompt are the defaults below; a project changes them
 * under the key `backend` (same shape as a KiBot config, only the deviations):
 *
 *   return [
 *       'paths'   => ['keyFile' => '/home/<hosting>/.z77/<project>/keys/anthropic.key'],
 *       'backend' => ['widget' => ['botName' => 'Muster-Assistent']],
 *   ];
 *
 * Knowledge: the generated help index ({@see HelpKnowledge}) under `data/kibot/help/`
 * (`paths.sourceRoot`; `data/` is a shared release directory and not public). It is
 * refreshed lazily before each question and by `bin/assistant-index.php`.
 *
 * Off — the shell keeps the classic «Hilfe» — while z77/kibot is not installed, the file is
 * missing, or no readable key file is configured ({@see enabled()}).
 */
final class BackendAssistant
{
    /** Key of the backend section in `config/client/kibot.inc.php`. */
    public const SECTION = 'backend';

    /** Session key of the topics of the last answer — apart from the website bot's. */
    public const TOPICS_KEY = 'kibotBackendTopics';

    public const CONFIG_URL = '/backend/system/assistant/config';
    public const ASK_URL    = '/backend/system/assistant/ask';

    /** Settings of the project file that hold for the backend too; everything else is the website's. */
    private const SHARED_KEYS  = ['api', 'budget', 'limits', 'log'];
    private const SHARED_PATHS = ['keyFile', 'logDir', 'rateDir'];

    private const DEFAULTS = [
        'widget' => [
            'botName'          => 'KI-Assistent',
            'heroTitle'        => 'Antworten aus den Hilfetexten',
            'placeholder'      => 'Deine Frage zur Bedienung …',
            'welcomeMsg'       => 'Hallo. Ich beantworte Fragen zur Bedienung des Backends — aus den Hilfetexten.',
            // %s = the screen the assistant is opened from (its help title).
            'contextWelcome'   => 'Hallo. Du bist bei «%s». Frag mich zur Bedienung — ich antworte aus den Hilfetexten aller Bildschirme.',
            'contextSwitch'    => 'Jetzt bei «%s».',
            'fallbackLabel'    => 'Hilfetext anzeigen',
            'widgetLabel'      => 'KI-Assistent',
            'toggleOpenLabel'  => 'KI-Assistent öffnen',
            'toggleCloseLabel' => 'KI-Assistent schliessen',
            'toggleTitle'      => 'KI-Assistent öffnen / schliessen',
            'closeLabel'       => 'KI-Assistent schliessen (Esc)',
            'inputLabel'       => 'Frage',
            'connectionError'  => 'Verbindungsfehler. Bitte später nochmals versuchen — oder «Hilfetext anzeigen».',
            'printTitle'       => 'KI-Assistent — Protokoll',
            'printUserLabel'   => 'Frage',
            'avatarAlt'        => 'KI-Assistent',
        ],
        'contactCtas' => [],
        'messages' => [
            'overloaded'       => 'Der KI-Dienst ist im Moment überlastet. Bitte in ein paar Minuten nochmals versuchen — oder «Hilfetext anzeigen».',
            'genericError'     => 'Es ist ein Fehler aufgetreten. Bitte später nochmals versuchen — oder «Hilfetext anzeigen».',
            'sessionExpired'   => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.',
            'rateLimited'      => 'Zu viele Fragen in kurzer Zeit. Bitte etwas später nochmals.',
            'noMessages'       => 'Keine gültige Frage.',
            'noApiKey'         => 'Der KI-Assistent ist zur Zeit nicht verfügbar. Der Hilfetext steht unter «Hilfetext anzeigen».',
            'invalidRequest'   => 'Ungültige Anfrage.',
            'connectionFailed' => 'Keine Verbindung zum KI-Dienst. Der Hilfetext steht unter «Hilfetext anzeigen».',
            'refusal'          => 'Dazu kann ich keine Auskunft geben.',
        ],
        'paths' => [
            'sourceRoot' => 'data/kibot/help',
            'topicsFile' => '',
        ],
        'log' => [
            'conversationFile' => 'kibot-backend-chat',
        ],
        'prompt' => [
            'intro' => 'Du bist der KI-Assistent im Backend von z77, einer Verwaltungssoftware (Inhalte, Dokumente, Kontakte, Aufträge, Rechnungen, Buchhaltung). Du hilfst angemeldeten Benutzern bei der Bedienung. Deine einzige Quelle sind die Hilfetexte der Bildschirme.',
            'rules' => [
                'Beantworte Fragen AUSSCHLIESSLICH anhand der Hilfetexte, die dir das Werkzeug lies_themen liefert.',
                'Steht etwas nicht in den Hilfetexten, sage klar: "Dazu steht nichts in der Hilfe." Rate nicht.',
                'Erfinde KEINE Menüpfade, Bildschirme, Knöpfe, Felder oder Abläufe. Nenne Bildschirme, Knöpfe und Felder genau so, wie sie im Hilfetext stehen, in «Guillemets».',
                'Du siehst keine Daten dieser Installation — keine Kunden, Aufträge, Rechnungen, Buchungen oder andere Datensätze. Fragt jemand nach einem konkreten Datensatz, sage, dass du keinen Zugriff auf Daten hast und nur die Bedienung erklärst.',
                'Antworte kurz, auf Deutsch (Schweiz: kein ß, immer ss), per du. Einen Ablauf schreibst du als nummerierte Schritte, eine Zeile pro Schritt.',
                'Schreibe schlichten Text ohne Markdown-Formatierung (keine Sternchen, keine Rauten, keine Tabellen).',
            ],
            'ctaRule'      => 'Verweise nicht auf eine Kontaktaufnahme und schreibe nie den Platzhalter {{CONTACT_CTA}}.',
            'contextTopic' => 'Der Benutzer ist gerade auf dem Bildschirm mit dem Hilfetext %1$s. Lies dieses Thema zu jeder Frage mit, dazu höchstens %2$d weitere, wenn die Frage andere Bildschirme betrifft.',
            'noTopic'      => 'Zu dieser Frage passt kein Hilfetext; es liegen keine Quellen vor. Sage das kurz, erfinde nichts und weise auf «Hilfetext anzeigen» hin.',
        ],
    ];

    /**
     * The backend's KiBot settings from the project file's overrides: the shared part, the
     * backend defaults, then the project's `backend` section.
     */
    public static function config(array $overrides): Config
    {
        $base = [];
        foreach (self::SHARED_KEYS as $key) {
            if (isset($overrides[$key]) && is_array($overrides[$key])) {
                $base[$key] = $overrides[$key];
            }
        }
        foreach (self::SHARED_PATHS as $key) {
            if (isset($overrides['paths'][$key]) && is_string($overrides['paths'][$key])) {
                $base['paths'][$key] = $overrides['paths'][$key];
            }
        }
        $section = is_array($overrides[self::SECTION] ?? null) ? $overrides[self::SECTION] : [];
        return new Config(Config::merge(Config::merge($base, self::DEFAULTS), $section));
    }

    /**
     * The installation's assistant endpoint, or null when it is off (package missing, no
     * config file).
     *
     * @throws \RuntimeException when the config file does not return an array
     */
    public static function fromConfig(?string $baseDir = null, ?Transport $transport = null): ?KibotEndpoint
    {
        $base      = KibotEndpoint::baseDir($baseDir);
        $overrides = KibotEndpoint::overrides($base);
        return $overrides === null
            ? null
            : new KibotEndpoint(self::config($overrides), $base, $transport, self::TOPICS_KEY);
    }

    /**
     * Whether the shell shows the assistant instead of the classic help: configured AND a
     * readable key file. Never throws — a broken config file keeps the classic help.
     */
    public static function enabled(?string $baseDir = null): bool
    {
        try {
            return self::fromConfig($baseDir)?->hasKey() ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** The help index the endpoint answers from (its `paths.sourceRoot`). */
    public static function knowledge(KibotEndpoint $endpoint, ?FileFinder $finder = null): HelpKnowledge
    {
        $log = new Log($endpoint->config(), $endpoint->path('logDir'));
        return HelpKnowledge::fromFileFinder(
            $finder ?? DI::getFileFinder(),
            $endpoint->path('sourceRoot'),
            static function (string $line) use ($log): void {
                $log->error(0, $line);
            }
        );
    }

    /**
     * Rebuilds the index when a help template changed (lazy, ADR-055). A failure is logged and
     * swallowed: the question then runs on the last index, or ends in the generic error.
     */
    public static function refresh(HelpKnowledge $knowledge, KibotEndpoint $endpoint): void
    {
        try {
            $knowledge->ensureFresh();
        } catch (\Throwable $e) {
            (new Log($endpoint->config(), $endpoint->path('logDir')))->error(0, 'help index: ' . $e->getMessage());
        }
    }

    /**
     * The screen name for the greeting: the help title without its «Hilfe:» lead
     * (`Hilfe: Aufträge` → `Aufträge`).
     */
    public static function screenLabel(string $helpTitle): string
    {
        return trim(preg_replace('/^Hilfe\s*:\s*/u', '', $helpTitle) ?? $helpTitle);
    }
}
