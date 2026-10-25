<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * The topic list (topics.json) and the sources behind it.
 *
 * The model only ever sees id, titel and beschreibung. Sources are read when the model
 * asks for a topic, from files below the source root: relative paths only, no "..",
 * nothing that resolves outside the root. Markdown and text are taken as they are,
 * HTML is reduced to text. The combined text is cut to a character budget.
 *
 * topics.json:
 *   {"version": 1, "themen": [{"id": "...", "titel": "...", "beschreibung": "...",
 *                              "quellen": ["dir/file.md", ...]}]}
 */
final class Topics
{
    public const EXTENSIONS = ['md', 'txt', 'html', 'htm'];
    private const TRUNCATED = "\n[... gekürzt]";

    /** @var array<string, array{id: string, titel: string, beschreibung: string, quellen: list<string>}> */
    private array $topics = [];
    private string $root;

    /** @throws \RuntimeException when the file or the source root is missing or the JSON is invalid */
    public function __construct(string $topicsFile, string $sourceRoot)
    {
        $root = realpath($sourceRoot);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('KiBot: source root not found: ' . $sourceRoot);
        }
        $this->root = $root;

        if (!is_file($topicsFile) || !is_readable($topicsFile)) {
            throw new \RuntimeException('KiBot: topics file not found: ' . $topicsFile);
        }
        $data = json_decode((string) file_get_contents($topicsFile), true);
        if (!is_array($data) || !isset($data['themen']) || !is_array($data['themen'])) {
            throw new \RuntimeException('KiBot: topics file has no "themen" list: ' . $topicsFile);
        }
        foreach ($data['themen'] as $i => $t) {
            $id = is_array($t) ? (string) ($t['id'] ?? '') : '';
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $id) !== 1) {
                throw new \RuntimeException("KiBot: topic #{$i} has an invalid id (a-z, 0-9, _ and -): {$id}");
            }
            if (isset($this->topics[$id])) {
                throw new \RuntimeException("KiBot: duplicate topic id: {$id}");
            }
            $this->topics[$id] = [
                'id'           => $id,
                'titel'        => trim((string) ($t['titel'] ?? $id)),
                'beschreibung' => trim((string) ($t['beschreibung'] ?? '')),
                'quellen'      => array_values(array_map('strval', (array) ($t['quellen'] ?? []))),
            ];
        }
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->topics);
    }

    public function has(string $id): bool
    {
        return isset($this->topics[$id]);
    }

    public function title(string $id): string
    {
        return $this->topics[$id]['titel'] ?? $id;
    }

    /** The list for the system prompt: id, titel, beschreibung — no contents. */
    public function catalog(): string
    {
        $lines = [];
        foreach ($this->topics as $t) {
            $lines[] = '- ' . $t['id'] . ' | ' . $t['titel'] . ': ' . $t['beschreibung'];
        }
        return implode("\n", $lines);
    }

    /**
     * Reads the sources of the given topics within $budget characters in total.
     * Each topic gets an equal share; what one topic leaves unused passes on to the next.
     * Each source is marked with its topic title. Unknown ids are ignored (validate first).
     *
     * @param list<string> $ids
     * @return array{text: string, truncated: bool, files: list<string>, skipped: list<string>}
     */
    public function read(array $ids, int $budget): array
    {
        $ids   = array_values(array_filter(array_unique($ids), fn ($id) => $this->has($id)));
        $parts = [];
        $files = [];
        $skipped   = [];
        $truncated = false;
        $left      = $budget;

        foreach ($ids as $n => $id) {
            $share = intdiv($left, count($ids) - $n);
            $text  = '';
            foreach ($this->topics[$id]['quellen'] as $relative) {
                $file = $this->resolve($relative);
                if ($file === null) {
                    $skipped[] = $relative;
                    continue;
                }
                $files[] = $relative;
                $text   .= ($text === '' ? '' : "\n\n") . $this->load($file);
            }
            // The separator belongs to the chunk, so the budget covers the joined text.
            $header = ($n > 0 ? "\n\n" : '') . '=== Quelle: ' . $this->topics[$id]['titel'] . " ({$id}) ===\n";
            $room   = max(0, $share - Text::length($header));
            if (Text::length($text) > $room) {
                $text = Text::cut($text, max(0, $room - Text::length(self::TRUNCATED))) . self::TRUNCATED;
                $truncated = true;
            }
            $chunk   = $header . ($text !== '' ? $text : '(keine Inhalte)');
            $parts[] = $chunk;
            $left   -= Text::length($chunk);
        }

        return ['text' => implode('', $parts), 'truncated' => $truncated, 'files' => $files, 'skipped' => $skipped];
    }

    /**
     * Absolute path of a source below the root, or null if the path is absolute,
     * contains "..", has a disallowed extension, is missing or resolves outside the root.
     */
    public function resolve(string $relative): ?string
    {
        $relative = str_replace('\\', '/', trim($relative));
        if ($relative === '' || str_contains($relative, "\0") || Config::isAbsolute($relative)
            || preg_match('#^[a-z][a-z0-9+.-]*:#i', $relative) === 1) {
            return null;
        }
        if (in_array('..', explode('/', $relative), true)) {
            return null;
        }
        if (!in_array(strtolower(pathinfo($relative, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
            return null;
        }
        $real = realpath($this->root . '/' . $relative);
        if ($real === false || !is_file($real)) {
            return null;
        }
        $rootPrefix = rtrim($this->root, '/\\') . DIRECTORY_SEPARATOR;
        return str_starts_with($real, $rootPrefix) ? $real : null; // symlink out of the root
    }

    /** HTML to plain text: drops script/style, keeps block breaks, decodes entities. */
    public static function htmlToText(string $html): string
    {
        $html = preg_replace('#<(script|style|head|noscript)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? '';
        $html = preg_replace('#</(p|div|li|h[1-6]|tr|section|article|table|ul|ol)>#i', "\n", $html) ?? '';
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        return trim(preg_replace('/\n{3,}/', "\n\n", $text) ?? $text);
    }

    private function load(string $file): string
    {
        $content = (string) file_get_contents($file);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content; // BOM
        $ext     = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        return trim($ext === 'html' || $ext === 'htm' ? self::htmlToText($content) : $content);
    }
}
