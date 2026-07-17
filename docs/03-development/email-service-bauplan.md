# Bauplan — EmailService (Template-Mails auf dem bestehenden Mail-Stack)

**Status:** `[BUILT]` — 2026-07-17, alle Phasen umgesetzt und lokal verifiziert (33-Check-
Harness mit Fake-Transport + Live-Formular-Flow). Offen: cyon-Echtversand (Akzeptanz 1+2)
und danach `logFallback`-Entfernung (Akzeptanz 4); Release als Teil von **1.2.0**.
**Date:** 2026-07-17

Ziel: Framework-EmailService in `kernel/shared`, der template-basierte Multipart-Mails
(HTML + daraus abgeleiteter Plain-Text) rendert und versendet. Erster Konsument: das
Kontaktformular zihlundsee.ch (bereits gebaut, ruft die API über einen Seam auf);
spätere Konsumenten: beliebige Formular-Mails, System-Notifications.

Requirements-Handoff der Projekt-Session:
`{projekt}/work/docs/topics/email.md` (zihlundsee, 2026-07-17) — dort ist die
Framework-Antwort mit den Abweichungen ergänzt.

## Owner-Entscheide (2026-07-17)

| Frage | Entscheid |
|---|---|
| Neuer Stack nach wdv-Vorbild? | **Nein.** Der bestehende Mail-Stack (`Z77\Shared\Mail`: `Message`/`MimeMessage`/`SmtpTransport`/`Mailer`, ADR-016, e2e-getestet) wird orchestriert, nicht dupliziert. Das ist der in [`mail.md`](../topics/mail.md) angekündigte finale Mail-Service-Schritt. |
| Namespace | `Z77\Shared\Mail` — **nicht** `Z77\Shared\Email` wie im Handoff. Ein Mail-Bereich, ein Topic. Kostet 1 Zeile im Projekt-Seam (`class_exists`-String in `ContactMailer.php`). |
| Config-Split | Infrastruktur (`transport mail\|smtp`, `enabled`, `fromAddress`, `fromName`, SMTP-Params) in `config/mail.inc.php` (seed-once). App-Ebene (`subjectPrefix`, `replyTo`-Default, `forms`-Map) im neuen `emailConfig` (override-first). Kein `from`/`transport` im emailConfig (Regel 2, keine Duplikate). |
| Installer-Seed | `writeMailConfig()` seed-once mit `enabled=true`, `transport='mail'` — mail() braucht keine Credentials, cyon-tauglich; Clean Install kann sofort senden. |
| Adressen Backend-editierbar? | **v2, nicht jetzt.** Die Auflösung `formKey → Einstellungen` ist im Service in EINEM Resolver gekapselt; später überschreibt eine File-Persistence-Entity (Backend-Editor, Sektion «Service») die Config — Config bleibt Seed/Fallback (Muster `navigation.default.json`). API/Templates/Konsumenten bleiben dabei unverändert. |
| Body-Encoding | base64 (bestehendes `MimeMessage`) statt quoted-printable aus wdv — RFC-äquivalent, bereits getestet. |

## Abweichungen vom Handoff (für die Projekt-Session)

| Handoff | Umsetzung |
|---|---|
| `Z77\Shared\Email\EmailService` / `EmailMessage` | `Z77\Shared\Mail\EmailService` / `EmailMessage` → Seam-Anpassung (1 Zeile) |
| emailConfig mit `transport`/`from`/`fromName` | wandern nach `config/mail.inc.php`; emailConfig behält `subjectPrefix`, `replyTo`, `forms` |
| quoted-printable Bodies | base64 (MimeMessage, bestehend) |
| «TemplateRenderer» | existiert nicht — Rendering via Closure-Scope (Muster `StylesheetManager::createCss`) |
| Reply-To-Validierung «EntityValidator isEmail» | `filter_var(FILTER_VALIDATE_EMAIL)` direkt (identische Semantik, kein Entity-Kontext nötig); ungültig → still verwerfen |
| CR/LF-Stripping der Header (wdv) | stärker: `Message`-VO **wirft** bei CR/LF bzw. ungültiger Adresse (bestehende Injection-Abwehr an der VO-Grenze) |

## Architektur

Alle neuen Bausteine in `packages/kernel/shared/src/Mail/`:

| Baustein | Zweck |
|---|---|
| `PhpMailTransport` | `MailTransport`-Implementation über PHP `mail()`. Zerlegt den gebauten RFC-5322-Blob am ersten CRLFCRLF in Header/Body, extrahiert `To`/`Subject` (setzt `mail()` selbst), übergibt die restlichen Header als additional_headers; Envelope-Recipients ohne To/Cc-Header → `Bcc`-Header (sendmail verarbeitet ihn); Envelope-Sender via `-f` (Adresse ist durch `Message` validiert). |
| `HtmlToText` | Port von wdv `preparePlainText()`: style/title entfernen, Whitespace-Kollaps, `<tr data-str="new-line">`/`</p>`/`</h*>`/`</li>`/`<br>` → Zeilenumbruch, `</a>` → Space, strip_tags, Entity-Decode, NBSP-Normalisierung, optionale Replacement-Map (Konstruktor-Param, Default `[]`). Reine Funktion, isoliert testbar. |
| `EmailMessage` | Dünner fluenter Builder: `to/from/cc/replyTo/subject`, `template(string $tpl, string $nameSpace, array $context)`, `attach(string $absPath, string $fileName)`. Hält nur Daten — Rendering passiert erst im Service. `;`→`,`-Normalisierung für Mehrfach-Empfänger-Strings beim Übernehmen. |
| `EmailService` | DI-Shared-Service (`DI::getEmailService()`, Registrierung im Bootstrap). `send(EmailMessage): bool` + `getLastErrors(): array`; `sendForm(string $formKey, array $context, ?string $replyTo): bool`. |

### Ablauf `send(EmailMessage)`

```text
EmailService::send(EmailMessage)
  → Body-Template via FileFinder::getFirstTplMatch (override-first) rendern (Closure-Scope, e() verfügbar)
  → in Layout-Template `emails/layout` (Z77\Shared, override-first) einsetzen → $htmlBody
  → HtmlToText → $textBody
  → Message (bestehende VO): from/to/cc/replyTo/subject/text/html/attach
      Reply-To: vorher filter_var-validieren, ungültig → still weglassen
      Attachments: Datei lesen → bestehendes Attachment-VO (mime via mime_content_type)
  → Mailer::create()->send(Message)
      transport 'mail'  → PhpMailTransport
      transport 'smtp'  → SmtpTransport (bestehend)
  → RuntimeException (unkonfiguriert / Transport-Fehler) → catch: false + getLastErrors() + error_log
  → Programmierfehler (fehlendes Template, unbekannter formKey) → wirft weiterhin
```

### Ablauf `sendForm(formKey, context, replyTo)`

```text
sendForm('contactForm', ['form' => $form], $form->getEmail())
  → Resolver: forms[$formKey] aus emailConfig (unbekannter Key → RuntimeException)
  → EmailMessage: to/subject (+ subjectPrefix)/template aus den Form-Settings,
    replyTo aus dem Call-Parameter (fällt auf emailConfig 'replyTo' zurück)
  → send(...)
```

Der Resolver ist die EINZIGE Stelle, die Form-Settings liest → v2-Entity dockt hier an.

### Mailer-Erweiterung (bestehende Klasse, minimal)

`Mailer::create()` liest neu `transport` aus `config/mail` (`'smtp'` = Default, bestehendes
Verhalten unverändert): `'mail'` → `PhpMailTransport` ohne Credential-Zwang. `enabled`
bleibt der Master-Schalter (false → unkonfiguriert → `send()` wirft, EmailService fängt).

## Config

### `config/mail.inc.php` — Infrastruktur (RUNTIME, seed-once via Installer)

```php
return [
    'enabled'     => true,
    'transport'   => 'mail',          // 'mail' (PHP mail(), Phase 1) | 'smtp'
    'fromAddress' => 'noreply@example.ch',
    'fromName'    => '',
    // nur für transport 'smtp':
    'host' => '', 'port' => 587, 'encryption' => 'tls',
    'username' => '', 'password' => '', 'timeout' => 15, 'heloHost' => 'localhost',
];
```

### `emailConfig.inc.php` — App-Ebene (override-first)

Framework-Default: `packages/kernel/shared/src/Config/emailConfig.inc.php` (leere `forms`).
Projekt-Override: `override/z77/shared/src/Config/emailConfig.inc.php`:

```php
return [
    'subjectPrefix' => '[zihlundsee.ch] ',
    'replyTo'       => null,          // Default; sendForm()-Parameter gewinnt
    'forms' => [
        'contactForm' => [
            'to'       => 'zihlundsee@sihlestate.ch',   // vom Kunden zu bestätigen
            'subject'  => 'Neue Anfrage über das Kontaktformular',
            'template' => ['partials/emails/contactForm', 'Z77\\Module\\Frontend'],
        ],
    ],
];
```

## Templates

- **Layout:** `packages/kernel/shared/res/view/templates/emails/layout.tpl.php` —
  HTML-Skelett (tabellenbasiert, Inline-CSS-freundlich), rendert `$emailBody` in den
  Content-Slot. Override-first automatisch (`override/z77/shared` vor `vendor`).
- **Body:** pro Mail, override-first via FileFinder mit dem im Config genannten Namespace.
  Der Kontaktformular-Body existiert bereits im Projekt
  (`override/…/partials/emails/contactForm.tpl.php`, nutzt `<tr data-str="new-line">`).
- Escaping: `e()` wie überall — Context-Werte sind User-Input.
- Konvention `<tr data-str="new-line">` für die Plain-Text-Ableitung im
  Layout-Template-Header dokumentieren.

## Sicherheit (Handoff-Checkliste → Umsetzung)

- Header-Injection: `Message`-VO wirft bei CR/LF / ungültiger Adresse (stärker als
  wdv-Stripping). Adressen aus Config; einziger User-Input-Header ist Reply-To →
  `filter_var`, ungültig still verwerfen.
- Subject: RFC 2047 B-encoded via `MimeMessage` (ASCII pass-through — konform).
- Kein User-Input in Template-Pfaden: `formKey` ist reiner Config-Lookup, unbekannt → Exception.
- `mail()` `-f`-Parameter: Adresse stammt aus `Message::getFromAddress()` (validiert);
  PHP escaped additional_params intern (`escapeshellcmd`).
- Fehler serverseitig loggen: `error_log` → `logs/php-error.log` (bestehendes Muster).

## Doku-Pflichten (im gleichen Zug)

1. [`mail.md`](../topics/mail.md): neue Bausteine in entry/file map/mental model/flow/rules;
   `RUNTIME=/skeleton/config/mail.inc.php` wieder in die file map (Datei existiert dann);
   known issue «finalised LAST» auflösen; v2-Entity als pending.
2. `installer.md`: `writeMailConfig()` in der execute()-Tabelle.
3. Handoff `work/docs/topics/email.md` (Projekt): Status auf BUILT, Deltas vermerkt (erledigt).
4. `npm run docs:check` grün.

Kein neuer ADR — ADR-016 (eigener Mail-Stack) deckt den Bereich; der mail()-Transport ist
eine Transport-Implementation innerhalb dieser Entscheidung (in mail.md dokumentiert).

## Umsetzungs-Phasen

- [x] **P1 Kernel:** `PhpMailTransport`, `HtmlToText`, `EmailMessage`, `EmailService`
      (send/sendForm/Resolver), `Mailer`-Transport-Switch, emailConfig-Default,
      Layout-Template, DI-Registrierung im Bootstrap
- [x] **P2 Installer:** `writeMailConfig()` seed-once (`enabled=true`, `transport='mail'`)
- [x] **P3 Projekt-Integration (zihlundsee):** Seam-`class_exists` auf
      `Z77\Shared\Mail\EmailService` + emailConfig-Override (durch die Projekt-Session
      vorbereitet), `config/mail.inc.php` mit zihlundsee-Absender angelegt.
      Offen bis cyon: Echtversand (Akzeptanz 1–2), danach `logFallback` entfernen (Akzeptanz 4)
- [x] **P4 Doku:** mail.md (entry/file map/mental model/flow/rules/known issues/pending),
      installer.md (`writeMailConfig()`), Handoff-Status; docs:check grün
- [x] **P5 Verifikation (lokal):** 33-Check-Harness mit Fake-Transport gegen die ECHTEN
      Projekt-Templates/-Configs — multipart/alternative, RFC-2047-Subject mit Prefix,
      Reply-To (inkl. Injection-Versuch → still verworfen), Plain-Text-Ableitung zeilenweise,
      Escaping, Text-only-Mail, Attachment (multipart/mixed), `mail()`-Arg-Splitting mit
      Bcc-Rest, unbekannter formKey wirft, Transportfehler/unkonfiguriert → `false` +
      `getLastErrors()`. Live-Submit Kontaktformular (Dev-Server): erwartetes `false` (kein
      MTA auf Windows), generische Formular-Fehlermeldung, Log-Eintrag — Fehlerpfad sauber.
      **Release: zusammen mit dem `.min.js`-Fallback als 1.2.0** nach cyon-Echtversand-Check.

## Akzeptanz (aus dem Handoff)

1. `composer update` im Projekt → Formular-Submit sendet echte Mail an den konfigurierten
   Empfänger, Reply-To = Absender.
2. Plain-Text-Teil lesbar (Labels + Werte mit Zeilenumbrüchen).
3. `sendForm` liefert `false` (+ Log) wenn der Versand scheitert; das Formular zeigt seine
   generische Fehlermeldung (bereits verdrahtet).
4. `logFallback`-Pfad im `ContactMailer` danach entfernt.

## Bewusst NICHT in v1

- **Entity + Backend-Editor** für Empfänger/Absender (v2): File-Persistence-Entity,
  cachable, Editor unter Sektion «Service»; Entity überschreibt Config, Config bleibt
  Seed/Fallback. Der gekapselte Resolver ist die vorbereitete Andockstelle.
- SMTP-Go-live im Projekt (Transport bleibt `mail`; `smtp` ist vorhanden und konfigurierbar).
- Queue/async, Bounce-Handling, Mail-Log-UI.
- Quoted-printable (base64 bleibt).
