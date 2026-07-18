<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Services\AssetCleaner,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod;

class SystemController extends BackendAbstractController
{
    #[Fetch, HttpMethod('POST')]
    protected function clearCacheAction(): FetchResponse
    {
        DI::getCacheManager()->clearAllApcu();
        DI::getCacheManager()->page()->clearAll();
        $assetCount = (new AssetCleaner())->clearAll();

        $this->messageService->pushFlash('success', "Cache geleert (APCu + PageCache + {$assetCount} versionierte Assets)");
        return $this->fetch()->setStatus('success');
    }

    #[Fetch, HttpMethod('POST')]
    protected function savePreferencesAction(): FetchResponse
    {
        $body = DI::getRequest()->getJsonBody();

        // Start from the stored preferences — this panel owns only the
        // appearance fields and must not drop others (e.g. partial_labels).
        $service = DI::getCurrentUserService();
        $prefs   = $service->getPreferences();
        $prefs->setPalette($body['palette'] ?? 'werkbank');
        $prefs->setDarkMode((bool) ($body['darkMode'] ?? false));
        $prefs->setFontScale((float) ($body['fontScale'] ?? 1.0));

        $service->savePreferences($prefs);

        return $this->fetch()->setStatus('success');
    }

    #[Fetch, HttpMethod('POST')]
    protected function toggleDebugAction(): FetchResponse
    {
        $flag     = ABS_BASE_PATH . '/data/framework/debug.flag';
        $newState = !file_exists($flag);

        if ($newState) {
            touch($flag);
        } else {
            unlink($flag);
        }

        DI::getCacheManager()->clearAllApcu();
        DI::getCacheManager()->page()->clearAll();

        $this->messageService->pushFlash('success', $newState ? 'Entwickler-Modus aktiviert' : 'Entwickler-Modus deaktiviert');

        if (!$newState) {
            $missing = $this->findMissingMinJs();
            if ($missing) {
                $this->messageService->pushFlash(
                    'error',
                    'Fehlende minifizierte JS-Dateien: ' . implode(', ', $missing)
                    . ' — die Seite nutzt den unminifizierten Fallback. Build ausführen.'
                );
            }
        }

        return $this->fetch()
            ->setStatus('success')
            ->setData(['devMode' => $newState]);
    }

    /**
     * Checks every JS asset registered in the module-level layoutConfigs for a
     * missing .min.js variant. Warns the admin at the moment debug is switched
     * off — the moment the .min files become the served sources. Controller-level
     * addJs() registrations are not scanned; those are covered at request time by
     * the JavascriptManager fallback (unminified source + error log).
     *
     * @return string[] e.g. ['shell.min.js (Z77\Module\Backend)']
     */
    private function findMissingMinJs(): array
    {
        $missing = [];

        foreach (array_keys(DI::getFileFinder()->getAllNamespaces()) as $nameSpace) {
            $layoutConfig = DI::getConfigManager()->getArrayConfig(
                configName: 'Ui/Config/layoutConfig',
                nameSpace: $nameSpace,
                throwError: false
            );

            foreach ($layoutConfig->get('javascripts', []) as $javascript) {
                $name = $javascript['name'] ?? null;
                if ($name === null) {
                    continue;
                }
                $jsNameSpace = $javascript['nameSpace'] ?? $nameSpace;

                $found = DI::getFileFinder()->getFirstAssetMatch(
                    fileName: "js/{$name}.min.js",
                    nameSpace: $jsNameSpace,
                    throwError: false,
                    cachePersist: false
                );
                if ($found === null) {
                    $missing["{$jsNameSpace}|{$name}"] = "{$name}.min.js ({$jsNameSpace})";
                }
            }
        }

        return array_values($missing);
    }

    #[Fetch, HttpMethod('POST')]
    protected function toggleNoindexAction(): FetchResponse
    {
        $flag     = ABS_BASE_PATH . '/data/framework/seo/noindex.flag';
        $newState = !file_exists($flag);

        if ($newState) {
            @mkdir(dirname($flag), 0775, true);
            touch($flag);
        } else {
            unlink($flag);
        }

        // Cached frontend pages bake the robots meta in — drop them so the new state serves.
        DI::getCacheManager()->clearAllApcu();
        DI::getCacheManager()->page()->clearAll();

        $this->messageService->pushFlash('success', $newState ? 'Website für Suchmaschinen gesperrt (noindex)' : 'Website für Suchmaschinen freigegeben');
        return $this->fetch()
            ->setStatus('success')
            ->setData(['noindex' => $newState]);
    }
}
