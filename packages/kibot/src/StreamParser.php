<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * Assembles a Messages API SSE stream into a message.
 *
 * Fed with raw chunks in any size (a line may be split across chunks). Handles
 * message_start, content_block_start/delta/stop (text, thinking, tool_use),
 * message_delta (stop_reason, usage), message_stop, ping and the "error" event.
 * Thinking blocks are kept exactly as received (incl. signature) so the caller can
 * send them back unchanged.
 */
final class StreamParser
{
    /** The "error" object of an error event, or null. */
    public ?array $error = null;

    private string $buffer = '';
    private array $message = ['role' => 'assistant', 'content' => [], 'stop_reason' => null, 'usage' => []];
    /** @var array<int, string> partial_json per tool_use block index */
    private array $partialJson = [];
    /** @var callable(string): void */
    private $onText;

    public function __construct(callable $onText)
    {
        $this->onText = $onText;
    }

    public function feed(string $chunk): void
    {
        $this->buffer .= $chunk;
        while (($pos = strpos($this->buffer, "\n")) !== false) {
            $line = rtrim(substr($this->buffer, 0, $pos), "\r");
            $this->buffer = substr($this->buffer, $pos + 1);
            $this->line($line);
        }
    }

    /** Processes a last line without trailing newline. */
    public function finish(): void
    {
        if ($this->buffer !== '') {
            $this->line(rtrim($this->buffer, "\r"));
            $this->buffer = '';
        }
    }

    public function message(): array
    {
        $message = $this->message;
        ksort($message['content']);
        $message['content'] = array_values($message['content']);
        return $message;
    }

    private function line(string $line): void
    {
        // "event:" lines repeat the type that the data object carries; data is enough.
        if (!str_starts_with($line, 'data:')) {
            return;
        }
        $event = json_decode(trim(substr($line, 5)), true);
        if (!is_array($event)) {
            return;
        }

        switch ($event['type'] ?? '') {
            case 'message_start':
                $start = $event['message'] ?? [];
                foreach (['id', 'model', 'role'] as $key) {
                    if (isset($start[$key])) {
                        $this->message[$key] = $start[$key];
                    }
                }
                $this->message['usage'] = $start['usage'] ?? [];
                break;

            case 'content_block_start':
                $index = (int) ($event['index'] ?? count($this->message['content']));
                $block = $event['content_block'] ?? [];
                if (($block['type'] ?? '') === 'tool_use') {
                    $this->partialJson[$index] = '';
                }
                $this->message['content'][$index] = $block;
                break;

            case 'content_block_delta':
                $this->delta((int) ($event['index'] ?? 0), $event['delta'] ?? []);
                break;

            case 'content_block_stop':
                $index = (int) ($event['index'] ?? 0);
                if (array_key_exists($index, $this->partialJson)) {
                    $json  = $this->partialJson[$index];
                    $input = $json === '' ? [] : json_decode($json, true);
                    // Invalid JSON -> null; the caller answers with an is_error tool_result.
                    $this->message['content'][$index]['input'] = is_array($input) ? $input : null;
                    unset($this->partialJson[$index]);
                }
                break;

            case 'message_delta':
                if (isset($event['delta']['stop_reason'])) {
                    $this->message['stop_reason'] = $event['delta']['stop_reason'];
                }
                if (isset($event['usage']) && is_array($event['usage'])) {
                    $this->message['usage'] = array_merge($this->message['usage'], $event['usage']);
                }
                break;

            case 'error':
                $this->error = is_array($event['error'] ?? null) ? $event['error'] : ['type' => 'api_error'];
                break;

            // message_stop, ping: nothing to do
        }
    }

    private function delta(int $index, array $delta): void
    {
        switch ($delta['type'] ?? '') {
            case 'text_delta':
                $text = (string) ($delta['text'] ?? '');
                $this->message['content'][$index]['text'] = ($this->message['content'][$index]['text'] ?? '') . $text;
                if ($text !== '') {
                    ($this->onText)($text);
                }
                break;
            case 'thinking_delta':
                $this->message['content'][$index]['thinking'] = ($this->message['content'][$index]['thinking'] ?? '') . ($delta['thinking'] ?? '');
                break;
            case 'signature_delta':
                $this->message['content'][$index]['signature'] = ($this->message['content'][$index]['signature'] ?? '') . ($delta['signature'] ?? '');
                break;
            case 'input_json_delta':
                $this->partialJson[$index] = ($this->partialJson[$index] ?? '') . ($delta['partial_json'] ?? '');
                break;
        }
    }
}
