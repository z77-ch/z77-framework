# Review — EmailService (Framework) + Anwendung im zihlundsee-Projekt

**Date:** 2026-07-18
**Scope:** `packages/kernel/shared/src/Mail/*` + `Config/emailConfig.inc.php`
(Framework) und `override/z77/module/frontend/**` Kontakt-Strecke +
`override/z77/shared/src/Config/emailConfig.inc.php` (zihlundsee).
**Auftrag:** (1) Controller-Code identifizieren, der als Service ins Framework
gehört; (2) Ownership von From/To klären (Controller vs. emailConfig);
(3) zihlundsee-Code auf Kürze/Wiederverwendbarkeit prüfen.

---

## Befund 0 — Bug (vorab, beim Review entdeckt): Blur-Check erreicht den Controller nie

**CONTACT-CHECK-001 (Code-Analyse, live zu verifizieren).** Die Feld-Validierung
on-blur ist mit hoher Wahrscheinlichkeit tot:

1. `contact-form.js` POSTet per `fetch()` → Browser sendet `Sec-Fetch-Mode: cors`
   → `RequestMode::Fetch`.
2. `AccessGuard::enforce()` prüft bei **Fetch + POST** den CSRF-Token — aber
   ausschliesslich aus dem **Header** `X-CSRF-Token` (`Request::getCsrfToken()`).
3. `contact-form.js` sendet den Token nur im **Body** (`csrf_token`-FormData),
   keinen Header → AccessGuard antwortet mit dem Error-Envelope (HTTP 200,
   `status: 'error'`), `ContactController::checkAction` läuft nie.
4. Das JS liest `data.valid` (nicht vorhanden → falsy) → jedes Blur markiert das
   Feld als fehlerhaft, mit leerem Hinweistext.

Der Formular-**Submit** ist nicht betroffen (Page-Mode-POST → AccessGuard prüft
dort kein CSRF; der Controller prüft manuell aus dem Body — korrekt).

**Fix (klein):** `contact-form.js` sendet den Token zusätzlich als Header
`X-CSRF-Token` (eine Zeile im `fetch()`-Aufruf). Danach ist die **manuelle
CSRF-Prüfung in `checkAction` redundant** (AccessGuard deckt Fetch+POST bereits
ab) und kann raus. Live-Check danach: Blur auf gültigem Feld → kein Fehler;
ungültiges Feld → Meldung.

---

## 1. Controller-Code, der ins Framework gehört

`ContactController` (200 Zeilen) mischt projektspezifisches (Texte, Sektionen,
Wunsch-Liste) mit **generischer Formular-Mechanik**, die in jedem Projekt mit
öffentlichem Formular wieder gebraucht wird (Rule 8, module-agnostic bauen):

### 1a. Bot-/Missbrauchsschutz → Framework-Service `FormGuard` (Vorschlag)

Heute im Controller (rein session-basiert, null Projektlogik):

| Mechanik | Controller-Code heute |
|---|---|
| Time-Trap (Render-Zeitstempel, MIN_FILL_SECONDS) | `RENDERED_AT`, `isSubmittedTooFast()` |
| Rate-Limit pro Session (MAX_SENDS_PER_HOUR, Stundenfenster, pruning) | `SENDS`, `isRateLimited()`, `recordSend()`, `recentSends()` |
| PRG-Bestätigungs-Flag | `SENT_FLAG` set/get/reset |
| Honeypot-Auswertung | delegiert an `ContactForm::isHoneypotTripped()` (ok) |

Vorschlag: `Z77\Shared\Forms\FormGuard` (shared, kein DI-Singleton — per Key
instanziert wie `Mailer::create()`):

```php
$guard = FormGuard::forKey('contact');       // Session-Keys: form.contact.*
$guard->armTimeTrap();                       // beim Rendern
$guard->isTooFast(minSeconds: 3);            // beim Submit
$guard->isRateLimited(maxPerHour: 3);
$guard->recordSend();
$guard->markSent(); / $guard->consumeSent(); // PRG-Flag
```

Defaults (3 s / 3 pro Stunde) als Parameter-Defaults — keine Config-Datei
(Rule «Framework-Code minimal», kein Setting ohne zweiten Consumer).
Der Controller behält nur noch die Aufrufe → ~45 Zeilen Session-Mechanik
verschwinden aus dem Projekt.

### 1b. CSRF für Page-Mode-POST → einheitlicher Framework-Mechanismus

Die manuelle Prüfung `DI::getCsrfService()->validate((string) $request->getPostParameter('csrf_token'))`
existiert inzwischen an **drei** Stellen (zihlundsee `contactAction`, redundant
in `checkAction`, Framework `AdminPanelController::togglePartialLabelsAction`
seit PARTIAL-LABELS-002). AccessGuard deckt nur Fetch+POST (Header) ab.

Vorschlag (opt-in, kein Breaking Change): Attribut `#[Csrf]` auf der Action,
vom Dispatcher/AccessGuard erzwungen — liest bei Page-Mode den Body-Parameter
`csrf_token` (Konvention = Feldname, `$csrfToken` wird von
`AbstractBaseController::html()` ja bereits injiziert), bei Fetch weiterhin den
Header. Alternative (kleiner): Helper `$this->requireCsrf(): bool` im
`AbstractBaseController`. Empfehlung: Attribut — konsistent mit `#[Page]` /
`#[HttpMethod]` (ADR-006-Muster), deklarativ statt drei Handkopien.

### 1c. Blur-Check-Endpoint → generischer Controller-Trait (zweite Priorität)

`checkAction` ist ein generisches Muster: Feld-Whitelist → DTO aus einem Feld →
gemeinsamer Validator → `{valid, message}`-JSON. Als Trait
(`FieldCheckTrait::fieldCheck(formClass, validatorClass, allowedFields)`)
wird der Endpoint pro Projekt zum Einzeiler. Da es erst EINEN Consumer gibt,
ist das v2-Material — beim zweiten Formular-Projekt extrahieren, nicht jetzt
(kein Bau auf Vorrat). Das Muster gehört aber jetzt schon in die Doku
(`mail.md`/`fetch.md` see-also), damit es nicht neu erfunden wird.

**Bleibt richtig im Controller:** Texte/Sektionen/Kontext, Wunsch-Liste,
Feldset, Fehlermeldungs-Wortlaut, das Verdrahten der Bausteine.

---

## 2. Ownership From/To — Diskussion (Entscheid beim Owner)

Auftragsthese: «From und To gehören in die Obhut des Controllers, nicht in
emailConfig — spätere Controller haben andere Absender/Empfänger.»

### From: hier widerspreche ich — From gehört NICHT in den Controller

`From` liegt heute gar nicht in `emailConfig`, sondern als Installations-
Identität in `config/mail.inc.php` (`fromAddress`/`fromName`, `Mailer` füllt sie
als Default). Das ist technisch begründet: **SPF/DKIM/DMARC** verlangen, dass
die From-Domain zur sendenden Infrastruktur passt (cyon-Konto der Site). Ein
Controller, der beliebige Absender setzt, produziert Mails, die beim Empfänger
im Spam landen oder abgewiesen werden. Der variable «Absender» eines Formulars
ist korrekt als **Reply-To** modelliert (bereits pro Aufruf übergeben, validiert,
einziger user-kontrollierter Header). `EmailMessage::from()` bleibt als
bewusste Ausnahme für Sonderfälle bestehen (z.B. zweite verifizierte Identität
derselben Domain) — Default bleibt die Installations-Identität.

### To: beide Modelle tragen — Empfehlung: Hybrid, Regel präzisieren

| | Modell A — To im Controller | Modell B — To in emailConfig (heute) |
|---|---|---|
| Wer ändert den Empfänger? | Entwickler (Deploy) | Betreiber/Owner (Config-Override; v2: Backend-UI) |
| Mehrere Mail-Controller | jeder setzt sein `->to()` — direkt sichtbar am Ort des Geschehens | ein `forms`-Key pro Formular — zentral einsehbar |
| Dynamische Empfänger (Mail an eingeloggten User, Objekt-Verantwortlichen) | natürlich | passt nicht (Config kann keine Laufzeitdaten) |
| v2-Pendenz «Backend-editierbare Mail-Settings» (mail.md pending) | stirbt bzw. muss neu gedacht werden | ist genau dafür gebaut (`resolveFormSettings` = Attachment-Point) |

Wichtig: **Beide APIs existieren heute schon.** `send(EmailMessage)` gibt dem
Controller die volle Obhut (`->to()->subject()->template()`); `sendForm()` ist
die config-getriebene Bequemform. Die Auftragsthese ist also keine Umbau-Frage,
sondern eine **Regel-Frage**: welche der beiden Formen ist der Normalfall?

**Meine Empfehlung (Hybrid):**

- **Statische, betreiberdefinierte Empfänger** (Kontaktformular: «Client
  bestätigt die Adresse») → `sendForm()` + emailConfig. Ein Empfängerwechsel
  («neue Verwaltung übernimmt») ist dann ein Config-Edit bzw. ab v2 ein
  Backend-Klick — kein Deploy. Genau dieser Fall liegt bei zihlundsee vor.
- **Dynamische, datengetriebene Empfänger** (User-Bestätigung, Objekt-Owner,
  spätere Fakturen) → `send(EmailMessage)` mit `->to()` im Controller — der
  Controller weiss es, die Config kann es nicht wissen.
- `mail.md`-Rule entsprechend präzisieren (heute steht dort pauschal «MUST NOT
  hardcode recipient addresses in controllers» — das ist für den dynamischen
  Fall falsch formuliert und würde gegen die eigene `send(EmailMessage)`-API
  verstossen).

Entscheidet der Owner stattdessen konsequent Modell A (To immer im Controller):
`forms`-Map schrumpft auf `subject`/`template` oder fällt weg, `sendForm()`
bekommt `$to` als Pflichtparameter, und die v2-Pendenz (Backend-editierbare
Empfänger) ist zu streichen oder auf ein Entity-Lookup im Controller umzustellen.
Machbar — aber man gibt die Owner-Editierbarkeit auf. → **Owner-Entscheid nötig.**

---

## 3. zihlundsee — Kürze & Wiederverwendbarkeit

Gesamturteil: die Strecke ist bereits schlank und framework-nah gebaut
(`ContactForm` nutzt `ArrayMappable`, `ContactFormValidator` erbt
`EntityValidator`, Template escaped konsequent mit `e()`, JS ist transport-only
und begründet). Konkrete Verbesserungen:

1. **`ContactMailer` löschen** (33 Zeilen für einen Einzeiler). Die Klasse war
   der Seam für den Log-Fallback der Designphase — der ist entfernt, damit ist
   der Seam tot (YAGNI). `contactAction` ruft direkt
   `DI::getEmailService()->sendForm('contactForm', ['form' => $form], $form->getEmail())`.
   (Erledigt auch die mail.md-Pendenz «dead logFallback path».)
2. **`checkAction`: CSRF-Doppelprüfung entfernen** — nach dem Header-Fix aus
   Befund 0 prüft AccessGuard bereits; die manuelle Body-Prüfung fliegt raus.
3. **Bot-Schutz-Block durch `FormGuard` ersetzen** (nach 1a): Controller
   verliert 4 Konstanten + 4 private Methoden, ~45 Zeilen → Restgrösse ~120
   Zeilen, fast nur noch Seiteninhalt.
4. **Kein weiterer Extraktionsbedarf:** E-Mail-Body-Template (18 Zeilen,
   `data-str="new-line"`-Kontrakt korrekt), `contactForm.tpl.php` (projektiges
   Markup/BEM), Wish-Liste, Texte — bleiben Projekt. `frontendConfig`-Override
   als Vollkopie ist die bekannte CE-Eigenschaft (AUTH-B003-Klasse), Cache-Aus
   für `contact` ist korrekt und bleibt nötig (CSRF-Token/Formularzustand darf
   nie in den geteilten Cache — unabhängig vom neuen Admin-Bypass
   CACHE-ADMIN-001, der nur eingeloggte Admins betrifft).

Kleinigkeit im Framework (Kosmetik): `EmailService::sendForm()` lädt die Config
zweimal (`sendForm` + `resolveFormSettings`) — bei Gelegenheit auf einen
`getArrayConfig`-Aufruf zusammenziehen.

---

## Massnahmen — Stand nach Umsetzung 2026-07-18

| # | Massnahme | Status |
|---|---|---|
| 1 | **Bug-Fix CONTACT-CHECK-001**: `X-CSRF-Token`-Header in `contact-form.js` (Override + deployte `public/`-Varianten); manuelle CSRF-Prüfung aus `checkAction` entfernt | ✅ umgesetzt — **Live-Check Blur offen (Owner)** |
| 2 | Owner-Entscheid From/To: **From bleibt Installations-Identität** (späteres Kontrollsystem garantiert Domain-Konformität); **To hybrid** (statisch → Config/v2-Entity, dynamisch → `->to()` im Controller). `mail.md`-Rules präzisiert | ✅ entschieden + dokumentiert |
| 3 | `FormGuard` (`Z77\Shared\Forms`) gebaut; `ContactController` umgestellt (~45 Zeilen Session-Mechanik raus) | ✅ umgesetzt |
| 4 | `#[Csrf]`-Attribut (AccessGuard, Header ODER `csrf_token`-Body-Feld) + `AdminPanelController` umgestellt; `contactAction` behält die In-Action-Prüfung **bewusst** (freundliche Fehler-UX statt Reject — im Attribut-Docblock + security.md festgehalten) | ✅ umgesetzt (Abweichung dokumentiert) |
| 5 | `ContactMailer` gelöscht, Direktaufruf `sendForm()` im Controller | ✅ umgesetzt |
| 6 | `sendForm()` Config-Doppel-Load zusammengezogen | ✅ umgesetzt |
| 7 | Blur-Check-Trait: `Z77\Shared\Controller\PublicFormCheckTrait` — gebaut 2026-07-20 als Teil des Public-Form-Standards ([`public-form-bauplan.md`](public-form-bauplan.md)); `checkAction` ist jetzt ein Einzeiler, die prüfbaren Felder kommen aus der `FormDefinition` | ✅ umgesetzt |
| 8 | **v2-Bauplan To/Betreff** inkl. Owner-Anforderung Routing (Betreff-Auswahl → andere/mehrere Empfänger) + Kundenstamm-`ref:`-Seam: [`email-settings-v2-bauplan.md`](email-settings-v2-bauplan.md) | 📄 Entwurf — **wartet auf Owner-Freigabe** |

Doku nachgeführt: `mail.md` (From/To-Rules, Pendenzen, see-also FormGuard),
`security.md` (CSRF-Kontrakt + FormGuard-Abschnitt + Rules), `routing.md`
(`#[Csrf]` in der Attribut-Tabelle), Projekt `work/docs/topics/email.md`.
