<?php
/** Start of the app shell (`/app/`, AppController::startAction): the queue banner and the waiting
 *  entries (filled by `_Z77.app.shell` — DOM nodes and textContent, never HTML), then every screen
 *  a module contributes (`appScreens`), each HIDDEN until the device shows the screen's scope
 *  (`GET /api/v1/devices/me`; 401 → /app/login). The scope only decides what is shown; the gateway
 *  decides what the device may do. At the end the sign-out of this device (ADR-058) — refused by
 *  the script while the queue holds unsent entries. */
/** @var list<array{key: string, scope: string, title: string, template: string, js: list<string>, nameSpace: string}> $screens */
?>
<div class="za-banner" data-app-banner hidden>
    <svg class="za-icon" aria-hidden="true"><use href="#za-i-offline"></use></svg>
    <span data-app-banner-text></span>
</div>
<div class="za-pad za-stack" data-app-queue hidden></div>
<p class="za-pad za-notice" data-app-notice role="status" hidden></p>
<p class="za-pad za-muted" data-app-loading>Einen Moment …</p>
<?php if ($screens === []): ?>
    <p class="za-pad za-notice">Auf dieser Installation bietet kein Modul eine Funktion für das Handy an.</p>
<?php endif; ?>
<?php foreach ($screens as $screen): ?>
    <section class="za-screen" data-app-screen="<?= e($screen['key']) ?>" data-app-scope="<?= e($screen['scope']) ?>" data-app-title="<?= e($screen['title']) ?>" hidden>
        <?= $this->partial($screen['template'], ['screen' => $screen], $screen['nameSpace']) ?>
    </section>
<?php endforeach; ?>
<p class="za-pad za-stack" data-app-account hidden>
    <button type="button" class="za-link" data-app-logout>Auf diesem Gerät abmelden</button>
</p>
<noscript>
    <p class="za-pad za-notice">Die App braucht JavaScript. Bitte JavaScript im Browser einschalten.</p>
</noscript>
