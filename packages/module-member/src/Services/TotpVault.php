<?php

namespace Z77\Module\Member\Services;

/**
 * Encryption for the TOTP secret at rest (B8 spec: «verschluesselt abgelegt»,
 * never plaintext in the account store). AES-256-GCM via OpenSSL; the key is
 * a 32-byte file under data/framework/member/ — outside the webroot, created
 * on first use, never committed (data/ is untracked) and never deployed as
 * code. Losing the key file means every member re-runs the 2FA setup —
 * annoying, not catastrophic; the backend reset handgrip exists anyway.
 *
 * Ciphertext format: base64(iv[12] . tag[16] . ct). decrypt() answers null
 * for anything that does not authenticate — a tampered store yields "no 2FA",
 * never a wrong secret.
 */
final class TotpVault
{
    public function __construct(private string $keyFile)
    {
    }

    public static function create(): self
    {
        return new self(
            rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/') . '/data/framework/member/totp.key'
        );
    }

    public function encrypt(string $plain): string
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($ct === false) {
            throw new \RuntimeException('TOTP secret encryption failed');
        }

        return base64_encode($iv . $tag . $ct);
    }

    public function decrypt(string $stored): ?string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) { // 12 iv + 16 tag + ≥1 ct
            return null;
        }

        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            $this->key(),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16)
        );

        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        if (is_file($this->keyFile)) {
            $key = (string)file_get_contents($this->keyFile);
            if (strlen($key) === 32) {
                return $key;
            }
        }

        $dir = dirname($this->keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $key = random_bytes(32);
        file_put_contents($this->keyFile, $key, LOCK_EX);
        @chmod($this->keyFile, 0600);

        return $key;
    }
}
