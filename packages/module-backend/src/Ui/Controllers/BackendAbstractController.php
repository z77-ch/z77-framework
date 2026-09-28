<?php
namespace Z77\Module\Backend\Ui\Controllers;

use Z77\Core\Controller\AbstractBaseController,
    Z77\Core\DI,
    Z77\Core\Http\Response\HtmlResponse,
    Z77\Core\Http\Response\FetchResponse,
    Z77\Module\Backend\Ui\BackendMenu,
    Z77\Shared\Auth\PasswordTier,
    Z77\Shared\Controller\RouteInfoTrait
;

abstract class BackendAbstractController extends AbstractBaseController
{
    /**
     * Kanonischer Pfad des Navigations-Eintrags, unter dem die Bildschirme
     * dieses Controllers stehen — fuer Seiten, die keinen eigenen Eintrag
     * haben (NAV-CURSOR-001). Leer = der Bildschirm hat seinen eigenen, oder
     * der Geschwister-Fallback reicht.
     *
     * Beispiel: `estate/tree` und `estate/widgets` sind Reiter EINES Mandanten,
     * also steht ihr Platz bei `/backend/service/tenant/list`.
     */
    protected const NAV_CURSOR = '';

    use RouteInfoTrait;

    protected const NAMESPACE = 'Z77\\Module\\Backend';

    private const CONTENT_LANGUAGE_SESSION_KEY = 'backendContentLanguage';

    /** Shared entity-manager accessor for backend CRUD controllers. */
    protected function em()
    {
        return DI::getUnifiedEntityManager();
    }

    /**
     * The backend content-editing language (session-sticky, mirrors the frontend
     * language-session model — ADR-013, see docs/topics/content.md). The backend
     * UI chrome stays single-language; this is only the language of the *content*
     * the editor is working on. Falls back to the system default language when
     * nothing is remembered or the stored value is no longer valid.
     */
    protected function contentEditLanguage(): string
    {
        $i18n       = DI::getI18n();
        $remembered = DI::getSessionManager()->get(self::CONTENT_LANGUAGE_SESSION_KEY);

        return (is_string($remembered) && $i18n->isValidLanguage($remembered))
            ? $remembered
            : $i18n->getDefaultLanguage();
    }

    /**
     * Persists an explicit content-editing language choice — the DE/FR switch on
     * the content list (`?language=<code>` is only the switch trigger; the active
     * language lives here in the session). Invalid input is ignored.
     */
    protected function setContentEditLanguage(string $language): void
    {
        if (DI::getI18n()->isValidLanguage($language)) {
            DI::getSessionManager()->set(self::CONTENT_LANGUAGE_SESSION_KEY, $language);
        }
    }

    /**
     * Installation-wide password strength tier from `config/client/auth.inc.php`
     * (defaults to {@see PasswordTier::Strong} when the config is absent/unknown).
     * Resolved here so the password-setting controllers (user admin, setup) share
     * one source. See docs/topics/security.md (PWD-POLICY-001).
     */
    protected function passwordTier(): PasswordTier
    {
        $name = DI::getConfigManager()->getBaseConfig('config/auth', throwError: false)->get('passwordTier');
        return PasswordTier::fromName(is_string($name) ? $name : null);
    }

    /** Pushes an error flash and returns a failed Fetch envelope — the standard backend fetch error path. */
    protected function fetchError(string $text): FetchResponse
    {
        $this->messageService->pushFlash('error', $text);
        return $this->fetch()->setStatus('error');
    }

    protected function html(array $context = []): HtmlResponse
    {
        if (!isset($context['userPreferences'])) {
            $context['userPreferences'] = DI::getCurrentUserService()->getPreferences();
        }
        $context['navSlot'] = 'backend-main';
        $context['bePalette']   = $context['userPreferences']->getPalette();
        $context['beTheme']     = $context['userPreferences']->isDarkMode() ? 'dark' : 'light';
        $context['beFontScale'] = $context['userPreferences']->getFontScale();
        $context['routeInfo'] ??= $this->routeInfo();

        // Header chrome view-model: plain display data, NOT the AuthUser security
        // object. The auth decision stays here in the controller (the template must
        // not touch a security object). AccessGuard has already gated the route, so
        // on any rendered backend page a user is present; GUEST pages (login/setup)
        // leave this unset → the header partial self-skips on data absence.
        $user = DI::getAuthService()->getCurrentUser();
        if ($user !== null && $user->isLoggedIn()) {
            // Avatar initials: the user-entered BackendUser::initials (2–3 chars) when set,
            // otherwise derived from the username (first two letters, uppercased). The
            // BackendUser is the one CurrentUserService already caches for this request.
            $initials = trim(DI::getCurrentUserService()->getBackendUser()?->getInitials() ?? '');
            if ($initials === '') {
                $initials = mb_strtoupper(mb_substr($user->getUserName(), 0, 2));
            }
            $context['headerUser'] ??= [
                'initials' => $initials,
                'name'     => $user->getUserName(),
                'role'     => $user->getHighestRole(),
            ];

            // The menu as this user may see it — entries follow the access
            // config (ADR-045), decided here, not in the shell templates.
            $context['backendMenu'] ??= BackendMenu::forCurrentUser($context['navSlot']);

            // The service panel's switches call SystemController actions; show
            // each only when its endpoint would accept this user (same rule as
            // the menu — an editor gets no debug/noindex/cache switches).
            $auth = DI::getAuthService();
            // Which installation this is. A backend tab looks identical in every
            // project, and that cost real time in the P2 exit check (S5: a dev
            // server of ANOTHER project on another port was read as this one).
            $context['installationName'] ??= self::installationLabel();

            $context['shellTools'] ??= [
                'debug'      => $auth->canReach($user, 'backend', 'system', 'system', 'toggle-debug'),
                'noindex'    => $auth->canReach($user, 'backend', 'system', 'system', 'toggle-noindex'),
                'clearCache' => $auth->canReach($user, 'backend', 'system', 'system', 'clear-cache'),
            ];
        }

        $this->loadHeaderSlots();

        // Wo dieser Bildschirm im Menue steht, wenn er keinen eigenen Eintrag
        // hat (NAV-CURSOR-001). Leer = nichts zu tun; ein Bildschirm MIT
        // eigenem Eintrag bleibt ohnehin unberuehrt.
        if (static::NAV_CURSOR !== '') {
            DI::getNavigationService()->setUiCursor(static::NAV_CURSOR);
        }

        return parent::html($context);
    }

    /**
     * What the topbar calls this installation: `installationName` from
     * systemConfig, or — unset, which is the seed — the host of
     * `canonicalBaseUrl`, which already differs per installation. Empty only
     * where neither is set; the shell then shows nothing rather than a guess
     * (the missing `canonicalBaseUrl` has its own banner).
     *
     * Read here and not published as a constant like `CANONICAL_BASE_URL`:
     * that one exists because a cron entry needs it too, this is chrome of
     * the backend shell and of nothing else. `cachePersist: false` for the
     * same reason as in `Bootstrap` — an edited systemConfig must show
     * without «Cache leeren».
     */
    private static function installationLabel(): string
    {
        $name = trim((string) DI::getConfigManager()
            ->getBaseConfig(configName: 'config/systemConfig', throwError: false, cachePersist: false)
            ->getInstallationName('')
        );
        if ($name !== '') {
            return $name;
        }

        $base = defined('CANONICAL_BASE_URL') ? CANONICAL_BASE_URL : '';

        return $base === '' ? '' : (string) preg_replace('#^https?://#', '', $base);
    }

    /**
     * Shell rebuild Phase 2 — header-slot auto-loader. For the CURRENT controller/action this
     * loads convention partials into the shell's aligned header band (body sections
     * hc1/hc2/hc3/tabs) IF the files exist:
     * `{Group}/{Controller}/{action}.hc1|hc2|hc3|tabs.tpl.php`. The speaking region names
     * `act|toolbar|crumb` are accepted for `hc1|hc2|hc3` and win when both exist (glossary in
     * docs/topics/css-backend.md → shell regions); new templates use them. `tabs` is the tab row (B10
     * v1.17.0) — unlike the other three it renders only when the file exists, because tabs
     * belong to the screen, not to the shell. A view thus only
     * DROPS IN the partial file(s) — no per-action `addPartials` boilerplate; every backend area
     * is wired identically. The partial is rendered with the full action context (HtmlView renders
     * every section with the same data), so it can read the action's view-model vars. A view with
     * no such files simply gets no header slots (the legacy inline `.backend-content-header` in its
     * body still works during migration).
     */
    private function loadHeaderSlots(): void
    {
        $handler = DI::getControllerHandler();
        $class   = $handler->getCurrentControllerClassName();
        $marker  = 'Ui\\Controllers\\';
        $idx     = strpos($class, $marker);
        if ($idx === false) {
            return;
        }
        // Namespace segment after Ui\Controllers → template dir, e.g. "Content/ContentController".
        $dir    = str_replace('\\', '/', substr($class, $idx + strlen($marker)));
        // Method "listAction" → convention action name "list".
        $action = preg_replace('/Action$/', '', $handler->getCurrentActionMethod());
        $finder = DI::getFileFinder();

        // Body section => accepted file suffixes, speaking name first. Only the first match loads,
        // so a screen that has both never renders its slot twice.
        $slots = [
            'hc1'  => ['act', 'hc1'],
            'hc2'  => ['toolbar', 'hc2'],
            'hc3'  => ['crumb', 'hc3'],
            'tabs' => ['tabs'],
        ];
        foreach ($slots as $section => $suffixes) {
            foreach ($suffixes as $suffix) {
                $file = $action . '.' . $suffix;   // e.g. "list.act"
                if ($finder->getFirstTplMatch($dir . '/' . $file . '.tpl.php', self::NAMESPACE, throwError: false) !== null) {
                    $this->layoutManager->addPartials($file, $dir, self::NAMESPACE, $section);
                    break;
                }
            }
        }
    }
}
