# Bauplan — Public-Form-Standard: deklarative Formulare im Framework

**Status:** `[DONE]` — 2026-07-20 (freigegeben + umgesetzt; visueller Pass durch Owner offen, siehe `topics/forms.md` PUBLIC-FORM-001)
**Date:** 2026-07-20

**Abweichungen bei der Umsetzung** (bewusst, gegenüber dem freigegebenen Entwurf):

1. `PublicFormHandler::process()` liefert **`bool`** statt einer `RedirectResponse` —
   der Controller behält die Response-Hoheit (ADR-003, Rule 3). Der Redirect-URL ist
   damit kein Handler-Parameter mehr.
2. Das generische Mail-Body-Template liegt in **`kernel/shared`**
   (`emails/publicForm`, Namespace `Z77\Shared`) statt in `module-frontend` — der
   Mail-Layer ist shared, nicht frontend-spezifisch.
3. **Labels laufen durch `t()`** (Key ODER Literal), nicht nur die Fehlertexte —
   sonst wäre ein mehrsprachiges Formular halb übersetzt.
4. Die Doku bekam ein **eigenes Topic** [`../topics/forms.md`](../topics/forms.md)
   statt nur Ergänzungen in `mail.md`/`security.md`/`view-layer.md` (die drei
   verweisen jetzt darauf) — Formulare sind ein eigener Arbeitsbereich.
5. Zusätzlich: `--fe-danger`-Token (Formularfehler brauchen Farbe) und eine
   Korrektur der Cache-Config-Kommentare in `frontendConfig` (Keys sind
   URL-Segmente, nicht Klassennamen).
6. **Nachträglich (2026-07-22, PUBLIC-FORM-004):** Das PRG-Ziel ist eine **eigene
   Danke-Seite** (`thanksAction`), nicht die Formularseite mit Session-Flag. Der
   unten beschriebene Flag (`markSent()` / `consumeSent()` / `$sent` im
   `viewContext`) ist ersatzlos entfallen — «wurde abgeschickt» steht in der URL,
   nicht in der Session. Ebenso entfallen: der Erfolgs-Flash aus dem Handler. Der
   Handler **liefert Zustand und sendet nichts** — wer einen Flash will, setzt ihn
   im Controller neben dem Redirect. Massgeblich ist [`../topics/forms.md`](../topics/forms.md).

Ziel: Ein öffentliches Formular (Kontakt-Klasse) besteht projektseitig nur noch aus
**Felddeklaration + Template**. Feldmechanik, Validierung, Missbrauchsschutz,
Submit-Flow und Blur-Endpoint liegen im Framework. Das Framework liefert dazu ein
lauffähiges **Referenzformular** als Standard, an dem sich neue Projekte
orientieren.

Owner-Vorgaben 2026-07-20:

1. Im Framework soll ein **Beispiel** stehen (Standard festlegen).
2. **Check und Validierung** werden vom Framework vorgenommen.
3. Das **Override legt nur** die zu validierenden Felder und das Template fest.
4. Die Validierung läuft über das Framework, das **Ergebnis geht ans Override**.
5. Das **Override gibt das OK** für den Versand.

## Ausgangslage

Der zihlundsee-`ContactController` ist der einzige Konsument von
`EmailService::sendForm()` und trägt den ganzen Boilerplate:

| Datei | Zeilen | Was |
|---|---|---|
| `ContactForm.php` | 81 | DTO, 9 Getter/Setter-Paare, Honeypot-Prüfung, Options-Konstante |
| `ContactFormValidator.php` | 69 | 7 `validate*()`-Methoden, fast nur `notEmpty/minLength/maxLength/isEmail` |
| `ContactController.php` | 161 | Submit-Kaskade (24 Z.), `checkAction` (20 Z.), Seiteninhalt (34 Z.) |
| `contact-form.js` | 113 | Blur-Transport, Selektoren an `zs-cform__*` gekoppelt |
| `partials/emails/contactForm.tpl.php` | 18 | Mail-Body, feldweise von Hand |

Bereits extrahiert (Review [`review-email-service-usage.md`](review-email-service-usage.md)
§1a/§3): `FormGuard` (Session-Mechanik), `ContactMailer` gelöscht,
CSRF-Doppelprüfung entfernt. Massnahme 7 («Blur-Check-Trait») war bewusst aufs
zweite Formular-Projekt vertagt — dieser Bauplan löst sie ein.

## Architektur

Alles Neue liegt in `packages/kernel/shared/src/Forms/` neben dem bestehenden
`FormGuard` (**unverändert** — er bleibt die Session-Mechanik, die der Handler
benutzt).

```text
FormDefinition   (abstract, Projekt erbt)  →  welche Felder, welcher emailConfig-Key
   ↓
PublicForm       (generisches DTO)         →  Werte aus dem POST, Labels, Options
   ↓
PublicFormValidator extends EntityValidator →  Regeln aus der Deklaration
   ↓
PublicFormHandler                          →  CSRF → Bot → Validate → Limit → Send → PRG
   ↓
PublicFormCheckTrait                       →  Blur-Endpoint (JSON), gleiche Regeln
```

### 1. `FormDefinition` — das Einzige, was ein Projekt schreibt

```php
abstract class FormDefinition
{
    /** @return array<string,array> feldname (snake_case = HTML input name) => spec */
    abstract public function fields(): array;

    /** emailConfig `forms` key — Empfänger/Betreff/Mail-Template liegen dort. */
    abstract public function formKey(): string;

    public function guardKey(): string      { return $this->formKey(); }
    public function honeypots(): array      { return ['website', 'fax']; }
    public function replyToField(): ?string { return 'email'; }   // → sendForm($replyTo)
    public function routeField(): ?string   { return null; }      // → sendForm($routeKey)
}
```

Feld-Spec:

| Schlüssel | Pflicht | Bedeutung |
|---|---|---|
| `label` | ja | Anzeigename — Template-Label, Fehlertext, E-Mail-Zeile |
| `type` | nein | `text` (default) \| `email` \| `tel` \| `textarea` \| `radio` \| `checkbox` |
| `options` | bei `radio` | `wert => label`; der Wert wird implizit gegen diese Schlüssel validiert |
| `rules` | nein | siehe Regel-Set |
| `autocomplete` | nein | HTML-Attribut fürs Template |

**Regel-Set (final, bewusst klein):**

| Regel | Wirkung | Übersetzungs-Key |
|---|---|---|
| `'required' => true` | nicht leer | `form.error.required` |
| `'min' => N` | Mindestlänge | `form.error.min` |
| `'max' => N` | Maximallänge (auch `maxlength` im Template) | `form.error.max` |
| `'email' => true` | E-Mail-Format | `form.error.email` |
| `'accepted' => true` | Checkbox muss gesetzt sein (Consent) | `form.error.accepted` |
| — | Options-Zugehörigkeit (implizit bei `options`) | `form.error.option` |

Regeln sind ein **assoziatives Array** (`['required' => true, 'min' => 2]`), kein
String-Format (`'min:2'`) — kein Parser im Framework nötig, typsicher, IDE-lesbar
(Owner-Entscheid 2026-07-20).

Beispiel (zihlundsee, ersetzt DTO **und** Validator):

```php
final class ContactFormDefinition extends FormDefinition
{
    public function formKey(): string { return 'contactForm'; }

    public function fields(): array
    {
        return [
            'wish' => [
                'label'   => 'Wunsch',
                'type'    => 'radio',
                'options' => [
                    '1.5' => '1.5-Zimmer-Wohnung',
                    '2.5' => '2.5-Zimmer-Wohnung',
                    '3.5' => '3.5-Zimmer-Wohnung',
                    '4.5' => '4.5-Zimmer-Wohnung',
                ],
                'rules'   => ['required' => true],
            ],
            'first_name' => ['label' => 'Vorname', 'autocomplete' => 'given-name',
                             'rules' => ['required' => true, 'min' => 2, 'max' => 80]],
            'name'       => ['label' => 'Name', 'autocomplete' => 'family-name',
                             'rules' => ['required' => true, 'min' => 2, 'max' => 80]],
            'email'      => ['label' => 'E-Mail', 'type' => 'email', 'autocomplete' => 'email',
                             'rules' => ['required' => true, 'email' => true]],
            'mobile'     => ['label' => 'Mobile', 'type' => 'tel', 'autocomplete' => 'tel',
                             'rules' => ['required' => true, 'min' => 10, 'max' => 30]],
            'message'    => ['label' => 'Meine Mitteilung bzw. Frage', 'type' => 'textarea',
                             'rules' => ['required' => true, 'min' => 10, 'max' => 4000]],
            'privacy'    => ['label' => 'Datenschutzerklärung', 'type' => 'checkbox',
                             'rules' => ['accepted' => true]],
        ];
    }
}
```

Zwei projektseitige Klassen (150 Zeilen) → eine Deklaration (~40 Zeilen).

### Fehlertexte — sprachabhängig (Owner-Entscheid 2026-07-20)

Ein öffentliches Formular steht in einer mehrsprachigen Umgebung
(`frontendConfig.languages`), also **müssen die Fehlertexte übersetzbar sein**.
Zwei Ebenen:

1. **Standardtexte** kommen aus dem bestehenden Übersetzungsmechanismus:
   `t('form.error.required', ['label' => 'Vorname'])` — `Translator::t()` kann
   `{platzhalter}` ersetzen (`packages/kernel/core/src/Services/Translator.php:33`).
   Die Keys werden in `de/en/fr.default.json`
   (`packages/kernel/core/data/framework/i18n/`) mitgeliefert, z.B.
   `"form.error.required": "{label} ist ein Pflichtfeld"`. Damit ist ein Text auch
   im Backend-Übersetzungseditor pflegbar (TRANS-TOOL-001), ohne Deploy.
2. **`messages`-Key pro Feld** überschreibt einzelne Regeln, wenn ein Formular
   einen eigenen Wortlaut braucht. Der Wert ist ein **Übersetzungs-Key** (ein
   nicht gefundener Key käme wörtlich durch, deshalb: Keys, keine Literale):

   ```php
   'wish' => [
       'label'    => 'Wunsch',
       'type'     => 'radio',
       'options'  => [ /* … */ ],
       'rules'    => ['required' => true],
       'messages' => ['required' => 'contact.error.wish'],   // → «Bitte wählen Sie eine Wohnungsgrösse.»
   ],
   ```

   Projektspezifische Keys leben im Projekt-Wörterbuch — zihlundsee behält seinen
   Wortlaut damit exakt.

**Konsequenz für `EntityValidator`:** dessen Fluent-Prüfer erzeugen fest
verdrahtete deutsche Texte (`EntityValidator.php:77-110`). Der
`PublicFormValidator` erbt zwar die **Fehler-Infrastruktur** (`isValid(?array
$only)`, `addFieldError()`, `getFieldErrors()`), führt die Regeln aber mit eigenen,
`t()`-basierten Texten aus. `EntityValidator` bleibt **unverändert** — er bedient
die einsprachigen Backend-Entities; ein Umbau dort wäre ein eigener Vorgang mit
eigenem Risiko. Der Preis ist eine kleine Duplikation der Prüf-Primitive
(`mb_strlen`, `filter_var` — zusammen ~20 Zeilen); bewusst in Kauf genommen,
Alternative wäre ein Message-Parameter quer durch alle Backend-Validatoren.

### 2. `PublicForm` — generisches DTO

- `PublicForm::fromPost(FormDefinition $def, array $post): self` — nimmt nur
  deklarierte Felder + Honeypots an (heutiges `array_intersect_key`-Verhalten),
  trimmt, `type: email` → lowercase, `type: checkbox` → bool
- `get(string $f): string`, `all(): array`, `mapToArray(): array` (EntityValidator-Kontrakt)
- `label(string $f): string`, `display(string $f): string` (Options-Label — ersetzt
  `getWishLabel()`), `options(string $f): array`
- `isChecked(string $f, ?string $value = null): bool` (Checkbox + Radio-Vorauswahl)
- `isHoneypotTripped(): bool`
- `definition(): FormDefinition`

Keine typisierten Getter mehr — Preis der Deklaration. Templates greifen per
Feldname zu, was den generischen Mail-Body und das generische Partial erst möglich
macht.

### 3. `PublicFormValidator extends EntityValidator`

`executeValidation(?array $only)` wird überschrieben und läuft die Regeln der
Deklaration ab. Von `EntityValidator` kommt die **Fehler-Infrastruktur**
(`isValid(?array $only)`, `addFieldError()`, `getFieldErrors()`,
`getFieldError()`); die Regelprüfungen selbst liegen lokal, weil die Fehlertexte
übersetzbar sein müssen (siehe «Fehlertexte»).

`isValid(?array $only)` bleibt unverändert → **Blur-Check und Submit benutzen exakt
dieselben Regeln** (heutiges Verhalten, bleibt erhalten).

### 4. `PublicFormHandler` — der Flow

Kapselt die Kaskade aus `ContactController::contactAction` in **derselben
Reihenfolge und mit demselben Verhalten**:

```text
POST?
 ├─ CSRF ungültig            → formError (freundlicher Re-Render, kein Reject)
 ├─ Honeypot ODER zu schnell → markSent() + Redirect        (Fake-Erfolg, kein Mail)
 ├─ Validierung fehlgeschlagen → errors + Werte behalten
 ├─ Rate-Limit erreicht      → formError
 ├─ onValid-Callback / sendForm() == true → recordSend() + markSent() + Redirect
 └─ sonst                    → formError
GET / Re-Render:
 consumeSent() → $sent ; armTimeTrap()
```

```php
$handler = PublicFormHandler::for(new ContactFormDefinition(), localizedUrl('/kontakt'));

// null = rendern; RedirectResponse = PRG (echter Erfolg ODER Bot-Fake-Erfolg)
if ($redirect = $handler->process()) {
    return $redirect;
}

return $this->html(['pageTitle' => 'Kontakt'] + $handler->viewContext() + [ /* Seiteninhalt */ ]);
```

- **`process()` ohne Callback** → Versand über `DI::getEmailService()->sendForm()`
  mit `formKey()`, Kontext `['form' => $form]`, Reply-To aus `replyToField()`,
  Route-Key aus `routeField()` (beides validierte Formularwerte — die
  `mail.md`-Rule zu `routeKey` bleibt eingehalten).
- **`process($onValid)`** → Owner-Vorgabe 5: das Override bekommt das validierte
  `PublicForm` und gibt das OK (`true` = erledigt → PRG, `false` = generische
  Fehlermeldung). Damit ist auch der dynamische Empfängerfall der `mail.md`-Rule
  (`send(EmailMessage)` mit `->to()`) abgedeckt, ohne den Flow zu duplizieren.
- **`viewContext()`** → `['form', 'fields', 'errors', 'formError', 'sent', 'checkUrl']`
  — dieselben Keys wie heute, Templates ändern sich minimal. `checkUrl` wird aus
  dem aktuellen Request abgeleitet (`/{module}/{group}/{controller}/check`, via
  `Naming`), per Parameter überschreibbar.
- **Fehlertexte** (CSRF abgelaufen / Versandfehler) sind Defaults am Handler und
  pro Instanz überschreibbar — die heutigen zihlundsee-Texte bleiben so erhalten.
- **CSRF** bleibt die In-Action-Prüfung mit freundlichem Re-Render (die in
  `security.md` bewusst dokumentierte Abweichung vom `#[Csrf]`-Attribut).
- Kein DI-Singleton — `for()` wie `Mailer::create()` / `FormGuard::forKey()`
  (Placement-Entscheid B).

### 5. `PublicFormCheckTrait` — Blur-Endpoint

Nach `Z77\Shared\Controller\` (neben `RouteInfoTrait`). Ersetzt `checkAction` 1:1
(405 bei GET, 400 bei unbekanntem Feld, `{valid, message}`, **kein** In-Action-CSRF
— `AccessGuard` prüft den Header, CONTACT-CHECK-001):

```php
protected function checkAction(): JsonResponse
{
    return $this->blurCheck(new ContactFormDefinition());
}
```

Die zulässigen Felder kommen aus der Deklaration — die heutige `CHECKABLE`-Konstante
entfällt ersatzlos. Erledigt Massnahme 7 des Reviews.

## Referenzformular im `module-frontend`

`IndexController::contactAction` ist heute ein leerer Platzhalter
(`packages/module-frontend/src/Ui/Controllers/Main/IndexController.php:35`) und wird
das lauffähige Beispiel:

| Datei | Inhalt |
|---|---|
| `src/Ui/Form/ContactFormDefinition.php` | Standard-Deklaration: Name, E-Mail, Telefon, Nachricht, Consent |
| `src/Ui/Controllers/Main/IndexController.php` | `contactAction` (Handler + Render) + `checkAction`; `use PublicFormCheckTrait` |
| `res/view/templates/partials/publicForm.tpl.php` | generisches Formular-Partial aus `$fields`: Rows, Labels, Hints (`aria-live`), `aria-invalid`, Honeypots, `csrf_token`, Submit |
| `res/view/templates/partials/emails/publicForm.tpl.php` | generischer Mail-Body: Tabelle über die Felder mit `<tr data-str="new-line">` (HtmlToText-Kontrakt) |
| `res/assets/js/public-form.js` | Generalisierung von `contact-form.js`: identische Logik, Selektoren über data-Attribute |
| `res/scss/components/_public-form.scss` | minimales token-basiertes Styling + Import in `base.scss` |
| `src/App/Config/frontendConfig.inc.php` | Page-Cache für die contact-Route aus (CSRF-Token/Formularzustand nie im geteilten Cache) |
| `packages/kernel/shared/src/Config/emailConfig.inc.php` | Form-Key `contactForm` nach der seed-address-Konvention |
| `packages/kernel/core/data/framework/i18n/{de,en,fr}.default.json` | `form.error.*`-Keys für die Standard-Fehlertexte |

**Markup-Kontrakt (data-Attribute statt CSS-Klassen)** — damit ein Projekt sein
eigenes Markup/BEM behalten kann und das Framework-JS trotzdem funktioniert:

| Attribut | Am Element | Zweck |
|---|---|---|
| `data-public-form` | `<form>` | JS-Einstieg |
| `data-check-url` | `<form>` | Blur-Endpoint |
| `data-validate` | Input/Textarea | dieses Feld blur-prüfen |
| `data-form-row` | Zeilen-Container | Fehlerklasse setzen |
| `data-hint-for="{feld}"` | Hint-Element | Fehlertext einsetzen |
| `data-error-class` | `<form>` (optional) | Klassenname für den Fehlerzustand (default `is-error`) |

JS-Budget (Rule 7): reiner Transport, keine Validierungslogik im Client; das
Formular funktioniert ohne JS vollständig (Submit → Server-Re-Render mit Fehlern).
`novalidate` bleibt gesetzt (konsistente eigene Fehlerdarstellung).

## Migration zihlundsee

Verhalten bleibt **1:1** (Markup, Texte, Bot-Schutz, PRG, a11y).

| Datei | Aktion |
|---|---|
| `override/.../Ui/Form/ContactForm.php` | **gelöscht** → `ContactFormDefinition` |
| `override/.../Ui/Form/ContactFormValidator.php` | **gelöscht** |
| `override/.../Controllers/Main/ContactController.php` | `contactAction` → Handler + Seiteninhalt; `checkAction` → Trait-Aufruf |
| `override/.../partials/contactForm.tpl.php` | bleibt Projekt-Markup (`zs-cform`-BEM, zwei Panels), liest `$fields`/`$form->get()` und setzt die data-Attribute |
| `override/.../partials/emails/contactForm.tpl.php` | **gelöscht** — generisches Framework-Body-Template |
| `override/.../assets/js/contact-form.js` | **gelöscht** — Framework-`public-form.js` (auch die deployte `public/`-Variante) |
| `override/.../emailConfig.inc.php` | `template` zeigt aufs Framework-Partial |

Erwartung: Controller 161 → ~90 Zeilen (fast nur noch Seiteninhalt), zwei Klassen,
ein JS und ein Mail-Template verschwinden.

## Bewusst NICHT

- **Kein Markup-Generator aus Config.** Das Partial rendert die Felder, aber das
  Layout/BEM bleibt Template-Sache — Projekte überschreiben das Partial (CE-Prinzip).
- **Keine Client-Validierung.** JS bleibt Transport (Rule 7); der Server ist die
  einzige Wahrheit.
- **Keine Regel-DSL über das Set oben hinaus.** Neue Regeln erst, wenn ein echtes
  Formular sie braucht (N=1-Risiko, siehe unten).
- **Keine Mehrschritt-Formulare, kein Datei-Upload, keine Formular-Persistenz.**
- **Keine Änderung an `FormGuard`, `EmailService`, `Mailer`** — der Handler ist
  reiner Konsument.

## Risiken

- **N=1-Abstraktion.** Der Standard wird an genau einem echten Formular validiert.
  Gegenmassnahmen: minimales Regel-Set, kein Markup-Generator, Projekt-Template
  frei überschreibbar, Handler-Callback als Ausstieg für Sonderfälle.
- **Regression zihlundsee-Optik.** Das Projekt-Partial wird auf `$fields`/data-
  Attribute umgebaut — visueller Vergleich ist Teil der Akzeptanz.
- **Zwei Fehlertext-Quellen.** Frontend-Formulare über `t()`, Backend-Entities
  weiterhin über die festen `EntityValidator`-Texte. Bewusst (siehe
  «Fehlertexte»); wird zum Thema, wenn das Backend je mehrsprachig wird.

## Akzeptanz

1. **CLI-Harness** (Muster der 24 Checks aus `MAIL-V2-001`): `fromPost`
   (Feldfilter, Trim, Lowercase-Mail, Checkbox-Bool), Regel-Matrix
   (`required`/`min`/`max`/`email`/`accepted`/Options), `isValid(['email'])`
   == Submit-Regeln, Honeypot, Handler-Kaskade gegen Fake-Transport inkl.
   Bot-Fake-Erfolg, CSRF-Fehlpfad, Rate-Limit, `process($onValid)`-Callback,
   Fehlertexte pro Sprache (`de`/`fr`) + `messages`-Override.
2. **Framework-Referenzformular im Browser:** `/contact` rendert, Blur-Check sendet
   `X-CSRF-Token`, fehlerhafter Submit → Re-Render mit erhaltenen Werten, gültiger
   Submit → PRG + Bestätigung.
3. **zihlundsee-Regression:** Kontaktseite optisch identisch, Blur-Hints, Fehler-
   Re-Render, PRG-Bestätigung, Honeypot- und Zeit-Trap-Submit → Fake-Erfolg ohne
   Mail. Lokal endet der Versand erwartungsgemäss im `false`-Pfad (kein MTA) — die
   echte Zustellung bleibt der offene cyon-Check aus `mail.md`.
4. `npm run docs:check` grün (file-map-Zeilen für alle neuen Dateien;
   `mail.md`/`security.md`/`view-layer.md` nachgeführt, Review-Massnahme 7 auf ✅).
