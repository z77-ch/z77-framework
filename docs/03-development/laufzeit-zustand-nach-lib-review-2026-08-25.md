# Review — Bauplan «wegwerfbarer Laufzeit-Zustand nach `lib/`» (ADR-034 in spe)

**Datum:** 2026-08-25 · **Gegenstand:** [`laufzeit-zustand-nach-lib-bauplan.md`](laufzeit-zustand-nach-lib-bauplan.md)
**Frage:** Kann das so gebaut werden?

> **Nachtrag 2026-08-25, gebaut.** Umzug und Backup-Ausschluss sind erledigt.
> Befund 1 wurde bestaetigt und umgesetzt: `fullExcludes` nennt jetzt `lib`
> (Konstante, Seed-Datei, Skeleton) UND wurde in den beiden bestehenden
> Installationen von Hand nachgezogen — als BACKUP-LIB-001 in
> [`../topics/backup.md`](../topics/backup.md) festgehalten. Befund 2 (der
> ueberzogene dritte Grund) ist beruecksichtigt: er steht in keinem Commit als
> Begruendung. Befund 3 (drei Kategorien statt zwei) und Befund 4 (der Name
> `lib`) stehen in
> [ADR-034](../02-decisions/adr-034-disposable-runtime-state-under-lib.md): die
> drei Kategorien als Entscheidungstabelle, `var/` als bewusst verworfene
> Alternative — der Name `lib` ist damit als Entscheid festgehalten, nicht als
> Versehen. Dieses Review ist abgearbeitet.

**Antwort: ja, der Umzug traegt — aber nicht mit der Begruendung und nicht mit
dem Deploy, die der Bauplan nennt.** Vier Befunde, zwei davon blockierend.

---

## Verifiziert (der Bauplan stimmt hier)

- **Exclude-Matching traegt `lib`.** `ZipArchiver::zipDirectory()` prueft
  `$rel === $exclude || str_starts_with($rel, $exclude.'/')` — ein Eintrag
  `lib` schliesst `lib/cache` und jedes kuenftige `lib/*` mit aus. Der
  Vorschlag «`lib` statt `lib/throttle`» ist technisch sauber.
- **`lib/` existiert zur Laufzeit.** `CacheManager::setCacheDir()` legt
  `lib/cache` beim Boot per `mkdir(recursive)` an, `MemberThrottle::count()`
  legt sein Verzeichnis selbst an. Punkt 3 des Bauplans ist beantwortet: kein
  Installer-Eingriff noetig.
- **Der Pfad steht dreimal getippt da** (`RegistrationFlow:82`,
  `LoginFlow:87`, `InvitationFlow:114`). `MemberThrottle::defaultDir()` zuerst
  ist richtig herum.

---

## Befund 1 — ⚠️ blockierend: `lib` als Ganzes loest das Seed-once-Problem NICHT

Der Bauplan stellt «besser: `lib` als Ganzes ausschliessen» so dar, als trage
sich der Satz dann selbst. Fuer **neue** Installationen stimmt das. Fuer
**bestehende** nicht — und die sind der Anlass.

`config/backup.inc.php` ist seed-once. Gemessen an der Live-Installation
zihlundsee am 2026-08-25:

```php
'fullExcludes' => ['vendor', 'node_modules', 'backup', 'lib/cache'],
```

`BackupService::fullExcludes()` liest `$this->config['fullExcludes']` und faellt
**nur bei fehlendem Schluessel** auf `DEFAULT_EXCLUDES` zurueck. Der Schluessel
fehlt nicht. Egal ob die Konstante kuenftig `lib/throttle` oder `lib` sagt:
axo3 und zihlundsee bekommen davon nichts. Ein neues `lib/throttle` landet dort
im Full-Backup — genau der Zustand, den der Umzug beenden soll.

**Konsequenz:** Deploy braucht einen zweiten Handgriff je Installation, der im
Bauplan fehlt:

```
config/backup.inc.php  →  'lib/cache' durch 'lib' ersetzen
```

Ohne diese Zeile ist der Umzug fuer das Full-Backup wirkungslos. Sie gehoert
neben die drei Loesch-Handgriffe im Abschnitt «Deploy», nicht in eine Fussnote.

---

## Befund 2 — ⚠️ blockierend fuer die ADR-Formulierung: der dritte Grund ist ueberzogen

Der Bauplan nennt «es kann das Archiv zerreissen» den harten Grund und beruft
sich auf den `DATA_EXCLUDES`-Docblock. Die beiden Faelle sind aber **nicht
dieselbe Klasse**:

| | `queue.json` (Jobs) | Drossel-Zaehler |
|---|---|---|
| Schreibweg | `FileStorage::save()` — tmp-Datei + `rename()` | `file_put_contents($file, ..., LOCK_EX)`, in place |
| Datei verschwindet | ja, der alte Inode wird ersetzt | nein, nie |
| Aufraeumen | ja | **keins** — «self-expiring, no cleanup needed» |

`MemberThrottle` loescht nichts und benennt nichts um. Waehrend des Archivs
kann eine Zaehlerdatei nur **ueberschrieben** werden — von `1` auf `2`, ein Byte
gegen ein Byte. `ZipArchive::close()` findet den Pfad vor, oeffnet ihn und
liest. Das ergibt schlimmstenfalls einen Zaehlerstand im Archiv, der eine
Sekunde alt ist. Kein «Read error», kein kaputtes Archiv.

Der einzige echte Riss waere ein Fensterwechsel mit zweistelligem Zaehler
(1 Byte → 2 Byte) zwischen `addFile()` und `close()` — und auch der liefert
libzip eine lesbare Datei.

**Das ist kein Argument gegen den Umzug.** Grund 1 (die Ablage luegt) und
Grund 2 (es ist im Backup, obwohl es nichts wert ist) tragen ihn allein und
sauber. Aber Grund 3 darf so **nicht in die ADR**: eine ADR, die mit einem
Datenverlust-Risiko begruendet, das der Code nicht hergibt, ist beim naechsten
Leser entweder falsch verstanden oder unglaubwuerdig. Entweder streichen oder
auf «gleiche Familie, geringeres Risiko, gleiche Behandlung» abschwaechen.

---

## Befund 3 — die Zwei-Kategorien-Regel hat am Tag eins schon eine Ausnahme

Die vorgeschlagene Abgrenzung:

> `data/` = was ein Restore zurueckbringen SOLL · `lib/` = was ein Restore nie
> zurueckbringen darf

`data/framework/jobs` und `data/framework/import` liegen unter `data/` und
duerfen ein Restore nie ueberleben — deshalb gibt es `DATA_EXCLUDES`. Der Satz
ist also schon falsch, bevor er geschrieben ist. Und der Bauplan selbst nimmt
die Sperren mit derselben Begruendung heraus, die auch fuer Jobs gilt:
zwischen zwei Laeufen wegwerfbar, waehrend eines Laufs nicht.

Es sind **drei** Kategorien, nicht zwei:

| | Restore bringt zurueck | jederzeit loeschbar | Ort |
|---|---|---|---|
| Nutzdaten | ja | nein | `data/` |
| fluechtiger Zustand mit Laufzeit-Bindung | nein | **nein** (nicht mitten im Lauf) | `data/` + `DATA_EXCLUDES` |
| wegwerfbarer Zustand | nein | ja | `lib/` |

Die ADR sollte die mittlere Zeile benennen und `DATA_EXCLUDES` als ihren
Traeger ausweisen — sonst liest der naechste Entwickler die Zwei-Wege-Regel,
findet `framework/jobs` unter `data/` und haelt es fuer ein Versehen. Jobs,
Import-Staging und Sperren nach `lib/` zu ziehen waere die andere Aufloesung,
ist aber falsch: sie sind nicht jederzeit loeschbar.

---

## Befund 4 — der Name `lib` sagt das Gegenteil (Diskussion, kein Blocker)

`lib` heisst in praktisch jedem PHP-Projekt «Bibliothek, also Code». Der Bauplan
belegt das Wort neu mit «Abfall, jederzeit loeschbar». Solange dort nur
`lib/cache` lag, war das eine hinnehmbare Unschaerfe. Eine ADR, die `lib/` zur
**deklarierten Kategorie** macht, zementiert den Fehlgriff — und zwar in einem
Framework, dessen erklaerter Zweck die Uebergabe an einen Nachfolger ist.

Der Standardname dafuer heisst `var/` (FHS, Symfony, Laravel-`storage/`) und
traegt genau diese Bedeutung von aussen mit. Kosten des Wechsels **jetzt**:

- `bootstrap.default.inc.php` → `'cacheDir' => 'var/cache/'`
- `backup.default.inc.php` + `DEFAULT_EXCLUDES` → `'var'`
- Deploy: `lib/cache` loeschen (ist ein Cache), `config/backup.inc.php` anpassen
  — beides steht wegen Befund 1 ohnehin schon auf der Liste

Das ist derselbe Handgriff, den der Umzug sowieso verlangt, plus eine
Konstante. Nach der Veroeffentlichung kostet es jede fremde Installation.
**Empfehlung: jetzt entscheiden.** Wenn `lib` bleibt, muss die ADR den Namen
ausdruecklich begruenden, damit der naechste Leser nicht denkt, es sei
Versehen.

---

## Fazit

Baubar. Reihenfolge:

1. **Entscheid Name** — `lib` oder `var`. Alles andere haengt daran.
2. `MemberThrottle::defaultDir()` einziehen, drei Aufrufer darauf umstellen.
3. `DEFAULT_EXCLUDES` + `backup.default.inc.php`: `lib/cache` → `lib` (bzw. `var`).
4. ADR-034 schreiben — mit **drei** Kategorien (Befund 3), ohne den
   Archiv-Riss als Hauptgrund (Befund 2).
5. Deploy-Liste um `config/backup.inc.php` je Installation ergaenzen (Befund 1).
6. `docs/topics/backup.md`, `docs/topics/member.md` (MEM-010/011, Anleitung
   «Zaehlerdateien loeschen») nachziehen, `npm run docs:check`.

## see also

- [`laufzeit-zustand-nach-lib-bauplan.md`](laufzeit-zustand-nach-lib-bauplan.md) — der Entscheid, den dieses Review prueft
- [`../topics/backup.md`](../topics/backup.md) — Excludes, Seed-once-Verhalten
- [`../topics/member.md`](../topics/member.md) — Login-Drossel, MEM-010/011
