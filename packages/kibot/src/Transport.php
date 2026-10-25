<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * The HTTP layer under AnthropicClient. Injectable so the tests can replay recorded
 * responses without a key and without network.
 */
interface Transport
{
    /**
     * Sends one POST request.
     *
     * @param list<string>                $headers  complete header lines ("name: value")
     * @param null|callable(string): void $onChunk  receives the raw body piece by piece
     *                                              while it arrives (streaming); null = buffer only
     */
    public function send(string $url, array $headers, string $body, ?callable $onChunk = null): HttpResult;
}
