<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * Two logs in the log directory:
 *
 *  - conversation log: one JSON object per line, one file per month
 *    (kibot-chat_YYYY-MM.jsonl). Appending a line instead of rewriting a JSON array
 *    keeps the cost per entry constant (chat-review 3.1); files older than
 *    retentionMonths are deleted when a new month starts.
 *  - API error log: one text line per failure,
 *    "[Y-m-d H:i:s] HTTP <status> | <type message> | <raw body, max 500 chars>".
 *    A 404 additionally names the model as "MODEL RETIRED?".
 *
 * Neither log ever contains the API key or request headers.
 */
final class Log
{
    public function __construct(
        private readonly Config $config,
        private readonly string $dir,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** @param array<string, mixed> $extra topics, model, stop_reason, usage, ... */
    public function conversation(string $question, string $answer, string $ip = '', array $extra = []): void
    {
        if ($question === '' && $answer === '') {
            return;
        }
        $now   = $this->now();
        $entry = ['datetime' => date('Y-m-d H:i:s', $now)];
        $ip    = $this->ip($ip);
        if ($ip !== null) {
            $entry['ip'] = $ip;
        }
        $entry += ['question' => $question, 'answer' => $answer] + $extra;

        $file  = $this->conversationFile($now);
        $isNew = !is_file($file);
        $this->append($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
        if ($isNew) {
            $this->purgeOld($now);
        }
    }

    public function apiError(ApiException $e, string $model = ''): void
    {
        $message = trim($e->errorType . ' ' . $e->getMessage());
        if ($e->isRetiredModel()) {
            $message = 'MODEL RETIRED? model=' . $model . ' (update api.answerModel / api.routingModel in Config) | ' . $message;
        }
        if ($e->midStream) {
            $message = 'mid-stream | ' . $message;
        }
        $this->error($e->status, $message, $e->rawBody);
    }

    public function error(int $status, string $message, string $rawBody = ''): void
    {
        $line = sprintf(
            "[%s] HTTP %d | %s | %s\n",
            date('Y-m-d H:i:s', $this->now()),
            $status,
            $message !== '' ? str_replace("\n", ' ', $message) : '(no message)',
            Text::cut(str_replace(["\r", "\n"], ' ', $rawBody), 500)
        );
        $this->append($this->dir . '/' . $this->config->get('log.errorFile'), $line);
    }

    public function conversationFile(?int $time = null): string
    {
        return $this->dir . '/' . $this->config->get('log.conversationFile') . '_' . date('Y-m', $time ?? $this->now()) . '.jsonl';
    }

    private function ip(string $ip): ?string
    {
        return match ($this->config->get('log.ip')) {
            'none' => null,
            'full' => $ip,
            // IPv4: last octet to 0; IPv6: keep the first three groups
            default => str_contains($ip, ':')
                ? implode(':', array_slice(explode(':', $ip), 0, 3)) . '::'
                : (preg_replace('/\.\d+$/', '.0', $ip) ?? ''),
        };
    }

    private function purgeOld(int $now): void
    {
        $months = (int) $this->config->get('log.retentionMonths');
        if ($months <= 0) {
            return;
        }
        $keepFrom = date('Y-m', strtotime(date('Y-m-01', $now) . " -{$months} months"));
        $prefix   = $this->config->get('log.conversationFile') . '_';
        foreach (glob($this->dir . '/' . $prefix . '*.jsonl') ?: [] as $file) {
            $month = substr(basename($file, '.jsonl'), strlen($prefix));
            if (preg_match('/^\d{4}-\d{2}$/', $month) === 1 && $month < $keepFrom) {
                @unlink($file);
            }
        }
    }

    private function append(string $file, string $content): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            return; // logging must never break the answer
        }
        @file_put_contents($file, $content, FILE_APPEND | LOCK_EX);
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
