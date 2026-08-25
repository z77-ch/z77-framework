# Bauplan — wegwerfbarer Laufzeit-Zustand wandert nach `lib/`

**Entscheid Peter, 2026-08-24** (Fund in axo3 an den Widget-Drossel-Zaehlern):

> `data/` ist der Datenbereich, kein Abfalleimer. Was unter `lib/` liegt, darf
> ich jederzeit loeschen. `lib/cache` bleibt Cache, **`lib/throttle` ist das
> Ziel**, und der Backup-Dienst bekommt die Ausnahme.

Status: **erledigt, 2026-08-25.** Festgeschrieben in [ADR-034](../02-decisions/adr-034-disposable-runtime-state-under-lib.md). Die Drosseln liegen
unter `lib/throttle/` (Framework: `member/`, `totp-guard/`; AXO3: `widget/`,
`zeichnen/`), und `fullExcludes` nennt `lib` statt `lib/cache` — in der
Konstante, der Seed-Datei, dem Skeleton und von Hand in den beiden bestehenden
Installationen inklusive Server (seed-once, siehe BACKUP-LIB-001 in
[`../topics/backup.md`](../topics/backup.md)). Zur Kritik am Bauplan siehe
[`laufzeit-zustand-nach-lib-review-2026-08-25.md`](laufzeit-zustand-nach-lib-review-2026-08-25.md).

## Warum — drei Gruende, der dritte ist der harte

**1. Die Ablage luegt ueber den Inhalt.** Ein Drossel-Zaehler ist ein
Minutenfenster und eine Zahl. Loescht man ihn, beginnt die laufende Minute bei
null — mehr passiert nicht. Er liegt heute neben den Mandanten, den Konten und
dem Bestand, also neben allem, was wirklich weh taete.

**2. Er ist im Backup.** `BackupService::DEFAULT_EXCLUDES` schliesst
`lib/cache` aus, `data/` nicht. Gemessen in axo3 am 2026-08-24:

| Ort | Dateien | Inhalt |
|---|---|---|
| `data/framework/member/throttle` | 52 | Login-Versuche je Adresse und Stunde |
| `data/project/axo3/widget-throttle` | 12 | Auslieferung je Snippet und Minute |
| `data/project/axo3/zeichnen-throttle` | 1 | Uploads je IP und Stunde |

**3. ⚠️ Es kann das Archiv zerreissen — und das steht bereits im Code.**
`BackupService::DATA_EXCLUDES` haelt `framework/jobs` und `framework/import`
aus dem Datenbackup, mit dieser Begruendung im Docblock:

> «And it MOVES while the archive is being written: `ZipArchive` reads the
> files at `close()`, not at `addFile()`, so a `queue.json` replaced by the
> very backup job that is running fails the whole archive with
> "ZipArchive::close(): Read error".»

**Drossel-Zaehler sind exakt dieselbe Klasse und wurden uebersehen.** Sie
werden bei JEDER Auslieferung und JEDEM Login-Versuch neu geschrieben. Auf
einer belebten Installation schreibt ein Widget-Zaehler im Minutentakt,
waehrend das Backup laeuft. Der Fehler faellt selten und dann als kaputtes
Archiv auf — die schlechteste Kombination.

## Das Ziel

```
lib/
  cache/          bleibt, wie es ist (Seiten-Cache, FileFinder)
  throttle/
    member/       aus data/framework/member/throttle
    widget/       aus data/project/axo3/widget-throttle
    zeichnen/     aus data/project/axo3/zeichnen-throttle
```

⚠️ **`fullExcludes` nennt `lib/cache`, nicht `lib`.** Ein neues `lib/throttle`
waere ohne Nachzug wieder im Backup — der Umzug allein loest nichts. Zwei
Wege, der zweite ist der richtige:

- `fullExcludes` um `lib/throttle` ergaenzen — greift nur, wo die
  `config/backup.inc.php` noch die Vorgabe traegt (**seed-once**, bestehende
  Installationen haben ihre eigene Kopie und wuerden es NICHT bekommen).
- **Besser: `lib` als Ganzes ausschliessen** und die Konvention lautet «alles
  unter `lib` ist wegwerfbar». Dann traegt der Satz sich selbst, und ein
  kuenftiges `lib/irgendwas` ist automatisch richtig einsortiert.

Empfehlung: `lib`, und `DEFAULT_EXCLUDES` mit. Die Seed-Datei sagt dann, was
die Regel ist, statt eine Liste zu fuehren, die man pflegen muss.

## Was zu tun ist

### 1. Framework — `MemberThrottle`

⚠️ Der Pfad steht **dreimal getippt** da, in jedem Aufrufer neu:

- `module-member/src/Services/RegistrationFlow.php:82`
- `module-member/src/Services/LoginFlow.php:87`
- `module-member/src/Services/InvitationFlow.php:114`

Also zuerst **eine Stelle daraus machen** — `MemberThrottle::defaultDir()` —,
dann den Pfad dort einmal aendern. Genau dieses Muster hat axo3 am selben Tag
auf seiner Seite aufgeloest (`WidgetThrottle::defaultDir()`/`fileFor()`), dort
war die Namensregel zweimal abgeschrieben, das zweite Mal mit dem Kommentar
«same name the throttle builds» — die Form, die eine Drift annimmt, bevor sie
passiert.

### 2. Framework — Backup

`BackupService::DEFAULT_EXCLUDES` und `backup.default.inc.php` `fullExcludes`:
`lib/cache` → `lib`. Docblock nachziehen (die Begruendung von `DATA_EXCLUDES`
gilt wortgleich).

### 3. Framework — `lib/` muss existieren

Pruefen, wer `lib/cache` anlegt (Installer? Bootstrap beim ersten Schreiben?).
Die Drosseln legen ihr Verzeichnis selbst an (`mkdir(..., recursive)`), das
sollte tragen — aber es gehoert einmal nachgesehen, nicht angenommen.

### 4. AXO3 — nachziehen

Eine Zeile je Drossel, weil beide schon auf einer Stelle stehen:

- `Estate/Widget/WidgetThrottle::defaultDir()`
- `Estate/Zeichnen/SubmissionGate::__construct()` (Vorgabe-Pfad)

### 5. Doku

- **ADR-034** «Wegwerfbarer Laufzeit-Zustand liegt unter `lib/`» — mit der
  Abgrenzung: `data/` = was ein Restore zurueckbringen SOLL, `lib/` = was ein
  Restore nie zurueckbringen darf.
- `docs/topics/backup.md` — die neue Ausnahme.
- `docs/topics/member.md` — der neue Pfad der Login-Drossel (MEM-010/011
  nennen ihn heute; auch die Anleitung «Zaehlerdateien loeschen, wenn man sich
  ausgesperrt hat» zeigt dorthin).

## Nicht im Umfang — und warum

**Die Sperren** (`data/project/axo3/locks`, 5 Dateien). Zwischen zwei Laeufen
wegwerfbar, **waehrend** eines laufenden Imports nicht. Wenn `lib` heissen
soll «jederzeit loeschbar», passen sie nicht hinein, ohne den Satz zu
verwaessern. Eigener Entscheid, spaeter.

**`data/framework/mail/outbox`** (nur Dev, `transport = 'file'`). Ebenfalls
wegwerfbar, aber es ist eine Entwickler-Einrichtung, kein Laufzeit-Zustand der
Anwendung. Kann mit, muss nicht.

## Deploy

Zwei Handgriffe je Installation, beide seed-once-fest, beide ueberleben jeden
Deploy:

```
config/backup.inc.php                'lib/cache' -> 'lib'
data/framework/member/throttle/      loeschen
data/project/axo3/widget-throttle/   loeschen
data/project/axo3/zeichnen-throttle/ loeschen
```

✅ **Beides erledigt** (Peter, 2026-08-25) — axo3 und zihlundsee, Configs und
Ordner, nachgesehen. Kein Datenverlust: die Zaehler haben bei null begonnen,
wer gesperrt war, ist frei.

**Nur axo3 traegt sie.** Am 2026-08-25 nachgesehen: zihlundsee, archsult,
propbase und das Skeleton haben keinen einzigen dieser Ordner — dort meldet
sich noch niemand an. `lib/throttle/*` legt sich beim ersten Zugriff selbst an
(`mkdir(..., recursive)` in jeder Drossel), kein Installer-Eingriff noetig.
`totp-guard` gab es nirgends, es ist neu und entsteht erst mit dem ersten
TOTP-Fehlversuch.

⚠️ **`totp-guard` stand nicht im Bauplan** und ist am 2026-08-25 dazugekommen:
Fehlversuchszaehler plus Sperrfenster, dieselbe Klasse Datei — `TotpGuard::reset()`
loescht sie bei einem gueltigen Code ohnehin selbst.
