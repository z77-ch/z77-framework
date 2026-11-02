<?php

/**
 * The other half of real-flow-site.php: PHP's built-in server over such a site, a browser with a
 * cookie jar, and module-member added to the site. Used by the harnesses that exercise the real
 * stack with the member door in it.
 */

/** Starts a server over $site and waits for it; it ends with the harness. Returns its port. */
function realFlowServer(string $site, string $sessions, string $log): int
{
    if (!is_dir($sessions)) {
        mkdir($sessions, 0777, true);
    }
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port  = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-S', "127.0.0.1:{$port}", '-t', $site . '/public', $site . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $site
    );
    register_shutdown_function(static function () use ($server, &$pipes): void {
        if (is_resource($server)) {
            proc_terminate($server);
        }
    });
    $deadline = microtime(true) + 10;
    do {
        usleep(100000);
        $sock = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
    } while ($sock === false && microtime(true) < $deadline);
    if ($sock !== false) {
        fclose($sock);
    }

    return $port;
}

/**
 * Adds module-member to a realFlowSite(). The monorepo autoloader does not map it, so the site's
 * stub gets a loader of its own. $memberConfig is merged over the framework's memberConfig through
 * a project-side file, which is how a project sets a hook; $autoloadExtra is PHP code run by the
 * stub (a hook class of the harness).
 *
 * @param array<string, mixed> $memberConfig
 */
function realFlowAddMember(string $site, string $root, array $memberConfig = [], string $autoloadExtra = ''): void
{
    $packages = str_replace('\\', '/', $root) . '/packages/';

    $stub = (string) file_get_contents($site . '/vendor/autoload.php');
    $body = preg_replace('/^<\?php\s*/', '', $stub);
    file_put_contents($site . '/vendor/autoload.php', "<?php\n"
        . 'spl_autoload_register(static function (string $class): void {' . "\n"
        . '    $prefix = ' . var_export('Z77\\Module\\Member\\', true) . ";\n"
        . '    if (str_starts_with($class, $prefix)) {' . "\n"
        . '        $file = ' . var_export($packages . 'module-member/src/', true) . ' . str_replace(\'\\\\\', \'/\', substr($class, strlen($prefix))) . \'.php\';' . "\n"
        . '        if (is_file($file)) { require $file; }' . "\n"
        . "    }\n"
        . "});\n"
        . $autoloadExtra . "\n"
        . $body);

    $sourcePaths = '$entry(\'module-member\')';
    if ($memberConfig !== []) {
        mkdir($site . '/project-override/src/App/Config', 0777, true);
        file_put_contents($site . '/project-override/src/App/Config/memberConfig.inc.php', "<?php\nreturn array_merge(require "
            . var_export($packages . 'module-member/src/App/Config/memberConfig.inc.php', true) . ', '
            . var_export($memberConfig, true) . ");\n");
        $sourcePaths = 'array_merge_recursive([\'sourcePaths\' => [' . var_export($site . '/project-override', true) . ']], $entry(\'module-member\'))';
    }

    $finder = (string) file_get_contents($site . '/config/fileFinder.inc.php');
    file_put_contents($site . '/config/fileFinder.inc.php', str_replace(
        "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),",
        "'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),\n        'Z77\\\\Module\\\\Member\\\\'   => {$sourcePaths},",
        $finder
    ));
    $modules = (string) file_get_contents($site . '/config/moduleManager.inc.php');
    file_put_contents($site . '/config/moduleManager.inc.php', (string) preg_replace("/('backend' =>\s*array \(\s*\),)/", "$1\n    'member' => array (),", $modules));

    $source = $packages . 'module-member/res/assets';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $to = $site . '/public/assets/member' . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
        if ($file->isFile()) {
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0777, true);
            }
            copy($file->getPathname(), $to);
        }
    }

    // The login form loads the frontend's public-form script.
    $source = $packages . 'module-frontend/res/assets/js';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        $to = $site . '/public/assets/frontend/js' . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
        if ($file->isFile()) {
            if (!is_dir(dirname($to))) {
                mkdir(dirname($to), 0777, true);
            }
            copy($file->getPathname(), $to);
        }
    }

    if (!is_dir($site . '/data/framework/member')) {
        mkdir($site . '/data/framework/member', 0777, true);
    }
}

/** One browser: a cookie jar and raw requests, redirects NOT followed. */
final class RealFlowBrowser
{
    /** @var array<string, string> */
    public array $cookies = [];

    /** @var list<string> every Set-Cookie line this browser received */
    public array $setCookies = [];

    public function __construct(private int $port)
    {
    }

    /**
     * @param array<string, mixed>|string|null $post a string goes out as JSON
     * @param list<string> $headers
     * @return array{0: int, 1: array<string, string>, 2: string}
     */
    public function go(string $path, array|string|null $post = null, array $headers = []): array
    {
        $head = $headers;
        if ($this->cookies !== []) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "{$k}={$v}";
            }
            $head[] = 'Cookie: ' . implode('; ', $pairs);
        }
        $opts = ['ignore_errors' => true, 'timeout' => 30, 'follow_location' => 0, 'max_redirects' => 0, 'method' => $post === null ? 'GET' : 'POST'];
        if (is_string($post)) {
            $head[] = 'Content-Type: application/json';
            $opts['content'] = $post;
        } elseif ($post !== null) {
            $head[] = 'Content-Type: application/x-www-form-urlencoded';
            $opts['content'] = http_build_query($post);
        }
        $opts['header'] = implode("\r\n", $head);
        $body   = (string) @file_get_contents("http://127.0.0.1:{$this->port}{$path}", false, stream_context_create(['http' => $opts]));
        clearstatcache(); // the server is another process: a file it removed must not look present here
        $status = 0;
        $out    = [];
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
                continue;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $name = strtolower(trim($name));
            $out[$name] = trim($value);
            if ($name === 'set-cookie' && preg_match('/^([^=]+)=([^;]*)/', trim($value), $c)) {
                $this->setCookies[] = trim($value);
                $this->cookies[$c[1]] = $c[2];
            }
        }

        return [$status, $out, $body];
    }

    /** The token of this session from a form or from the shell's meta tag. */
    public function pageToken(string $path): string
    {
        $html = $this->go($path)[2];
        if (preg_match('/name="csrf_token" value="([^"]+)"/', $html, $m) || preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m)) {
            return $m[1];
        }

        return '';
    }

    /** A fetch POST as core.js sends it: JSON, the mode header, the CSRF header when given. */
    public function fetchPost(string $path, array $body, ?string $token): array
    {
        $headers = ['Sec-Fetch-Mode: cors'];
        if ($token !== null) {
            $headers[] = 'X-CSRF-Token: ' . $token;
        }

        return $this->go($path, (string) json_encode($body), $headers);
    }

    public function fetchGet(string $path): array
    {
        return $this->go($path, null, ['Sec-Fetch-Mode: cors', 'X-Requested-With: XMLHttpRequest']);
    }
}
