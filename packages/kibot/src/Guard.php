<?php

declare(strict_types=1);

namespace Z77\KiBot;

/**
 * The security chain in front of every question (from the poolmanufaktur ChatController):
 *   1. honeypot  — the hidden field "website" must stay empty
 *   2. CSRF      — token issued with the widget config, compared with hash_equals
 *   3. rate limit — per session, plus per IP in files (a new session does not reset it)
 *   4. messages  — only user/assistant with string content, at most maxMsgCount,
 *                  each cut to maxMsgLength, the last one from the user
 *
 * check() throws a GuardException naming the message key; nothing is echoed here.
 */
final class Guard
{
    public const TOKEN_KEY = 'kibotCsrfToken';
    public const RATE_KEY  = 'kibotRateLimit';

    public function __construct(
        private readonly Config $config,
        private readonly SessionStore $session,
        private readonly string $rateDir = '',
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function issueToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::TOKEN_KEY, $token);
        return $token;
    }

    /**
     * @param array  $body decoded POST body: {messages: [...], token: "...", website: ""}
     * @return list<array{role: string, content: string}> the cleaned history
     * @throws GuardException
     */
    public function check(array $body, string $ip = ''): array
    {
        if (($body['website'] ?? '') !== '') {
            throw new GuardException('invalidRequest');
        }

        $token        = $body['token'] ?? '';
        $sessionToken = $this->session->get(self::TOKEN_KEY);
        if (!is_string($token) || $token === '' || !is_string($sessionToken) || $sessionToken === ''
            || !hash_equals($sessionToken, $token)) {
            throw new GuardException('sessionExpired');
        }

        $this->rateLimitSession();
        if ($ip !== '') {
            $this->rateLimitIp($ip);
        }

        return $this->messages($body['messages'] ?? null);
    }

    /** @return list<array{role: string, content: string}> */
    public function messages(mixed $messages): array
    {
        $maxLen   = (int) $this->config->get('limits.maxMsgLength');
        $maxCount = (int) $this->config->get('limits.maxMsgCount');

        $clean = [];
        foreach (is_array($messages) ? $messages : [] as $m) {
            if (!is_array($m) || !is_string($m['content'] ?? null)
                || !in_array($m['role'] ?? null, ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim(Text::cut($m['content'], $maxLen));
            if ($content !== '') {
                $clean[] = ['role' => $m['role'], 'content' => $content];
            }
        }
        $clean = array_slice($clean, -$maxCount);
        while ($clean !== [] && $clean[0]['role'] !== 'user') {
            array_shift($clean); // the conversation starts with the user
        }
        if ($clean === [] || $clean[count($clean) - 1]['role'] !== 'user') {
            throw new GuardException('noMessages');
        }
        return $clean;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }

    private function rateLimitSession(): void
    {
        $now    = $this->now();
        $window = (int) $this->config->get('limits.windowSeconds');
        $data   = $this->session->get(self::RATE_KEY);
        if (!is_array($data) || ($now - (int) ($data['start'] ?? 0)) > $window) {
            $data = ['count' => 0, 'start' => $now];
        }
        $data['count']++;
        $this->session->set(self::RATE_KEY, $data);
        if ($data['count'] > (int) $this->config->get('limits.maxRequests')) {
            throw new GuardException('rateLimited');
        }
    }

    private function rateLimitIp(string $ip): void
    {
        $max = (int) $this->config->get('limits.ipMaxRequests');
        if ($max <= 0 || $this->rateDir === '') {
            return;
        }
        if (!is_dir($this->rateDir) && !@mkdir($this->rateDir, 0775, true) && !is_dir($this->rateDir)) {
            return; // no place to count: the session limit still applies
        }
        $file = $this->rateDir . '/' . hash('sha256', $ip) . '.json';
        $fh   = @fopen($file, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $data   = json_decode((string) stream_get_contents($fh), true);
            $now    = $this->now();
            $window = (int) $this->config->get('limits.ipWindowSeconds');
            if (!is_array($data) || ($now - (int) ($data['start'] ?? 0)) > $window) {
                $data = ['count' => 0, 'start' => $now];
            }
            $data['count']++;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($data));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
        // One file per IP: now and then drop the ones whose window has long expired.
        if (random_int(1, 100) === 1) {
            $cutoff = $this->now() - 2 * (int) $this->config->get('limits.ipWindowSeconds');
            foreach (glob($this->rateDir . '/*.json') ?: [] as $old) {
                if (@filemtime($old) < $cutoff) {
                    @unlink($old);
                }
            }
        }
        if ($data['count'] > $max) {
            throw new GuardException('rateLimited');
        }
    }
}
