# Bauplan — Job-Queue mit CLI-Runner (`z77-run`)

**Status:** `[DONE]` — Bauphasen 1–6 gebaut und verifiziert (2026-08-07), Rollout offen
**Date:** 2026-08-07
**ADR:** [ADR-031](../02-decisions/adr-031-job-queue-and-cron-runner.md) — bindende Entscheide
**Vorgänger:** [ADR-028](../02-decisions/adr-028-cli-entry-point.md) — CLI-Einstiegspunkte

Ziel: eine Job-Verwaltung, die pro Installation mit **einem** Cron-Eintrag
auskommt. Jobs werden aus der Anwendung eingereiht oder von einem Zeitplan
erzeugt, laufen gedrosselt (20 Mails, dann 10 Minuten Pause) und können lange
Arbeit in Scheiben erledigen (Halt machen, beim nächsten Lauf aufsetzen).

> **Laufende Dokumentation ab hier:** [`../topics/jobs.md`](../topics/jobs.md).
> Dieses Dokument ist der Bauplan und bleibt als Entstehungsgeschichte stehen —
> Pendenzen und bekannte Fallen gehören ins Topic, nicht hierher.

## Hier geht es weiter (Stand 2026-08-07, Feierabend)

Der Code ist gebaut, verifiziert und committet. **Offen ist der Rollout** — im
Framework selbst ist nichts mehr zu tun.

1. **axo3: Composer aktualisieren.** `composer update z77/kernel` im Projekt,
   sonst entsteht `vendor/bin/z77-run` nicht. Ein `composer install` oder
   `vendor-deploy.bat` genügt NICHT — Composer baut `vendor/bin` aus der
   bin-Liste in der Lock-Datei (packaging.md PKG-005). Danach wie gewohnt
   deployen.
2. **Cron-Zeile setzen** (cyon-Panel, pro Installation eine):
   `* * * * * cd /pfad/zum/projekt && php vendor/bin/z77-run`
   Ob sie greift, zeigt der Bildschirm *Service → Jobs* selbst: ohne frischen
   Zeitstempel steht dort ein Warnbanner.
3. **Menüpunkt «Jobs» anlegen** — Navigationsdaten sind seed-once, bestehende
   Projekte bekommen Knoten 28 nicht automatisch. Über die
   Navigations-Verwaltung: Modul `backend`, Gruppe `service`, Controller `job`,
   Action `list`, unter der Sektion «Service».
4. **Erst dann Zeitpläne einschalten.** `member-cleanup` löscht — vorher einmal
   `php vendor/z77/module-member/bin/member-cleanup.php --dry-run` laufen
   lassen und anschauen, was er treffen würde. Die Backup-Jobs sind
   ungefährlich, kosten aber Plattenplatz (Retention beachten).
5. **Offen und bewusst nicht gebaut:** `cleanupAfterDays` aus der echten
   `memberConfig` konnte nicht verifiziert werden — im Skeleton ist `member`
   kein registriertes Modul, dort greift der eingebaute 30-Tage-Wert. In axo3
   ist das mit einem `--dry-run` in einer Minute geprüft.

Danach ist das Thema abgeschlossen; weitere Arbeit läuft über
[`../topics/jobs.md`](../topics/jobs.md) `## pending`.

## Owner-Entscheide (2026-08-07)

| Frage | Entscheid |
|---|---|
| Trigger | CLI-Binary, ein Cron-Eintrag pro Installation. HTTP-Trigger bleibt ausgeschlossen (ADR-028 + ADR-031) |
| Boot | `Bootstrap::__construct()` + neue `pullUpServices()` — kein Routing, keine Session, kein Dispatcher |
| Umfang | echte Queue (Payload, Retry, Drosselung, Fortsetzung), nicht nur wiederkehrende Zeitpläne |
| Cron-Eintrag | manuell beim Hoster; keine Installer-Arbeit, keine Prüfung |
| Job-Ziel | Job-Key → Klasse aus der Modul-Config. **Nicht** module/controller/action, **nicht** ein freier Script-Pfad |
| Parallelität | mehrere Jobs gleichzeitig, Obergrenze `maxParallel` (Default 3, konfigurierbar) |
| Sperren | `flock()` auf zwei Ebenen. Ein `running`-Feld im Datensatz ist nur Anzeige, nie Schutz |
| Akteur | `AuthRole::CRON_JOB` als Identität für Audit/ACL, nicht als Berechtigungs-Gate |

## Der Kernmechanismus

Drosselung und Scheiben-Arbeit sind **derselbe** Mechanismus. Ein Job gibt
zurück, ob er fertig ist — und wenn nicht, wo er wieder aufsetzt und ab wann:

```php
JobResult::done('42 Mails versendet');
JobResult::again(cursor: ['offset' => 340], notBefore: 600);  // 10 Min Pause
JobResult::again(cursor: ['offset' => 340]);                  // nächster Lauf, sofort
JobResult::failed('SMTP nicht erreichbar');
```

- **Drosselung** = `again()` mit `notBefore` in der Zukunft.
- **Scheiben** = `again()` ohne `notBefore`, wenn das Zeitbudget knapp wird.

Der Cursor gehört dem Job. Der Runner speichert ihn **opak** und interpretiert
ihn nie — ein Offset, eine letzte ID, eine Batch-Nummer sind alle gültig.

**Ein Eintrag bekommt höchstens eine Scheibe pro Lauf.** `again()` ist die
Aussage des Jobs, dass er für diesen Lauf fertig ist — der Runner überstimmt ihn
nicht, auch wenn der Eintrag sofort wieder fällig wäre und Budget übrig ist.
Ohne diese Regel dreht ein Job mit `notBefore: 0` eine enge Schleife aus
Leerläufen (gemessen: 231 Neustarts in 4 Sekunden Budget), jeder davon mit
vollem Lese-Schreib-Zyklus auf die Ablage.

## Das Job-Interface

```php
interface Job
{
    public function run(JobContext $context): JobResult;
}
```

`JobContext` ist alles, was ein Job vom Runner bekommt:

| Methode | Zweck |
|---|---|
| `getPayload(): array` | Nutzdaten aus dem Einreihen |
| `getCursor(): ?array` | Fortschritt des letzten Laufs, `null` beim ersten |
| `getAttempt(): int` | Versuchszähler (ab 1) |
| `hasTimeLeft(int $reserve = 5): bool` | ob noch Budget für eine weitere Scheibe da ist |
| `getActor(): AuthUser` | synthetischer Cron-Akteur für Audit / ACL |
| `log(string $line): void` | eine Zeile in den Lauf-Log |

`hasTimeLeft()` ist der ehrliche Teil: PHP kann einen Job nicht von aussen
unterbrechen (`pcntl` fehlt auf Shared Hosting). Das Budget ist **kooperativ** —
ein Job, der nicht fragt, läuft über. Der Runner protokolliert die Überschreitung,
verhindern kann er sie nicht.

## Ablauf eines Runner-Laufs

```text
php vendor/bin/z77-run
  1. Projekt-Root finden (getcwd() aufwärts, oder --project=) — Muster aus z77-backup
  2. new Bootstrap()          → Config, DI, DEBUG, Timezone, Logging, CANONICAL_BASE_URL
  3. Bootstrap::pullUpServices() → ModuleManager, I18n, Translator, UEM, EmailService
  4. laufende Job-Sperren zählen — >= maxParallel? sofort Exit 0
  5. fällige Zeitpläne → je einen Queue-Eintrag erzeugen (dedupliziert)
  6. Queue abarbeiten, solange Zeitbudget und maxParallel es zulassen:
       Eintrag holen (availableAt <= jetzt, state = queued,
                      in diesem Lauf noch nicht dran)         [kurze Queue-Sperre]
       → jobs/{jobKey}.lock versuchen — besetzt? Eintrag überspringen
       → state = running, startedAt setzen                     [kurze Queue-Sperre]
       → Job::run($context)                                    [lange Job-Sperre gehalten]
       → done   : Eintrag abschliessen
          again : cursor + availableAt speichern, state = queued
          failed: attempts++, Backoff → queued, oder nach maxAttempts → failed
       → Job-Sperre freigeben
       jeder Job in eigenem try/catch — ein Absturz beendet den Lauf nicht
  7. eine Zusammenfassungszeile ausgeben, Exit 0
```

Punkt 5 ist wichtig: ein Zeitplan ist **kein zweiter Ausführungspfad**, sondern
ein Erzeuger. Es gibt genau einen Weg, wie ein Job läuft — über die Queue.

Der Cron-Eintrag, manuell beim Hoster:

```text
* * * * * cd /pfad/zum/projekt && php vendor/bin/z77-run
```

Ein Lauf ohne fällige Arbeit kostet Boot-Phase 1 + Services und beendet sich
sofort — kein Routing, keine Session, keine Navigation.

## Sperren und Parallelität

Zwei Sperren mit verschiedener Haltedauer — das ist der Grund, warum Mailversand
und Backup gleichzeitig laufen können, ohne dass die Ablage zerschossen wird:

| Sperre | Haltedauer | Zweck |
|---|---|---|
| Queue-Datei | Millisekunden, nur um einen Eintrag zu lesen/schreiben | garantiert genau einen Schreiber auf `queue.json` |
| `jobs/{jobKey}.lock` | ganze Laufzeit des Jobs | verhindert, dass derselbe Job zweimal anläuft |

Die kurze Sperre existiert bereits: `FileStorage::withExclusiveLock()` — eigene
`.lock`-Datei (nicht die Zieldatei, die durch `rename()` ersetzt wird), nicht
blockierend mit ~2 s Budget statt endlosem Warten, auf NTFS und der NAS-Freigabe
verifiziert. `save()` schreibt ohnehin atomar über Temp-Datei + `rename()`.

Warum `flock()` und nicht ein `running`-Feld im Datensatz: eine Betriebssystem-
Sperre stirbt mit ihrem Prozess. Bricht ein Lauf hart ab (PHP-Fatal, Kill,
Neustart), ist sie automatisch frei. Ein Flag in der JSON-Datei überlebt den
Absturz und blockiert den Job für immer. Das `running`-Feld bleibt trotzdem —
aber ausschliesslich als Anzeige im Backend («läuft seit 14:03»). Steht es
falsch, ist das kosmetisch.

Verwaiste Einträge räumt der nächste Lauf auf: `state = running`, `startedAt`
älter als das Zeitbudget und keine Job-Sperre gehalten → der Prozess ist tot,
Eintrag zurück auf `queued` mit `attempts++` (ohne den Zähler dreht eine
Endlosschleife).

`maxParallel` (Default 3) deckelt, wie viele Jobs gleichzeitig laufen — Shared
Hosting begrenzt die Zahl gleichzeitiger PHP-Prozesse.

## Bootstrap-Aufteilung

`Bootstrap::__construct()` ist bereits vollständig HTTP-frei. `pullUp()` mischt
zwei Schichten; die HTTP-freie wandert in eine eigene Methode:

| Neu in `pullUpServices()` | Bleibt in `pullUp()` |
|---|---|
| `ModuleManager`, `I18n`, `Translator`, `SlugTranslator`, `TranslationCatalog` | `Request`, `Router`, `NavigationUrlResolver`, `NavigationService` |
| `DataSourceResolver`, `UnifiedEntityManager` | `SessionManager`, `CsrfService`, `AuthService`, `CurrentUserService` |
| `EmailService` (heute im HTTP-Block, im CLI zwingend nötig) | `ControllerHandler`, `AccessGuard`, `PageCachePolicy`, `Dispatcher` |

`pullUp()` ruft `pullUpServices()` als ersten Schritt auf. Für den Web-Request
ändert sich nichts — die Extraktion ist mechanisch und wird durch den
bestehenden Request-Pfad verifiziert.

Mail-Links funktionieren im CLI: `Request::getBaseUrl()` liest ausschliesslich
die Konstante `CANONICAL_BASE_URL`, kein `$_SERVER`. Ist sie leer, wirft der
Aufruf — ein Job bricht also ab, statt Links ins Nichts zu versenden (ADR-030).

## Registrierung — module-agnostisch

Jobs werden pro Modul deklariert, aggregiert über `ModuleManager::getJobs()`.
Gleiches Muster wie `reservedRoutes`: doppelter Key = Fail-Fast-Config-Fehler.

```php
'jobs' => [
    'member-cleanup' => [
        'class'           => MemberCleanupJob::class,
        'label'           => 'Member-Bereinigung',
        'runAs'           => AuthRole::CRON_JOB,
        'maxAttempts'     => 3,
        'defaultSchedule' => 'daily@03:15',
    ],
],
```

`defaultSchedule` ist ein **Seed**, kein fixer Zeitplan: sieht der Runner einen
Job-Key ohne Zeitplan-Eintrag, legt er ihn einmalig daraus an. Danach gehört der
Eintrag dem Betreiber und wird im Backend geändert. Damit braucht es keine
Installer-Arbeit und der Zeitplan bleibt trotzdem editierbar.

Der Job-Key ist bewusst **kein** module/controller/action und **kein** freier
Script-Pfad:

- Ein Controller zieht Routing und Session mit hoch — `AbstractBaseController::run()`
  holt `DI::getControllerHandler()` (gültig erst nach `lock()`) und
  `DI::getMessageService()` (braucht die Session). Dazu gibt eine Action laut
  Regel 3 immer ein `Response`-Objekt zurück, das im CLI niemand entgegennimmt.
- Ein Script-Pfad im Datensatz wäre ein Einfallstor: wer den Eintrag im Backend
  ändern darf, würde damit beliebigen Code starten. Über die Modul-Config ist es
  eine feste Auswahlliste.

## Config — `config/jobs.inc.php` (seed-once, wie backup)

```php
return [
    'maxParallel'   => 3,     // gleichzeitig laufende Jobs (Shared-Hosting-Limit)
    'timeBudget'    => 50,    // Sekunden pro Runner-Lauf
    'staleAfter'    => 900,   // Sekunden, ab denen ein 'running' als verwaist gilt
    'keepRuns'      => 50,    // Historie pro Job
];
```

## Datenmodell

Zwei Entities, `file`-Driver wie alles andere:

**`JobSchedule`** — `framework/jobs/schedules.json`
`jobKey`, `expression`, `enabled`, `lastRunAt`, `nextRunAt`.
Wenige Einträge, selten geschrieben.

Vier Ausdrucksformen (`ScheduleExpression`), bewusst **kein** Cron-Ausdruck:

| Form | Bedeutung |
|---|---|
| `every:15m` / `every:2h` | Abstand seit dem letzten Lauf |
| `hourly@:20` | jede Stunde zur Minute 20 |
| `daily@03:15` | täglich um 03:15 |
| `weekly@mon,03:15` | jeden Montag um 03:15 |

Ein Cron-Parser wäre einige hundert Zeilen mit eigenen Fallstricken, und seine
Fehler sind still — `15 3 * * 7` sieht richtig aus und feuert am falschen Tag.
Diese vier Formen decken ab, was eine Website-Installation real plant, lesen
sich laut vorgelesen korrekt und lassen sich im Backend als Auswahlfeld
abbilden. Eine Cron-Form kann später als fünfter Fall dazu, ohne dass sich
drumherum etwas ändert.

Nur `every:` misst ab dem letzten Lauf. Die Uhrzeit-Formen ignorieren ihn:
03:15 heisst 03:15, egal ob der gestrige Lauf stattgefunden hat.

**`JobRun`** — `framework/jobs/queue.json`
`id`, `jobKey`, `payload`, `cursor`, `state`, `availableAt`, `startedAt`,
`attempts`, `note`, `createdBy`.

Zustände: `queued` → `running` → `done` | `failed`. `running` ist Anzeige und
Absturz-Erkennung, **nicht** der Schutz gegen Doppelstart — das leistet die
Job-Sperre (siehe oben).

Jede Schreiboperation läuft durch die kurze Queue-Sperre, deshalb reicht je eine
JSON-Datei; `perRecord` (ADR-010) ist die Ausweichoption, falls das Volumen
wächst. **Erledigte Einträge müssen gelöscht werden** — sonst wächst
`queue.json` unbegrenzt und `FileStorage::load()` wird mit jedem Lauf teurer.
Vorschlag: `done` sofort entfernen, letzte `keepRuns` Läufe pro Job separat als
Historie führen.

## Akteur-Identität

Der Runner legt einen synthetischen `AuthUser` mit der Rolle aus `runAs`
(Default `AuthRole::CRON_JOB`, Stufe 30) in den `JobContext`. Er sitzt in keiner
Session — es gibt keine.

Klar benennen, damit keine falsche Sicherheit entsteht: **die CLI-Ausführung ist
durch diese Rolle nicht beschränkt.** Wer den Runner startet, kann ohnehin alles,
was der PHP-Prozess kann. Die Rolle dient der Zuordnung (`createdBy`,
Audit-Felder) und der Auswertung in Services, die selbst ACLs prüfen (DMS
`AclService`). Braucht ein Job mehr, deklariert er ein höheres `runAs` — sichtbar
in der Modul-Config statt versteckt im Code.

## Backend-UI

Analog `BackupController` (Gruppe `service`): Liste aller registrierten Jobs mit
letztem Lauf, Status und nächstem Termin; Zeitplan ein/aus und Intervall ändern;
«jetzt ausführen» (reiht einen Eintrag mit `availableAt = jetzt` ein, Fetch POST
+ CSRF); fehlgeschlagene Läufe mit Meldung und «nochmals versuchen».

Das UI reiht nur ein — ausgeführt wird ausschliesslich im Runner. Damit gibt es
weiterhin genau einen Ausführungspfad, und ein Klick im Backend kann keinen
Request-Timeout auslösen.

## Bestehende Binaries

`z77-backup` und `member-cleanup.php` bleiben als manueller Direkteinstieg
bestehen. Ihre Logik wandert in Job-Klassen; die Binaries werden dünne Wrapper,
die denselben Job einmal synchron ausführen. Kein doppelter Code, keine zweite
Wahrheit. Der heutige Handaufbau in `member-cleanup.php` (CacheManager und UEM
selbst zusammengeschraubt, ohne Timezone/DEBUG/Logging) entfällt damit.

## Bauphasen

| Phase | Inhalt |
|---|---|
| 1 | ✅ `Bootstrap::pullUpServices()` extrahiert; Web-Pfad unverändert verifiziert |
| 2 | ✅ `Job`, `JobContext`, `JobResult`, `JobOutcome`, `JobRun`, `JobLock`, `JobQueue`, `JobRunner`, Binary `z77-run`, `ModuleManager::getJobs()`, `AuthUser::REALM_CRON` |
| 3 | ✅ `member-cleanup` als erster Job portiert (`MemberCleanupJob`), Binary auf Wrapper umgebaut, in `memberConfig` registriert — bewusst OHNE `defaultSchedule` |
| 4 | ✅ `JobSchedule`, `ScheduleExpression`, `JobSchedules` + Seed aus `defaultSchedule`; Runner reiht fällige Zeitpläne ein |
| 5 | ✅ Backend-Sektion (`JobController` + Template, Navigation-Knoten 28, SUPER_USER); Backup-Typen als Jobs (`BackupJob`, drei Registry-Keys, Typ in der Payload); Runner-Heartbeat |
| 6 | `docs/topics/jobs.md` anlegen (erst jetzt — der Linter prüft, dass alle `SOURCE=`-Pfade existieren) |

## Offene Risiken

- **`flock()` auf Netzlaufwerken** ist nicht überall verlässlich. `FileStorage`
  nutzt es bereits und hat es auf NTFS und der NAS-Freigabe verifiziert — für die
  Zielumgebung des Kunden vor Phase 2 trotzdem prüfen. Fällt es aus, braucht es
  eine Lock-Datei mit PID + Zeitstempel und Altersprüfung.
- **Kooperatives Budget:** ein Job, der `hasTimeLeft()` ignoriert, belegt seine
  Job-Sperre weiter. Andere Jobs laufen dank `maxParallel` trotzdem, nur dieser
  eine staut sich.
- **`maxParallel` gegen das Hosting-Limit:** zu hoch gesetzt läuft der Runner in
  die Prozessgrenze des Hosters. Default 3 ist konservativ; wer erhöht, muss das
  Limit kennen.
- **Queue-Wachstum:** ohne Pruning wird jeder Lauf teurer. Gehört in Phase 2, nicht
  nachträglich.
- **Payload-Grenzen:** alles muss serialisierbar sein; lebende Objekte überleben
  keinen Lauf.
