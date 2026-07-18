# Bauplan — EmailService v2: Backend-editierbare Empfänger/Betreff + Routing

**Status:** `[DONE]` — 2026-07-18 (freigegeben + umgesetzt; visueller UI-Pass durch Owner offen, siehe P3)
**Date:** 2026-07-18

Ziel: `to` und `subject` eines Formular-Mails werden **im Backend editierbar**
(heute: Code-Deploy via emailConfig-Override). Zusätzlich — Owner-Anforderung
2026-07-18 — **empfängerseitiges Routing**: ein Formular kann fix an ein Ziel
senden, ODER abhängig von einer im Formular gewählten Option (z.B. Betreff-
Auswahl) an unterschiedliche bzw. **mehrere** Empfänger. Später kommen die
Empfänger aus einem **Kundenstamm** — v2 legt den Seam dafür an, baut ihn aber
nicht.

Grundsatz bleibt (Review §2, Owner-Entscheid 2026-07-18): `from` ist die
Installations-Identität aus `config/mail.inc.php` (SPF/DKIM/DMARC) — nicht Teil
dieses Bauplans; ein späteres Kontrollsystem garantiert Domain-Konformität.

## Architektur

### Datenmodell — Entity `EmailFormSetting` (file persistence)

`data/framework/mail/emailFormSettings.json`, ein Datensatz pro `form_key`
(Entity-Muster wie `navigation.default.json`: **Config bleibt Seed/Fallback**,
Entity übersteuert — kein Merge pro Feld, ein vorhandener Datensatz gewinnt
komplett für to/cc/subject/routes):

```json
{
    "form_key": "contactForm",
    "to":       ["zihlundsee@sihlestate.ch"],
    "cc":       [],
    "subject":  "Neue Anfrage über das Kontaktformular",
    "routes": {
        "vermietung":  { "to": ["vermietung@sihlestate.ch"] },
        "verwaltung":  { "to": ["verwaltung@sihlestate.ch", "backup@sihlestate.ch"],
                          "subject": "Verwaltungsanfrage" }
    }
}
```

- `to` = Liste (mehrere Empfänger first-class, nicht mehr Komma-String).
- `routes` = Map **Options-Wert → Override** (`to` zwingend, `subject` optional).
  Kein Treffer / kein `routes` → Default `to`/`subject`. Ein Routen-Override
  ersetzt `to` komplett (kein Additiv — vorhersagbar).
- `template` bleibt **Code** (emailConfig): Templates sind Entwickler-Artefakte
  mit `e()`-Kontrakt, nichts für die Backend-Pflege.
- `subjectPrefix`/`replyTo`-Default bleiben emailConfig (global, selten).
- `#[Entity(..., invalidatesCache: false)]` — Mails rendern nie in Seiten.

### Kundenstamm-Seam (v3, nur Schnittstelle festlegen)

Empfänger-Einträge sind entweder eine literale Adresse ODER eine Referenz
`"ref:{source}:{id}"` (z.B. `"ref:customer:1042"`). v2 validiert/sendet nur
literale Adressen; der Resolver läuft aber durch eine einzige Methode
(`resolveRecipients(list<string>): list<string>`), in die v3 den
Kundenstamm-Lookup (`RecipientSourceInterface`) einhängt. Damit ändert der
Kundenstamm später **nur** diese Methode + eine UI-Auswahl, nicht das Datenmodell.

### Resolution — einziger Attachment-Point bleibt `EmailService`

```text
sendForm(formKey, context, replyTo, routeKey: ?string = null)
  → resolveFormSettings(formKey):
        1. Entity EmailFormSetting[form_key]     (Repository, wenn vorhanden)
        2. sonst emailConfig forms[form_key]     (Seed/Fallback wie heute)
  → routeKey !== null && routes[routeKey] vorhanden
        → to/subject aus der Route (subject fehlt → Default-subject)
  → subjectPrefix (emailConfig) + subject
  → weiter wie v1 (Template aus Config, Message, Mailer)
```

- **`routeKey` liefert der Controller** aus dem validierten Formularwert (z.B.
  die Betreff-Auswahl). Damit bleibt die Regel intakt: User-Input wählt nur
  einen **Schlüssel** aus einer server-definierten Map — nie eine Adresse und
  nie den Subject-Text (Header-Injection bleibt strukturell ausgeschlossen;
  unbekannter Key → Default, error_log-Hinweis).
- Signatur-Erweiterung ist abwärtskompatibel (Default `null`).

### Backend-UI — Sektion «Service» → «E-Mail»

`EmailSettingsController` (`Ui/Controllers/Service/`, extends
`BackendAbstractController`), Muster BackupController/LoginUser-Liste:

| Action | Art | Zweck |
|---|---|---|
| `listAction` | HTML | Formular-Keys (Vereinigung Config + Entity) als `be-list`: Key, effektive Empfänger, Betreff, Herkunft (Config/Backend), Routen-Anzahl |
| `editAction` | Fetch/Modal | to/cc (mehrzeilig), subject, Routen-Tabelle (Options-Wert, to, subject) |
| `saveAction` | Fetch POST | validieren (E-Mail-Format je Eintrag, `ref:`-Einträge in v2 abgelehnt; subject via `Message`-Kontrakt CR/LF-frei) → Entity persist |
| `resetAction` | Fetch POST | Entity-Datensatz löschen → zurück auf Config-Seed |

- Die **Options-Werte** einer Route (woher weiss das UI, welche Werte das
  Formular anbietet?): v2 pragmatisch = Freitext-Key im UI (der Entwickler
  dokumentiert die gültigen Werte im emailConfig-Kommentar des Formulars).
  Saubere Kopplung (Formular deklariert seine Options ans UI) → bewusst v3,
  zusammen mit dem Kundenstamm.
- Keine neue JS-Datei — bestehendes Fetch/Modal-Muster (`core.js`-Envelope).

## Owner-Entscheide (freigegeben 2026-07-18)

| # | Frage | Entscheid |
|---|---|---|
| E1 | Rolle für die UI | **ADMIN** — kein Config-Eintrag nötig (moduleRole-Default, AUTH-B003); Backup bleibt SUPER_USER |
| E2 | `to` als Liste vs. Komma-String | **Liste** im Entity; Config-Fallback akzeptiert weiterhin String (normalisiert beim Lesen) |
| E3 | Route darf `cc` übersteuern? | **Nein** — nur `to`/`subject` (v2) |
| E4 | zihlundsee-Routing sofort? | **Nein** — Fähigkeit liegt bereit, Aktivierung bei Client-Bedarf (`routeKey: $form->getWish()`) |

## Umsetzungs-Phasen

- [x] **P1 Kernel:** Entity `EmailFormSetting` + Repository + Validator;
      `EmailService`: `resolveFormSettings` Entity-first, `routeKey`-Parameter,
      `resolveRecipients()`-Seam (v2: literal only, `ref:` gedroppt + geloggt)
- [x] **P2 Backend-UI:** `EmailSettingsController` (list / edit-Modal mit
      Routen-Zeilen / reset), Navigation-Seed «E-Mail» (id 27) unter Service.
      Bestehende Projekte legen den Navigation-Eintrag manuell an (seed-once)
- [ ] **P3 Verifikation:** CLI-Harness ✓ (21/21 PASS: Config-Fallback,
      Entity-Override komplett, Route mit/ohne Subject, unbekannter routeKey →
      Default, `ref:` gedroppt / nur-`ref:` → false, Config-Routen-Normalisierung,
      Validator-Matrix, Entity-Round-Trip); **offen:** visueller UI-Pass durch
      Owner (Liste, Edit-Modal inkl. Routen-Zeilen, Zurücksetzen)
- [x] **P4 Doku:** `mail.md` (Abschnitt «form-mail settings v2», Rules,
      MAIL-V2-001, v3-Pendenz), `backend.md` (Gruppen- + Controller-Tabelle),
      docs:check grün

## Bewusst NICHT in v2

- Kundenstamm-Lookup (`RecipientSourceInterface`) — nur der `ref:`-Seam ist
  definiert; Auflösung + UI-Auswahl kommen mit dem Kundenstamm (v3).
- Formular-seitige Options-Deklaration ans UI (v3, siehe oben).
- Editierbare Templates/Layouts im Backend — Templates bleiben Code.
- Bcc, Versand-Log, Queue — kein Bedarf definiert.
