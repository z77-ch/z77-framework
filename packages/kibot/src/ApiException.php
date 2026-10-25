<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * An Anthropic API failure with its HTTP status (0 = no connection) and the API's
 * error type. Thrown for HTTP 4xx/5xx and for an "error" event in the middle of a stream.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorType,
        string $message,
        public readonly string $rawBody = '',
        public readonly bool $midStream = false,
    ) {
        parent::__construct($message, $status);
    }

    /** Builds the exception from a JSON error body ({"type":"error","error":{...}}). */
    public static function fromBody(int $status, string $rawBody, string $transportError = ''): self
    {
        if ($status === 0) {
            return new self(0, 'connection_error', $transportError !== '' ? $transportError : 'no HTTP response', $rawBody);
        }
        $data = json_decode($rawBody, true);
        $type = is_array($data) ? (string) ($data['error']['type'] ?? '') : '';
        $msg  = is_array($data) ? (string) ($data['error']['message'] ?? '') : '';
        return new self($status, $type !== '' ? $type : 'http_error', $msg !== '' ? $msg : 'HTTP ' . $status, $rawBody);
    }

    /** 404 on /v1/messages almost always means the configured model was retired. */
    public function isRetiredModel(): bool
    {
        return $this->status === 404 || $this->errorType === 'not_found_error';
    }

    /** Rate limit or overload: the user gets the "busy" text. */
    public function isBusy(): bool
    {
        return in_array($this->status, [429, 529], true)
            || in_array($this->errorType, ['overloaded_error', 'rate_limit_error'], true);
    }

    public function isConnectionError(): bool
    {
        return $this->status === 0 && !$this->midStream;
    }
}
