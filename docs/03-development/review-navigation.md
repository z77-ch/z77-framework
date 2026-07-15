# Review: Navigation — Bereichs-Organisation & ORM-Tauglichkeit

**Stand:** 2026-05-29
**Scope:** Eignung der `tag → navigation`-Hierarchie zur Organisation der Bereiche frontend / backend / (zukünftig) member; Tauglichkeit für eine spätere Migration der Entities auf Doctrine ORM ohne Änderung der Logik.
**Quellen:** `NavigationService.php`, `Navigation.php`, `Tag.php`, `NavigationValidator.php`, `tags.default.json`, Frontend `header/footer.tpl.php`, Backend `header/subnav.tpl.php`, `BackendAbstractController.php`, ADR-005, `persistence-architecture.md`.

---

## Zusammenfassung

Die Hierarchie trägt — sie organisiert frontend/backend heute sauber und kann member aufnehmen. Aber zwei Strukturentscheidungen sind für „professionell skalierbar" und „ORM ohne Logikänderung" relevant:

1. **`tag` ist dreifach überladen** (NAV-R001): Bereichs-Diskriminator + UI-Region/Slot + Tree-Root-Marker in einem String-Feld. Funktioniert, aber Bereich und Struktur-Rolle sind orthogonale Konzepte, die im selben Feld kollidieren. Beim member-Bereich wächst der Tag-Namensraum kombinatorisch (`member`, `member-meta`, `member-secondary`, `member-auth`, …).
2. **`children: int[]` ist der eigentliche ORM-Risikopunkt** (NAV-R002) — nicht der Tag. Das Eltern-besitzt-Kinder-Array ist relational invertiert (Doctrine besitzt den Baum über `parent_id` am Kind) und die Reihenfolge der Kinder steckt heute implizit in der Array-Position. Beides muss bei der ORM-Migration in den Service-Interna umgeschrieben werden.

**Gute Nachricht zur ORM-Anforderung:** Die *Consumer*-Logik (Controller, Templates) ist bereits vollständig vom Persistence-Modell entkoppelt — sie greift nur über `NavigationService` zu, nie direkt auf `Navigation::getChildren()` (Regel in `navigation.md`, durchgesetzt in den Templates). „An der Logik nichts ändern" gilt für Consumer also schon. Was sich bei der ORM-Migration ändert, sind die *Service-Interna* — das ist unvermeidbar und akzeptabel, sofern wir die zwei fragilen Stellen jetzt kennen.

Verdict: **Keine Pflicht-Umbauten, aber zwei gezielte Verbesserungen empfohlen** (NAV-R001 Entkopplung Root-Marker, NAV-R002 explizites `position`-Konzept). Beide sind Diskussionspunkte, keine einseitigen Entscheidungen — siehe „Offene Entscheidungen".

---

## NAV-R001 — `tag` trägt drei Rollen gleichzeitig

**Dateien:** `Navigation.php:42` (`$tag`), `NavigationService.php:183` (`getByTag`), `NavigationValidator.php:165` (`validateTag` XOR-Logik), `tags.default.json`.

`tag` entscheidet heute drei voneinander unabhängige Dinge:

| Rolle | Beispiel | Wo verankert |
|---|---|---|
| **Bereich** (Area) | `frontend` vs `backend` | Template-Literale `getByTag('frontend')`, `$navTag='backend'` |
| **UI-Region / Slot** | `frontend` = Header, `frontend-meta` = Footer, `backend-auth` = nur Routing | Suffix-Konvention, nur im String kodiert |
| **Tree-Root-Marker** | Tag gesetzt ⇒ Wurzel; `tag: null` ⇒ Kind | `validateTag` (tag XOR child-referenced) |

Das ist „stringly typed": Die Zerlegung „Prefix = Bereich, Suffix = Region" ist reine Namenskonvention — nichts parst oder erzwingt sie. Konsequenzen:

- **Bereich ist nirgends erstklassig.** Er steckt mal im `module` (routable Einträge: `module='frontend'`), mal nur im Tag (strukturelle Container wie id 1/2 haben leeres `module`, aber `tag='backend'`), mal in einer Controller-Konstante (`BackendAbstractController.php:18` setzt `$navTag='backend'`). Drei Quellen für dieselbe Information.
- **Redundanz bei routablen Einträgen:** `module='frontend'` und `tag='frontend'` kodieren beide den Bereich. Der nicht-redundante Beitrag des Tags ist nur der Slot-Suffix (`-meta`, `-auth`).
- **Root-Marker und Klassifikation sind gekoppelt.** Ein Kind-Knoten *kann* keinen Tag tragen (XOR-Regel) — Klassifikation ist nur auf Wurzel-Ebene verfügbar. Will man später ein Kind-Blatt regional zuordnen, geht das nicht ohne Modell-Bruch.

**Skalierung auf member:** Pro Bereich entsteht ein voller Satz Region-Suffixe. 3 Bereiche × ~4 Regionen ⇒ ~12 Tags, alle als flache Strings, alle als Literale in den jeweiligen Templates. Das ist verkraftbar, aber es gibt keine Query „alle Einträge des Bereichs member", ohne jeden Suffix zu kennen, und keine Validierung, dass ein Tag zu einem bekannten Bereich gehört.

**Bewertung:** Für 3 Bereiche tragbar, aber das Konzept ist unscharf. Der hochwertigste, billigste Eingriff ist die **Entkopplung des Tree-Root-Markers vom Tag**: Eine Wurzel ist dann „hat keinen Parent" (strukturell), nicht „hat einen Tag" (Klassifikation). Das ist exakt die Richtung, die ORM ohnehin erzwingt (siehe NAV-R002), und macht den Tag frei für seine eigentliche Aufgabe: Region/Slot.

---

## NAV-R002 — `children: int[]` ist relational invertiert (der eigentliche ORM-Knackpunkt)

> **Status (2026-05-29): vollständig umgesetzt.**
> - Teil 1 — `sortKey` eingeführt (Reihenfolge nicht mehr implizit). Topic `navigation.md` NAV-SORT-001.
> - Teil 2 — `children: int[]` → `parentId: ?int` (Kind hält FK). Topic `navigation.md` NAV-PARENTID-001.
>
> **Entscheidungsverlauf (transparent):** `parentId` war zunächst bis zur Doctrine-Migration aufgeschoben (Consumer durch Service entkoppelt → kein stilles Risiko). Diese Entscheidung wurde am 2026-05-29 **revidiert**, nachdem klar wurde, dass Navigation der **Blueprint für mehrere kommende Baum-Entities** ist (document, article, content, …, Mix aus flachen Listen und Bäumen). Ein geteiltes Tree-Fundament soll auf der relational korrekten Repräsentation stehen — `children[]` (falsche Besitz-Richtung) in eine wiederverwendbare Basis einzuzementieren und später X-fach zu migrieren wäre teurer und fehleranfälliger. Daher `parentId` jetzt am Blueprint geklärt. `FileRepository` bleibt einfach (skalar `parent_id`), die Tree-Logik liegt im `NavigationService` / `NavigationController`. Maps 1:1 auf Doctrine `#[ORM\ManyToOne] $parent` bei der Migration, ohne Consumer-Änderung.

**Dateien:** `Navigation.php:53` (`$children`), `:138` (`setChildren`), `NavigationService.php:280` (`getChildren`), `:241` (`iterateSubTree`), `NavigationValidator.php:207` (`validateChildren`), `NavigationController::moveAction` (Topic `navigation.md`).

Heute besitzt der **Parent** die Kind-Beziehung als geordnetes ID-Array:

```php
private array $children = [];   // [6, 7] — IDs, Reihenfolge = Anzeigereihenfolge
```

Im relationalen Modell (Doctrine, Adjacency List) besitzt das **Kind** die Beziehung über `parent_id`:

```php
#[ORM\ManyToOne(targetEntity: Navigation::class, inversedBy: 'children')]
private ?Navigation $parent = null;

#[ORM\OneToMany(mappedBy: 'parent', targetEntity: Navigation::class)]
#[ORM\OrderBy(['position' => 'ASC'])]
private Collection $children;
```

Drei konkrete Migrationsfolgen:

1. **Besitz-Richtung kippt.** File: Parent hält `children[]`. Doctrine: Kind hält `parent_id`, die `children`-Collection ist nur die inverse Seite. `validateChildren` (sucht „erscheint ID in mehr als einem Parent-Array") und der Orphan-Check in `validateTag` (sucht „wird diese ID irgendwo als Kind referenziert") werden mit einem `parent_id` trivial bzw. überflüssig — aber sie müssen umgeschrieben werden.

2. **Reihenfolge ist heute implizit.** Die Anzeigereihenfolge der Kinder = Array-Reihenfolge im JSON. `moveAction` (`new_index`) operiert direkt auf Array-Positionen. Eine `OneToMany`-Collection hat **keine** inhärente Ordnung — sie braucht eine explizite `position`-Spalte + `#[OrderBy]`. **Das ist die gefährlichste stille Änderung:** Ohne `position` geht die Sortierung bei der Migration verloren.

3. **`getChildren()` ändert Rückgabetyp.** Heute `int[]` (Roh-IDs), via Service zu Entities aufgelöst. Doctrine liefert direkt `Collection<Navigation>`. Hier rettet uns die bestehende Kapselung: Consumer rufen `NavigationService::getChildren($entry)` (Regel in `navigation.md`), nie `$entry->getChildren()` direkt. Die Typänderung bleibt damit **innerhalb** von `NavigationService` — Templates und Controller merken nichts.

**Bewertung:** Die ORM-Anforderung „Logik unverändert" ist für Consumer erfüllt, weil die Kapselung sauber ist. Sie ist *nicht* erfüllt für `NavigationService` + `NavigationValidator` + `moveAction` — diese drei lesen das Array-Modell direkt und werden bei der Migration neu geschrieben. Das ist Framework-intern und vertretbar. Der einzige inhaltliche Fallstrick ist die **fehlende explizite Reihenfolge**: Ein `position`-Feld jetzt einzuführen (auch im File-Modell) macht die Migration verlustfrei und ist billig.

---

## NAV-R003 — `tag` referenziert die Tag-Entity per Slug-String, nicht per FK

**Dateien:** `Navigation.php:42` (`$tag: ?string`), `Tag.php:14` (`$id`), `NavigationService.php:294` (`getTag(string $name)`), `TagRepository.php:10` (`findByName`).

`Navigation.tag` speichert den Slug (`'backend'`), die `Tag`-Entity hat aber eine `id`. Die Beziehung ist ein **logischer Join über den Namen**, kein physischer FK (`tag_id → tags.id`).

Für Doctrine wäre die normalisierte Form ein FK. Aber: `persistence-architecture.md` akzeptiert logische Joins explizit (ARCH-A001, minimales `RepositoryInterface`, `findBy`). `getTag(name)` ist treiber-agnostisch. **Kein Handlungsdruck** — bei der ORM-Migration kann der Slug-Join bleiben oder optional auf FK normalisiert werden, ohne Consumer-Logik zu berühren. Erwähnt der Vollständigkeit halber; niedrigste Priorität.

`ref: ?int` (Self-Pointer) und `params: Map<string,string>` sind dagegen ORM-freundlich: `ref` → `ManyToOne` Self-Reference, `params` → `#[Column(type: 'json')]`. Beide unkritisch.

---

## Optionen

| Option | Idee | Pro | Contra |
|---|---|---|---|
| **A — Status quo** | Composite-String-Tag beibehalten, member analog ergänzen (`member`, `member-meta`, …) | Null Aufwand; konsistent mit Bestehendem; „kein Overengineering" | Tag bleibt dreifach überladen; Bereich nicht erstklassig/abfragbar; ORM-Reihenfolge-Risiko (NAV-R002) bleibt ungelöst |
| **B — Root-Marker entkoppeln + `position`** | Wurzel = „kein Parent" statt „hat Tag"; `position`-Feld einführen; Tag bleibt, trägt aber nur noch Region/Slot | Billigster hoher Nutzen; löst NAV-R002-Reihenfolge verlustfrei; bereitet ORM-Baummodell vor; Tag wird konzeptuell scharf | Umbau von `validateTag`/`validateChildren`/`moveAction`; Dat+Migration der bestehenden JSON-Einträge |
| **C — Bereich erstklassig** | Zusätzlich `area`-Feld (frontend/backend/member) explizit; Tag → reiner Slot; ggf. `area` aus `module` ableiten | Bereich abfragbar/erzwingbar; saubere member-Skalierung; Access-Guards können auf `area` keyen | Mehr Felder/Konzepte; Gefahr Overengineering bei nur 3 Bereichen; `area` teils redundant zu `module` |

---

## Empfehlung

**Option B als Kern, C-Anteile nur falls konkret gebraucht.**

Begründung gegen reines A: Die Reihenfolge-Lücke (NAV-R002) ist ein echtes, stilles Datenrisiko bei der ORM-Migration — die genau die Anforderung „an der Logik nichts ändern" verletzen würde (Kinder kämen in falscher/zufälliger Reihenfolge). Ein `position`-Feld jetzt ist billig und macht die Migration verlustfrei.

Begründung gegen volles C: Bei 3 Bereichen ist ein explizites `area`-Feld nahe an Overengineering — `module` trägt den Bereich für routable Einträge bereits. Ich würde `area` erst einführen, wenn ein konkreter Bedarf auftaucht (z.B. bereichsweite Access-Guards oder eine Query „alle member-Einträge"). Bis dahin reicht die Entkopplung des Root-Markers, um den Tag konzeptuell sauber auf „Region/Slot" zu reduzieren.

Konkret vorgeschlagen (in dieser Reihenfolge, jeder Schritt eigenständig lauffähig):

1. **`position: int` einführen** (File-Modell + Service + `moveAction`), Anzeigereihenfolge explizit machen statt aus Array-Position abzuleiten. → ORM-verlustfrei.
2. **Root-Marker von Tag entkoppeln:** „Wurzel = nicht als Kind referenziert" als alleiniges Kriterium; `validateTag` prüft dann nur noch, dass *Wurzeln* einen Tag (Slot) tragen und Kinder optional. → Tag wird reines Region/Slot-Feld.
3. **`area` zurückstellen**, bis ein konkreter Use-Case (member-Access-Guard, bereichsweite Query) ihn rechtfertigt.

NAV-R003 (Slug vs. FK) bleibt offen gelassen — bei der ORM-Migration entscheidbar, ohne Consumer-Folgen.

---

## Offene Entscheidungen (Diskussion, nicht einseitig)

1. ~~**`position`-Feld?**~~ **Erledigt 2026-05-29 als `sortKey`** (NAV-SORT-001). ~~**`parentId` jetzt oder bei ORM?**~~ **Erledigt 2026-05-29** (NAV-PARENTID-001, Begründung siehe NAV-R002-Status oben). — Zwei Folge-Punkte offen: (a) `EntityManager::reorder()` ist codebase-weit ungenutzt → Entfernung aus dem Persistence-Interface separat entscheiden; (b) **Extraction-Trigger**: beim zweiten sortierbaren bzw. Baum-Entity Ebene 1 `Orderable`-Trait + Ebene 2 Baum-Move extrahieren (jetzt noch nicht — ein Consumer).
2. **NAV-R001 (Tag-Überladung / `area` erstklassig) — offen.** Unabhängig von NAV-R002. Der Tree-Root-Marker hängt weiter am Tag (`hat Tag` ⇒ Wurzel); Bereich/Region/Root-Rolle teilen sich nach wie vor das `tag`-Feld. Lohnt eine Entkopplung erst, wenn member/Bereichs-Logik konkret wird.
3. **`area` erstklassig — ja/nein?** Hängt davon ab, ob bereichsweite Logik (Access, Queries) absehbar ist. Wenn nein: weglassen (Overengineering vermeiden).

---

## see also

- [`../topics/navigation.md`](../topics/navigation.md) — Single Source of Truth; Pendenzen/Bugs dort pflegen
- [`../topics/persistence-architecture.md`](../topics/persistence-architecture.md) — Doctrine-Branch (geplant), `RepositoryInterface`-Vertrag, akzeptierte Abstraktions-Lecks (ARCH-A001/002/003)
- [`../02-decisions/adr-005-module-architecture-and-url-grouping.md`](../02-decisions/adr-005-module-architecture-and-url-grouping.md) — `module`/`group` als Bereichs-/UI-Grenzen
- [`navigation-opener-entscheidungs.md`](navigation-opener-entscheidungs.md) — Opener-/Ref-Mechanik (offene Teile)
