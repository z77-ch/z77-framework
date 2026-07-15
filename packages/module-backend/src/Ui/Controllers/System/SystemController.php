<?php
namespace Z77\Module\Backend\Ui\Controllers\System;

use Z77\Core\Http\Response\FetchResponse,
    Z77\Core\Services\AssetCleaner,
    Z77\Core\DI,
    Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Shared\Attributes\Fetch,
    Z77\Shared\Attributes\HttpMethod,
    Z77\Shared\ValueObjects\UserPreferences;

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
        $body  = DI::getRequest()->getJsonBody();
        $prefs = new UserPreferences([
            'palette'    => $body['palette']   ?? 'werkbank',
            'dark_mode'  => (bool) ($body['darkMode']  ?? false),
            'font_scale' => (float) ($body['fontScale'] ?? 1.0),
        ]);

        DI::getCurrentUserService()->savePreferences($prefs);

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
        return $this->fetch()
            ->setStatus('success')
            ->setData(['devMode' => $newState]);
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
