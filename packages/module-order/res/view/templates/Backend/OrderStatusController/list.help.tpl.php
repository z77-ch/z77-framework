<?php
/**
 * Help for Stammdaten › Auftragswesen › Auftragsstatus (ADR-048, HELP-003) — attached by
 * `listAction()` with `toolbar: true`. A quick help for the list; the status window explains the
 * phases and every switch (`edit.help`). Static text.
 */
?>
<h3>Auftragsstatus</h3>
<p>Die Status, die ein Auftrag haben kann, geordnet nach ihrer Phase: Status anlegen, umbenennen, deaktivieren; sehen, welcher Status beim Erfassen, bei Auftragsbestätigung, Lieferschein, Fakturieren und Journal verbuchen gesetzt wird.</p>
<ul>
    <li><strong>+ Status</strong> legt einen neuen Status an; das Symbol am Zeilenanfang öffnet einen bestehenden.</li>
    <li><strong>Phase</strong> — Eröffnet, Termin, Geliefert, Abgeschlossen oder Abgelegt. Die Phase bestimmt, was mit einem Auftrag geht, und in welchem Reiter der Auftragsliste er steht.</li>
    <li><strong>Wird gesetzt durch</strong> — die Aktion, die diesen Status setzt: neuer Auftrag, Auftragsbestätigung, Lieferschein, Fakturieren, Journal verbuchen. Leer = nur von Hand.</li>
    <li><strong>Aktiv</strong> — ein deaktivierter Status wird nicht mehr vergeben; Aufträge, die darin stehen, behalten ihn. Gelöscht wird ein Status nie.</li>
</ul>
