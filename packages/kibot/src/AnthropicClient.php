<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * Thin client for POST /v1/messages — non-streaming (create) and streaming (stream).
 *
 * Raw HTTP on purpose: the package has no dependencies (plan section 4). The client
 * sends what the caller builds and returns the assembled message; it knows nothing
 * about topics, the widget or SSE towards the browser.
 *
 * Failures become an ApiException carrying the HTTP status (0 = no connection):
 * HTTP 4xx/5xx with a JSON error body, and an "error" event in the middle of a stream.
 * The key is read lazily per request and never appears in an exception or a log.
 */
final class AnthropicClient
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly Transport $transport,
        private readonly string $version = '2026-10-21',
        private readonly string $endpoint = 'https://api.anthropic.com/v1/messages',
    ) {
    }

    /**
     * Non-streaming call. Returns the decoded message (content, stop_reason, usage, ...).
     *
     * @param list<string> $betas values for the anthropic-beta header
     */
    public function create(array $body, array $betas = []): array
    {
        unset($body['stream']);
        $result = $this->transport->send($this->endpoint, $this->headers($betas), $this->encode($body));
        if ($result->status === 0 || $result->status >= 400) {
            throw ApiException::fromBody($result->status, $result->body, $result->error);
        }
        $message = json_decode($result->body, true);
        if (!is_array($message) || !isset($message['content']) || !is_array($message['content'])) {
            throw new ApiException($result->status, 'invalid_response', 'response is not a message', $result->body);
        }
        return $message;
    }

    /**
     * Streaming call. $onText receives each text delta as it arrives; the return value
     * is the assembled message in the same shape as create() returns, so its content
     * blocks can be sent back unchanged in the next turn.
     *
     * A tool_use block's input is decoded only after its content_block_stop. Input that
     * is not valid JSON becomes null; the caller answers it with an is_error tool_result.
     *
     * @param callable(string): void $onText
     * @param list<string>           $betas
     */
    public function stream(array $body, callable $onText, array $betas = []): array
    {
        $body['stream'] = true;
        $parser = new StreamParser($onText);
        $result = $this->transport->send(
            $this->endpoint,
            $this->headers($betas),
            $this->encode($body),
            static fn (string $chunk) => $parser->feed($chunk),
        );
        $parser->finish();

        if ($result->status === 0 || $result->status >= 400) {
            throw ApiException::fromBody($result->status, $result->body, $result->error);
        }
        if ($parser->error !== null) {
            $type = (string) ($parser->error['type'] ?? 'api_error');
            $status = match ($type) {
                'overloaded_error' => 529,
                'rate_limit_error' => 429,
                default            => 500,
            };
            throw new ApiException($status, $type, (string) ($parser->error['message'] ?? $type), '', true);
        }
        return $parser->message();
    }

    /** @param list<string> $betas */
    private function headers(array $betas): array
    {
        $headers = [
            'content-type: application/json',
            'x-api-key: ' . $this->keys->get(),
            'anthropic-version: ' . $this->version,
        ];
        if ($betas !== []) {
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }
        return $headers;
    }

    private function encode(array $body): string
    {
        return json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }
}
