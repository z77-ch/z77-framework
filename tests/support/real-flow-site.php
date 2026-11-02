<?php

/**
 * A minimal installation under $site: the kernel's seed data, flat config, 'file' mail transport.
 * The packages are read in place through fileFinder and a stub autoloader. Deliberately no symlink
 * or junction out of the temp tree: the clean-up at the end must not be able to reach the repo.
 * The harnesses tests/backend-password-reset.php and tests/backend-2fa.php run on it.
 *
 * @param list<array<string, mixed>> $users the rows of backendUsers.json
 */
function realFlowSite(string $site, string $root, array $users): void
{
    foreach (['public', 'config', 'vendor', 'data/framework/auth', 'var/state', 'var/lib', 'logs'] as $dir) {
        mkdir($site . '/' . $dir, 0777, true);
    }
    file_put_contents($site . '/vendor/autoload.php', "<?php\nreturn require " . var_export($root . '/vendor/autoload.php', true) . ";\n");

    $seed = $root . '/packages/kernel/core/data/framework';
    $it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($seed, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.default.json')) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($seed)));
        $to  = $site . '/data/framework' . substr($rel, 0, -strlen('.default.json')) . '.json';
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0777, true);
        }
        copy($file->getPathname(), $to);
    }

    $config = static function (string $name, array $values) use ($site): void {
        file_put_contents($site . '/config/' . $name . '.inc.php', "<?php\nreturn " . var_export($values, true) . ";\n");
    };
    $config('bootstrap', ['cacheDebug' => false, 'htmlRoot' => 'public', 'apcuCachePrefix' => 'z77test' . getmypid(), 'overrideDir' => 'override',
        'moduleDir' => 'module', 'assetDir' => 'assets', 'tplDir' => 'res/view/templates', 'timeZone' => 'Europe/Zurich']);
    $config('systemConfig', ['canonicalBaseUrl' => 'https://kunde.example.ch']);
    $config('auth', ['passwordTier' => 'strong']);
    $config('i18n', ['defaultLanguage' => 'de', 'languages' => ['de']]);
    $config('mail', ['enabled' => true, 'transport' => 'file', 'outbox' => $site . '/data/framework/mail/outbox', 'fromAddress' => 'no-reply@example.ch', 'fromName' => 'Beispiel']);
    $config('moduleManager', ['modulePrefix' => 'Module', 'frameworkPrefix' => 'Z77', 'defaultModule' => 'frontend', 'currentModule' => null, 'modules' => ['frontend' => [], 'backend' => []]]);
    $packages = var_export(str_replace('\\', '/', $root) . '/packages/', true);
    file_put_contents($site . '/config/fileFinder.inc.php', <<<PHP
<?php
\$packages = {$packages};
\$entry = fn(string \$package) => [
    'sourcePaths' => [\$packages . \$package],
    'assetPaths'  => [ABS_BASE_PATH . '/public/assets/' . str_replace('module-', '', basename(\$package))],
];
return [
    'resourceDir' => ['sourceDir' => 'src', 'tplDir' => 'res/view/templates'],
    'namespaces' => [
        'Z77\\\\Core\\\\'             => \$entry('kernel/core'),
        'Z77\\\\Shared\\\\'           => \$entry('kernel/shared'),
        'Z77\\\\Persistence\\\\'      => \$entry('kernel/persistence'),
        'Z77\\\\Module\\\\Frontend\\\\' => \$entry('module-frontend'),
        'Z77\\\\Module\\\\Backend\\\\'  => \$entry('module-backend'),
    ],
];
PHP);

    file_put_contents($site . '/data/framework/auth/backendUsers.json', json_encode($users, JSON_PRETTY_PRINT));

    // The installer copies assets into public/ too; the JS manager writes versioned copies beside
    // them, so reading them from the packages would litter the repository.
    foreach (['kernel/shared' => 'shared', 'module-backend' => 'backend'] as $package => $asset) {
        $source = $root . '/packages/' . $package . '/res/assets';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
            $to = $site . '/public/assets/' . $asset . str_replace('\\', '/', substr($file->getPathname(), strlen($source)));
            if ($file->isFile()) {
                if (!is_dir(dirname($to))) {
                    mkdir(dirname($to), 0777, true);
                }
                copy($file->getPathname(), $to);
            }
        }
    }

    file_put_contents($site . '/public/index.php', file_get_contents($root . '/packages/kernel/core/public/index.php'));
    file_put_contents($site . '/router.php', <<<'PHP'
<?php
// The built-in server's DOCUMENT_ROOT is what index.php derives the project from.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
PHP);
}
