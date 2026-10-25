<?php

declare(strict_types=1);

namespace Z77\KiBot;

/** Transport over ext-curl. The only place that touches the network. */
final class CurlTransport implements Transport
{
    public function __construct(private readonly int $timeoutSeconds = 120)
    {
    }

    public function send(string $url, array $headers, string $body, ?callable $onChunk = null): HttpResult
    {
        $raw = '';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_WRITEFUNCTION  => static function ($curl, string $data) use (&$raw, $onChunk): int {
                $raw .= $data;
                if ($onChunk !== null) {
                    $onChunk($data);
                }
                return strlen($data);
            },
        ]);

        $ok     = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = $ok === false ? curl_error($ch) : '';
        // No curl_close(): deprecated since PHP 8.5, the handle is freed with $ch.

        return new HttpResult($ok === false ? 0 : $status, $raw, $error);
    }
}
