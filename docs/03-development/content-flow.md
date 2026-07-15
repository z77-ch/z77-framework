# Content Flow — exact method trace

Zweck: den **vollständigen Methoden-Ablauf** zweier Requests zum Studieren.
Stand 2026-06-04. `→` = Aufruf, Einrückung = Aufruftiefe. Datei:Zeile zur Orientierung.

Zwei Requests:
- **a) `GET /home`** — Frontend, content-getriebene Startseite.
- **b) `GET /backend/content/content/list`** — Backend, Liste der Content-Dokumente.

---

## 0. Gemeinsamer Vorlauf (beide Requests)

```
public/index.php
  define ABS_BASE_PATH / ABS_INDEX_PATH
  require vendor/autoload.php
  new Bootstrap()
  $dispatcher = $bootstrap->pullUp()        // core/Bootstrap.php
  $dispatcher->execute()                    // core/Routing/Dispatcher.php
```

### Bootstrap::pullUp()  (core/Bootstrap.php)
```
DI::set(...) registriert nur Framework-Infrastruktur, u.a.:
  'UnifiedEntityManager', 'NavigationService', 'Router', 'PageCachePolicy', …
  (NICHT 'BlockRegistry' / 'ContentService' — die sind consumer-gebaute Factories, adr-012)
$request = DI::getRequest()
$request->runParsing()                      // URL → module/group/controller/action
   → Router (nutzt NavigationService) + ControllerHandler::resolveController()/resolveAction()
       resolveController(): Naming::toControllerClassName(prefix, group, controller)
       resolveAction():     Naming::toActionMethod(action)   // 'list' → 'listAction'
DI::getControllerHandler()->lock()          // Auflösung einfrieren
DI::set(...) für 'SessionManager','MessageService','CsrfService','AuthService',
       'CurrentUserService','AccessGuard','Dispatcher'
require Functions.php / Helper.php (globale Helfer: e(), raw(), …)
return DI::getDispatcher()
```

Routing-Ergebnis:
| Request | module | group | controller | action | Klasse::Methode |
|---|---|---|---|---|---|
| `/home` | Frontend | Main | Index | homeAction | `Z77\Module\Frontend\Ui\Controllers\Main\IndexController::homeAction` |
| `/backend/content/content/list` | Backend | Content | Content | listAction | `Z77\Module\Backend\Ui\Controllers\Content\ContentController::listAction` |

### Dispatcher::execute()  (Dispatcher.php:37)
```
$controller = DI::getControllerHandler()->getCurrentControllerInstance()
              → new {ControllerClass}($actionMethod)           // ControllerHandler.php:57
$request    = DI::getRequest()
enforceActionConstraints($controller, $request)                // Dispatcher.php:83
   ReflectionMethod(action) → prüft #[Fetch] / #[Page] / #[HttpMethod]
   (listAction/homeAction: keine → keine Einschränkung)
$denied = $this->accessGuard->enforce()                        // AccessGuard.php:42
   $authUser     = AuthService::getCurrentUser()
   $requiredRole = AuthService::resolveRoleForCurrentController()
   AuthService::hasSufficientRole($authUser,$requiredRole) ? null : RedirectResponse('/login')
     • /home                → GUEST erlaubt → null
     • /backend/.../list    → controllerRole ADMIN; Gast → Redirect /login, Admin → null
if $denied === null:
   $decision = PageCachePolicy::decide($request)               // NewPage | PageFromCache | PageFromClientCache
   $response = resolveResponse($decision, $controller)         // Dispatcher.php:112
      resolveNavigation()                                      // Dispatcher.php:148
         NavigationService::resolveCurrent(module,group,controller,action,query)
         NavigationService::resolveUiCurrent($via)
      $response = $controller->run()                           // ← der eigentliche Controller
$response->send()
```

### AbstractBaseController::run()  (AbstractBaseController.php:34)
```
$this->layoutManager  = new LayoutManager(ControllerHandler, DEBUG)
$this->messageService = DI::getMessageService()
preExecute()  (falls definiert — hier nicht)
$response = execute()                                          // AbstractBaseController.php:56
   → $this->{actionMethod}()                                   // homeAction() / listAction()
postExecute() (falls definiert — hier nicht)
return $response
```

Ab hier trennen sich die beiden Requests.

---

## a) GET /home

### IndexController::homeAction()  (module-frontend/.../Main/IndexController.php:11)
```
$language    = DI::getRequest()->getLanguage()
$contentHtml = ContentService::create()->render('home', $language)   // Factory, nicht DI (adr-012)
   │
   ├─ ContentService::create()                                // shared/Services/ContentService.php
   │     repo = DI::getUnifiedEntityManager()->getRepository(Content::class)
   │     new ContentRenderer(BlockRegistry::assemble())       // assemble(): DefaultBlockRegistry + Modul-contentBlocks
   └─ ContentService::render('home', $language)               // shared/Services/ContentService.php
        ContentService::find('home', $language)               // ContentService.php:23
           ContentRepository::findBySlug('home', $language)   // shared/.../ContentRepository.php:14
              FileRepository::findOneBy(['slug'=>'home','language'=>$language])
                 → Document-Mode: lädt EINE Datei data/content/home.<lang>.json (O(1))
                 → hydratisiert ein Content-Entity
           Active-Gate: $content !== null && $content->isActive()
              → Content  (aktiv)   |  null  (fehlt/inaktiv)
        wenn Content:
           ContentRenderer::render($content)                  // shared/Content/ContentRenderer.php:16
              ContentRenderer::renderBlocks($content->getBlocks())   // :24
                 foreach block (array):
                    BlockRegistry::render($block)              // shared/Content/BlockRegistry.php:37
                       $renderer = renderers[$block['type']] ?? null
                       $renderer?->render($block, $this->inline) ?? ''
                          z.B. HeroRenderer::render($block, $inline)
                             InlineMarkdown::toHtml($field)    // escape-first + Whitelist
                             → '<section class="fe-hero">…</section>'
                       unbekannter Typ → ''
                 → konkatenierte, sichere HTML-Blöcke
        sonst: ''
return $this->html(['pageTitle'=>'Home', 'contentHtml'=>$contentHtml])
```

### AbstractFrontendController::html()  (module-frontend/.../AbstractFrontendController.php:25)
```
$user    = DI::getAuthService()->getCurrentUser()
$isAdmin = $user?->hasAtLeast(AuthRole::ADMIN)
$isPage  = Request::getMode() === Page
if $isAdmin && $isPage:                       // Gast: übersprungen
   context['authUser','viewAreas','routeInfo'] setzen
$response = parent::html($context)            // → AbstractBaseController::html()
if $isAdmin && $isPage:
   layoutManager->addCss('admin-overlay')
   layoutManager->addPartials('adminOverlay', 'partials', NS, 'adminOverlay')
return $response
```

### AbstractBaseController::html()  (AbstractBaseController.php:97)
```
layoutManager->initialize()                   // LayoutManager.php:88
   Page-Mode:
     ConfigManager liest Ui/Config/layoutConfig (Frontend) [+ controllerConfig falls vorhanden]
     setSkeletonTemplate()  → html-default-skeleton (Frontend)
     body.main leer → addPartials($this->action) → 'homeAction' → homeAction.tpl.php
context['navigationService'] = NavigationService
context['navigation']        = NavigationService::getCurrent()
context['language']          = Request::getLanguage()
context['metaData']          = NavigationService::findMetaData(navId, language)
context['csrfToken']         = CsrfService::getToken()
context['_flashes'/'_messages'] = MessageService::consumeFlashesForPage()/…  (Page-Mode)
return new HtmlResponse($layoutManager, $context)
```

### HtmlResponse::send()  (HtmlResponse.php:137)  — am Ende von Dispatcher::execute()
```
sendHeaders()   (Content-Type, Cache-Control aus CacheMode, evtl. ETag)
sendBody() → echo getHtml()                   // HtmlResponse.php:117
   layoutManager->buildView() → new HtmlView(skeletonPath, partials, css, js, ns)  // LayoutManager.php:166
   HtmlView::assign($context)
   HtmlView::render()                          // HtmlView.php:56
      renderPartials():
         je Partial: TemplateRenderer::render($path, $context)   // extract + ob + require
            body.main = homeAction.tpl.php  →  <?= $contentHtml ?>   (bereits sicheres HTML)
            (adminOverlay nur falls Admin)
      renderCss() / renderJs('head'|'footer')
      TemplateRenderer::render(skeletonPath, context + layoutVars)
         html-default-skeleton (Frontend): <?= $head ?>,<?= $css ?>,<?= $main ?>,<?= $jsFooter ?> …
   (kein Envelope bei Page-Load)
```

---

## b) GET /backend/content/content/list

AccessGuard (siehe §0): `controllerRole = ADMIN`. Gast → 302 `/login`. Admin → weiter.

### ContentController::listAction()  (module-backend/.../Content/ContentController.php:41)
```
$contents = $this->repo()->findAll()
   repo() → $this->em()->getRepository(Content::class)        // em() = DI::getUnifiedEntityManager()
   FileRepository::findAll()
      → Document-Mode: liest ALLE data/content/*.json → Content[] hydratisiert
usort($contents, slug,language)
$response = $this->html(['contents'=>$contents])
$this->layoutManager->addCss('navigation/list', NS)           // Listen-Styling (wiederverwendet)
$this->layoutManager->addCss('content/editor',  NS)           // Modal-Styling (muss auf der Vollseite sein)
return $response
```

### BackendAbstractController::html()  (module-backend/.../BackendAbstractController.php:43)
```
context['userPreferences'] = CurrentUserService::getPreferences()
context['navGroupSlug']    = 'backend-main'
context['bePalette'/'beTheme'/'beFontScale'] = aus userPreferences
context['routeInfo']       = $this->routeInfo()
$user = DI::getAuthService()->getCurrentUser()
if $user?->isLoggedIn():
   context['headerUser'] = ['initials'=>…, 'name'=>…, 'role'=>…]   // plain View-Model, KEIN AuthUser
return parent::html($context)                 // → AbstractBaseController::html()
```

### AbstractBaseController::html()  (AbstractBaseController.php:97)
```
layoutManager->initialize()                   // Page-Mode
   ConfigManager liest Ui/Config/layoutConfig (Backend):
      body-Partials: header, subnav, footer, flash, messages
      javascripts:   core, panel-toggle, appearance, system/cache
      styleSheets:   base, mobile, tablet, desktop, nav-*
   setSkeletonTemplate() → html-default-skeleton (Backend)
   body.main leer → addPartials('listAction') → listAction.tpl.php
context['navigationService','navigation','language','metaData','csrfToken'] (wie §a)
context['_flashes'/'_messages'] = consume…ForPage()
return new HtmlResponse($layoutManager, $context)
```

### HtmlResponse::send() → getHtml() → HtmlView::render()
```
renderPartials() (TemplateRenderer::render je Partial):
   header.tpl.php   nutzt $headerUser, $navigationService, $navGroupSlug  (Topbar/Logo/Avatar)
                    Guard: if (empty($headerUser)) return;   // reine Daten-Präsenz
   subnav.tpl.php   nutzt $navigationService, $navGroupSlug; getActiveSectionByGroupSlug()
                    Hinweis: listAction übergibt KEIN 'activeSection' → Tree bleibt inaktiv
   main             = listAction.tpl.php  → rendert die $contents-Zeilen (be-tree__node …)
   footer / flash / messages
renderCss()  → base.css (enthält .be-list/.be-tree/.be-btn) + content/editor.css
renderJs()   → core, panel-toggle, appearance, system/cache
TemplateRenderer::render(skeletonPath, context + layoutVars)
   html-default-skeleton (Backend): <?= $header ?>,<?= $subnav ?>,<?= $main ?>,<?= $footer ?>
      + <dialog data-z77-popup class="be-modal"> … (Fullscreen-Button, scrollbarer Body)
(kein Envelope bei Page-Load)
```

---

## Anhang — verwandter Pfad: ein Listeneintrag bearbeiten (Fetch)

Nur zur Einordnung (nicht Teil von a/b): Klick auf «Bearbeiten» ist ein **eigener
Request** im **Fetch-Mode** (`Sec-Fetch-Mode: cors`):

```
GET /backend/content/content/edit?slug=home&language=de   (Fetch)
ContentController::editAction() → edit():
   $registry = BlockRegistry::assemble()                  // Factory, nicht DI (adr-012)
   $registry->types() / $registry->schemas()              // Editor-Formulare
   $this->html([... 'schemas'=>…])
      LayoutManager::initialize() (Fetch-Mode): NUR Fetch-Skeleton, KEIN Page-Config
   addPartials('edit', …)                                  // body.main = edit.tpl.php
   $response->addCommand('load-script', src=content/editor, init='content-editor', scope=[data-z77-popup-body])
send() → getHtml(): HtmlView rendert NUR das edit-Partial in den Fetch-Skeleton
   + angehängter <script data-z77-envelope>{commands:[load-script,…]}</script>
Client (core.js): popup.show(html) → wire() → führt load-script aus → editor.js initialisiert das Modal
Speichern: POST …/edit  → ContentValidator → em()->persist()/flush() → Envelope {close-modal, reload}
```

---

## Merkpunkte

- **Genau eine** `html()`-Kette: `{Modul}AbstractController::html()` → `AbstractBaseController::html()` → `HtmlResponse` → (send-time) `LayoutManager::buildView()` → `HtmlView::render()` → `TemplateRenderer::render()`.
- **Rendering lazy am send-Zeitpunkt** (`HtmlResponse::getHtml()`), damit späte Asset-Registrierungen (addCss/addJs nach `html()`) noch einfliessen.
- **Frontend** geht für Content immer durch `ContentService` (Active-Gate). **Backend** liest das Repository direkt (sieht inaktive Dokumente) und rendert sie (noch) nicht.
- **`BlockRegistry`** ist die einzige Stelle, die `type → Renderer` kennt — Rendering-Pfad (`render`) und Authoring-Pfad (`types`/`schemas`) nutzen dieselbe Assemblierung (`BlockRegistry::assemble()`), pro Consumer einmal gebaut (kein DI-Singleton, adr-012).
- **Page vs. Fetch** entscheidet `LayoutManager::initialize()`: Page = volles Skeleton + Partials; Fetch = nur Fetch-Skeleton + das eine Action-Partial (+ Envelope).
