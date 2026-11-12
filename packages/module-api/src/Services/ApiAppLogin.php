<?php

namespace Z77\Module\Api\Services;

use Z77\Core\DI,
    Z77\Module\Api\Entities\ApiAppUser,
    Z77\Module\Api\Entities\ApiDevice,
    Z77\Shared\Alert\AlertService,
    Z77\Shared\Alert\EmailAlertChannel,
    Z77\Shared\Auth\LoginCodeStore,
    Z77\Shared\Mail\EmailMessage,
    Z77\Shared\Throttle\FileThrottle
;

/**
 * The sign-in of an app user by an e-mail code (ADR-058) — what `POST devices/login` and
 * `POST devices/verify` do ({@see DevicesService}):
 *
 *   request()  {email}: ALWAYS one record in the code store and the same 202, for unknown,
 *              inactive and active addresses alike (the anti-oracle rule of module-member's
 *              LoginFlow). The requester gets a random nonce (cookie `z77_api_login`); only for
 *              a usable app user within its limits does the record carry its id and a code go
 *              out by mail. Requests never replace each other (review B4).
 *   verify()   {code} + the nonce: judged by the store under a lock (5 tries, 15 minutes); one
 *              generic failure for wrong, expired, used up and unknown. Success: the app user is
 *              asked again (active, person there), a device is issued, the device this browser
 *              was signed in as (any app user) is revoked, «Neues Gerät angemeldet» goes out.
 *
 * Limits (ADR-058 §4 «Numbers»): per e-mail 5 requests per hour and 15 per day — answered with
 * the same 202, the record then signs in nobody; per source /64 10 requests and 20 verifies per
 * hour — 429. Reaching the daily cap of an address raises an operator alert (Service › E-Mail,
 * entry `apiLoginCap`), its recovery comes with the next request the address may make.
 *
 * The per-address counters, the cap marker and the alert exist ONLY for the address of an app
 * user (security review M1): an address that is not one — malformed, or simply nobody's — is
 * never counted, never written to disk and never put into an operator mail, so an anonymous
 * caller can neither mint a file per address he types nor mail the operator a text of his own.
 * Such a request counts against one shared bucket that never limits (the same file work, so the
 * time of the answer does not tell an app user's address from another).
 *
 * No oracle in failure (review B1): a mail that cannot be sent is logged, never answered. Every
 * mail — the code, the new device, the operator alert — goes out AFTER the response where the
 * server can finish a response early (PHP-FPM, LiteSpeed); elsewhere it is sent inline and every
 * request() is padded to the same floor ({@see FLOOR_SECONDS}) — a send slower than the floor
 * stays visible there (residual risk) and is logged.
 * No «no account here» mail: an anonymous endpoint mailing strangers would be a relay.
 */
final class ApiAppLogin
{
    public const REQUESTS_PER_HOUR_PER_EMAIL = 5;
    public const REQUESTS_PER_DAY_PER_EMAIL  = 15;
    public const REQUESTS_PER_HOUR_PER_NET   = 10;
    public const VERIFIES_PER_HOUR_PER_NET   = 20;

    /** The request nonce: HttpOnly, SameSite=Strict, Path /api, as long as a code lives. */
    public const NONCE_COOKIE = 'z77_api_login';

    /** Where no response can be finished early, every request() takes at least this long. */
    public const FLOOR_SECONDS = 2.0;

    /** Service › E-Mail entry naming who is told when an address hits its daily cap. */
    public const ALERT_FORM_KEY = 'apiLoginCap';

    private const NS = 'Z77\\Module\\Api';

    /**
     * @param \Closure(EmailMessage): bool                 $sendMail      the mail stack; false = not sent
     * @param \Closure(\Closure(): void): void             $afterResponse runs a job once the answer is out
     * @param (\Closure(string, bool, string): void)|null  $alert         (source, failing, email) → AlertService
     */
    public function __construct(
        private ApiAppUsers $appUsers,
        private ApiDevices $devices,
        private LoginCodeStore $codes,
        private FileThrottle $throttle,
        private string $capDir,
        private \Closure $sendMail,
        private \Closure $afterResponse,
        private ?\Closure $alert = null,
        private float $floorSeconds = 0.0,
    ) {
    }

    /** Production wiring. */
    public static function create(): self
    {
        $base    = rtrim(str_replace('\\', '/', ABS_BASE_PATH), '/');
        $uem     = DI::getUnifiedEntityManager();
        $scopes  = new ApiDeviceScopes(DI::getModuleManager());
        $devices = new ApiDevices($uem, $scopes);
        $codes   = new LoginCodeStore(ApiAppUsers::loginCodeDir());
        $early   = self::canFinishEarly();

        return new self(
            new ApiAppUsers($uem, $scopes, $devices, $codes),
            $devices,
            $codes,
            new FileThrottle($base . '/var/lib/throttle/api'),
            $base . '/var/lib/api/login-cap',
            static fn(EmailMessage $mail): bool => DI::getEmailService()->send($mail),
            $early ? self::deferToShutdown(...) : static function (\Closure $job): void { $job(); },
            self::operatorAlert(...),
            $early ? 0.0 : self::FLOOR_SECONDS,
        );
    }

    /**
     * `devices/login {email}`. 429 only for the source network over its limit — everything
     * else, every address, is the same 202 with a fresh nonce.
     *
     * @param string|null $net the source /64 ({@see FileThrottle::normalizeIp()}); null = no usable address
     * @return array{status: 202, nonce: string}|array{status: 429, retryAfter: int}
     */
    public function request(string $email, ?string $net, ?string $userAgent, ?int $now = null): array
    {
        $started = microtime(true);
        $now     = $now ?? time();
        $netKey  = 'app-login-net:' . ($net ?? 'unknown');
        if (!$this->throttle->allow($netKey, self::REQUESTS_PER_HOUR_PER_NET, 3600, $now)) {
            return ['status' => 429, 'retryAfter' => $this->throttle->retryAfter(3600, $now)];
        }

        $email   = ApiAppUser::normalizeEmail($email);
        // A malformed address is nobody's (review M1) — no lookup, no counter, no alert.
        $appUser = ApiAppUsers::isAppEmail($email) ? $this->appUsers->findByEmail($email) : null;
        $within  = $this->withinEmailLimits($appUser, $now);
        $person  = $within && $appUser->isActive() ? $this->appUsers->linkedPerson($appUser) : null;
        $subject = $person !== null ? (string) $appUser->getId() : null;

        $issued = $this->codes->issue($subject, $now);
        if ($subject !== null && $issued['stored']) {
            $mail = $this->codeMail($appUser, $person['name'], $issued['code'], ApiDevices::label($userAgent), $now);
            ($this->afterResponse)(fn() => $this->send($mail, 'login code mail'));
        }

        $this->pad($started);

        return ['status' => 202, 'nonce' => $issued['nonce']];
    }

    /**
     * `devices/verify {code}` with the nonce of the request.
     *
     * @param string|null $currentCookie the device cookie this browser carries, if any
     * @return array{status: 'ok', device: ApiDevice, cookie: string, appUser: ApiAppUser, ownerName: string, scopes: list<string>}
     *       |array{status: 'failed'}|array{status: 'throttled', retryAfter: int}
     */
    public function verify(string $nonce, string $code, ?string $net, ?string $currentCookie, ?string $userAgent, ?int $now = null): array
    {
        $now = $now ?? time();
        if (!$this->throttle->allow('app-verify-net:' . ($net ?? 'unknown'), self::VERIFIES_PER_HOUR_PER_NET, 3600, $now)) {
            return ['status' => 'throttled', 'retryAfter' => $this->throttle->retryAfter(3600, $now)];
        }

        $subject = $this->codes->verify($nonce, $code, $now);
        $appUser = $subject !== null ? $this->appUsers->find((int) $subject) : null;
        $person  = $appUser !== null && $appUser->isActive() ? $this->appUsers->linkedPerson($appUser) : null;
        if ($person === null) {
            return ['status' => 'failed'];
        }

        // Signed in again on a browser that holds a live device cookie: the old row goes, WHOSE
        // ever it is (review S1, answer 4) — findByCookie() proves the browser holds its secret,
        // so the person at it may end it. The app keeps each app user's waiting captures apart
        // (the queue stamps the app user), so nothing of the other one goes out under this one.
        $old = $currentCookie !== null ? $this->devices->findByCookie($currentCookie, $now) : null;
        if ($old !== null) {
            $this->devices->revoke((int) $old->getId(), $now);
        }

        $issued = $this->devices->issue($appUser, $userAgent, $now);
        $this->appUsers->recordLogin($appUser, $now);

        $mail = $this->newDeviceMail($appUser, $person['name'], $issued['device']->getLabel(), $now);
        ($this->afterResponse)(fn() => $this->send($mail, 'new device mail'));

        return [
            'status'    => 'ok',
            'device'    => $issued['device'],
            'cookie'    => $issued['cookie'],
            'appUser'   => $appUser,
            'ownerName' => $person['name'],
            'scopes'    => $this->appUsers->liveScopes($appUser, $person),
        ];
    }

    /** The `Set-Cookie` value of the request nonce. */
    public static function nonceCookieHeader(string $nonce, bool $secure, ?int $now = null): string
    {
        $expires = gmdate('D, d M Y H:i:s', ($now ?? time()) + LoginCodeStore::TTL_SECONDS) . ' GMT';

        return self::NONCE_COOKIE . '=' . $nonce
            . '; Expires=' . $expires
            . '; Max-Age=' . LoginCodeStore::TTL_SECONDS
            . '; Path=/api'
            . ($secure ? '; Secure' : '')
            . '; HttpOnly; SameSite=Strict';
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Counts the request against the app user's address: hour first, then day. Over either = no
     * code. The daily cap raises the alert; the first request allowed after it ends the incident.
     * Both alerts go out after the response, like the code mail (review M1: an inline SMTP call
     * only for an app user's address would be a timing oracle).
     *
     * No app user (null): one shared bucket without a limit, the same file work — no file per
     * address, no marker, no alert; false (nobody gets a code anyway).
     * The files stay bounded: per app user one counter per window, one marker at most.
     *
     * @phpstan-assert-if-true ApiAppUser $appUser
     */
    private function withinEmailLimits(?ApiAppUser $appUser, int $now): bool
    {
        $email = $appUser?->getEmail();
        $key   = $email !== null ? hash('sha256', $email) : 'nobody';
        $hour  = $email !== null ? self::REQUESTS_PER_HOUR_PER_EMAIL : PHP_INT_MAX;
        $day   = $email !== null ? self::REQUESTS_PER_DAY_PER_EMAIL : PHP_INT_MAX;
        if (!$this->throttle->allow('app-login-mail-h:' . $key, $hour, 3600, $now)) {
            return false;
        }

        $marker = $this->capDir . '/' . $key;
        if (!$this->throttle->allow('app-login-mail-d:' . $key, $day, 86400, $now)) {
            if (!is_dir($this->capDir)) {
                @mkdir($this->capDir, 0755, true);
            }
            @touch($marker);
            ($this->afterResponse)(fn() => $this->raise('api-login:daily-cap:' . substr($key, 0, 16), true, (string) $email));
            return false;
        }
        if ($email !== null && is_file($marker)) {
            @unlink($marker);
            ($this->afterResponse)(fn() => $this->raise('api-login:daily-cap:' . substr($key, 0, 16), false, $email));
        }

        return $email !== null;
    }

    private function raise(string $source, bool $failing, string $email): void
    {
        if ($this->alert === null) {
            error_log('ApiAppLogin: ' . ($failing ? 'daily cap reached' : 'daily cap over') . " ({$source})");
            return;
        }
        try {
            ($this->alert)($source, $failing, $email);
        } catch (\Throwable $e) {
            error_log('ApiAppLogin: alert not delivered — ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    private function codeMail(ApiAppUser $appUser, string $name, string $code, string $device, int $now): EmailMessage
    {
        return (new EmailMessage())
            ->to($appUser->getEmail())
            ->language(ApiAppUsers::LANGUAGE)
            // Code first: a subject ENDING in a number is a spam signal (KAM_NUMSUBJECT).
            ->subject($code . ' ist dein Code für die z77 App')
            ->template('emails/appLoginCode', self::NS, [
                'greetingName' => $name,
                'code'         => $code,
                'device'       => $device,
                'when'         => date('d.m.Y, H:i', $now),
                'minutes'      => intdiv(LoginCodeStore::TTL_SECONDS, 60),
            ]);
    }

    private function newDeviceMail(ApiAppUser $appUser, string $name, string $device, int $now): EmailMessage
    {
        return (new EmailMessage())
            ->to($appUser->getEmail())
            ->language(ApiAppUsers::LANGUAGE)
            ->subject('Neues Gerät angemeldet — z77 App')
            ->template('emails/appNewDevice', self::NS, [
                'greetingName' => $name,
                'device'       => $device,
                'when'         => date('d.m.Y, H:i', $now),
            ]);
    }

    /** A failure is logged, never answered (review B1). */
    private function send(EmailMessage $mail, string $what): void
    {
        try {
            if (!($this->sendMail)($mail)) {
                error_log("ApiAppLogin: {$what} not sent");
            }
        } catch (\Throwable $e) {
            error_log("ApiAppLogin: {$what} not sent — " . get_class($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * Pads request() to the floor where mails go out inline. A request that took longer — a slow
     * SMTP send — is logged: its time tells that a mail went out (the residual risk of ADR-058).
     */
    private function pad(float $started): void
    {
        if ($this->floorSeconds <= 0) {
            return;
        }
        $took = microtime(true) - $started;
        $left = $this->floorSeconds - $took;
        if ($left > 0) {
            usleep((int) ($left * 1_000_000));
            return;
        }
        error_log(sprintf('ApiAppLogin: a login request took %.2f s, over the %.1f s floor — its time is measurable (mail sent inline)', $took, $this->floorSeconds));
    }

    /** PHP-FPM and LiteSpeed can end the response and keep running. */
    private static function canFinishEarly(): bool
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    /** The job runs at shutdown, after the response has been handed to the client. */
    private static function deferToShutdown(\Closure $job): void
    {
        register_shutdown_function(static function () use ($job): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $job();
        });
    }

    /** Edge-triggered operator mail (AlertService); nobody named in Service › E-Mail = nothing. */
    private static function operatorAlert(string $source, bool $failing, string $email): void
    {
        $to = implode(',', DI::getEmailService()->formRecipients(self::ALERT_FORM_KEY)['to']);
        if ($to === '') {
            error_log('ApiAppLogin: ' . ($failing ? 'daily cap reached' : 'daily cap over') . " ({$source}), no alert recipient");
            return;
        }
        $alerts = new AlertService(AlertService::defaultDir(), [new EmailAlertChannel($to)]);
        $failing
            ? $alerts->failure($source, 'daily-cap', ['email' => $email, 'limit' => self::REQUESTS_PER_DAY_PER_EMAIL . ' per day'])
            : $alerts->success($source, ['email' => $email]);
    }
}
