# dms-bulk-select-bauplan.md — Multi-Select + Bulk-Aktionen (löschen / verschieben) im Drive

**Status:** GEBAUT (2026-07-16, S1–S6) — Auswahl-UI im Skeleton-Browser-Test BESTÄTIGT
(Checkboxen nach `appearance`-Fix, Bulkbar-Reveal, Zähler, Alle/Keine); Klick-Durchlauf der
Bulk-Modals (Verschieben/Löschen) + «Papierkorb leeren» noch offen.
Verifiziert: `php -l` (Trait + 3 Templates) grün, `npm run build:dms` grün, Action-Naming
(`bulk-confirm-delete` → `bulkConfirmDeleteAction` etc. via `Naming::toActionMethod`) bestätigt,
Routing per Konvention (deviation-only Map — kein Config-Eintrag nötig). Projekt zihlundsee:
publizierte Asset-Kopien (`public/assets/dms/css/dms.css` + `js/documents/drive.js`) manuell
aktualisiert (Installer-Kopie war vom 13.07; Templates/PHP kommen live über die vendor-Junction).
Nachtrag Skeleton-Test (2026-07-16): Checkboxen waren unsichtbar — das Backend-Normalize strippt
`appearance` von ALLEN Inputs (dieselbe Falle wie die `<select>`s, `_forms.scss`);
`.dms-file__select` setzt jetzt explizit `appearance: auto`. Bulkbar/Zähler/Alle/Keine liefen im
Test bereits; Thumbnails der 45-MP-Bilder alle da (Upload-Fix bestätigt sich mit).
Nachtrag 2 (User-Wunsch, 2026-07-16): **«Papierkorb leeren»** im Trash-Panel — `<details>`-
bestätigter `purgeAll`-Op in `trashAction` (jetzt `#[Fetch]`, da der Op ohne Entity-Token läuft;
Zeilen-Ops behalten ihre Tokens): Loop über das principal-scoped `listDeleted()` durch das
bestehende gegatete `purge()`; Retention-gesperrte werden übersprungen + im Flash rapportiert.
Ziel (User, zihlundsee): Bei vielen Bildern ist Einzeln-löschen/-verschieben umständlich.
Im Drive sollen mehrere Dokumente selektierbar sein; sobald ≥1 selektiert ist, erscheint
eine Aktionsleiste mit **Löschen** und **Verschieben** für die Auswahl.
Topic-Doc: [`../topics/documents.md`](../topics/documents.md).

## Design-Entscheide (mit dem User, 2026-07-16)

1. **Muster = Checkbox pro Zeile + Aktionsleiste** (Gmail/Google-Drive-Web-Pattern) — gewählt
   gegen Modifier-Klick (kollidiert mit `drive.js`-Konvention: Modifier-Klicks gehören dem
   Browser, "open in new tab"), Long-Press-Mode (JS-Zustand) und Marquee-Drag (Overkill).
2. **Aktionsleiste erscheint per CSS `:has()`** (`.dms-filelist-bulkbar`-Reveal, sobald eine
   Zeilen-Checkbox checked ist) — kein JS für das Kernverhalten (Regel 7, CSS-first).
   Browser-Support seit Ende 2023 komplett; Fallback wäre eine stets sichtbare Leiste.
3. **v1-Abgrenzung (User bestätigt):** nur Dokumente (keine Ordner), nur löschen + verschieben.
   Weitere Ops (aktiv/inaktiv, deliveryMode, Trash-Bulk) docken später als eigene Actions an
   dieselbe Leiste an.
4. **Kein neuer Domain-Code:** die Bulk-Endpoints iterieren über die BESTEHENDEN, gegateten
   `DocumentService::delete($id)` / `move($id, $target)` — das `manage`-Gate pro Dokument
   bleibt im Domain-Service (RF-4a/R-authz-1: Gate lebt im Service, pro Einzeloperation).
   Teilerfolge werden gesammelt und als EIN Flash rapportiert („7 verschoben, 2 übersprungen“).
5. **CSRF:** Bulk-POSTs tragen kein Entity-Token (n Dokumente) → beide Actions sind
   `#[Fetch]`-annotiert, der globale AccessGuard-Fetch-CSRF-Check ist die Autorität
   (DMS-SEC-001-Regel; Muster `folderAddAction`).
6. **Selektion ist Client-Zustand, URLs bleiben server-gebaut:** die Liste trägt die
   server-gebauten Modal-Basis-URLs als `data-bulk-delete-url` / `data-bulk-move-url`;
   `drive.js` hängt NUR den Selektions-Parameter `&ids=<id,id,…>` an (analog Formularfeldern —
   der Client konstruiert keine URL-Struktur). Im Modal reisen die ids als EIN hidden Feld
   (CSV-String) — `_z77CollectFormData` kollabiert mehrere gleichnamige hidden-Inputs
   (nur Checkbox-Gruppen werden zu Arrays), deshalb bewusst CSV statt Mehrfach-Feld.
7. **Kein No-JS-Pfad für Bulk:** alle Drive-Mutationen laufen heute über die Fetch-Schicht
   (Modals via `data-modal`); Bulk folgt dem. Der No-JS-Fallback des Drive betrifft nur
   Navigation (`href`).
8. **Bekannte Grenze:** ids reisen im GET-Query des Modal-Aufrufs — bei extrem grossen
   Ordnern (≫500 Dokumente alle selektiert) könnte die URL-Länge limitieren. Für das
   Backend-Tool akzeptiert (v1); ein POST-Modal-Flow wäre die spätere Eskalation.

## Bausteine

| Schritt | Datei | Inhalt |
|---|---|---|
| S1 | `_list.tpl.php` | Checkbox pro Zeile (`.dms-file__select`, `data-bulk-check`, value=id), Aktionsleiste `.dms-filelist-bulkbar` (Zähler, Alle/Keine, Verschieben, Löschen) mit server-gebauten `data-bulk-*-url` |
| S2 | `_filelist.scss` | Checkbox-Sichtbarkeit (hover / checked / `(hover:none)`), Bulkbar + `:has()`-Reveal |
| S3 | `DriveControllerTrait` | `bulkMoveAction` (GET Modal + POST, dual wie `moveAction`), `bulkConfirmDeleteAction` (GET Modal), `bulkRemoveAction` (POST) — alle `#[Fetch]`; ids-Parsing/Clamp, Loop + Teilerfolg-Zählung, `paneRefresh` |
| S4 | `_bulkMove.tpl.php`, `_bulkConfirmDelete.tpl.php` | Modals (Muster `move.tpl.php`/`confirmDelete.tpl.php`), hidden `ids`-CSV |
| S5 | `drive.js` | Delegiert: Bulkbar-Buttons (ids sammeln → `fetch.get(url + ids)`), Alle/Keine, Shift-Klick-Range, Zähler-Update |
| S6 | Build + Verify | `npm run build:dms`, `php -l`, Smoke (Bulk-Loop-Gating), Doku nachführen |

## Verifikation

- CLI-Smoke: bulk-Loop-Semantik (Teilerfolg: 1 ok + 1 fremdes/gesperrtes übersprungen; Flash-Text).
- Browser (User): Ordner mit vielen Bildern → selektieren (einzeln, Shift-Range, Alle) →
  verschieben in anderen Ordner → zurück, selektieren → löschen → Papierkorb prüfen.
