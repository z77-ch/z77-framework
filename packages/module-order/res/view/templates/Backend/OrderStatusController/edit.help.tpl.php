<?php
/**
 * Help for the order-status window (ADR-048) — attached by the trait, opened with «Hilfe» in the
 * title bar or F1; a section per field and per flag (`data-help-field` = the field's `name`), the
 * invariants in words. The form itself carries no explanations.
 */
?>
<h3>Auftragsstatus</h3>
<p>Ein Status sagt, wo ein Auftrag steht — von der Offerte bis «erledigt». Was ein Status <strong>bewirkt</strong>, steht in seinen Schaltern unter «Verhalten»: ob er Bestand reserviert oder verbraucht, ob der Buchungsdruck angeboten wird, ob der Auftrag damit abgeschlossen ist. Das Programm fragt nie nach dem Namen eines Status, nur nach diesen Schaltern — darum lässt sich hier ein neuer Status (z.B. «Abholbereit») ohne Programmänderung anlegen.</p>
<p>Ein Status wird nie gelöscht, nur <strong>deaktiviert</strong> (Schalter «Aktiv» in der Liste): Aufträge, die darin stehen, behalten ihn; er wird nur nicht mehr vergeben. Ein neuer Auftrag beginnt im aktiven Status mit der tiefsten Stufe.</p>
<p>Jeder Statuswechsel eines Auftrags wird protokolliert (wer, wann, von → nach). «Bezahlt» ist kein Status — ob eine Rechnung bezahlt ist, zeigt der Auftrag aus der Debitorenbuchhaltung.</p>

<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den jeder Auftrag speichert: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich. Nach dem Anlegen fix — für eine andere Bedeutung einen neuen Status anlegen.</p>
</section>

<section data-help-field="label">
<h3>Bezeichnung</h3>
<p>Was die Liste und das Auftragsfenster zeigen. Kann jederzeit geändert werden.</p>
</section>

<section data-help-field="level">
<h3>Stufe</h3>
<p>Die Reihenfolge im Ablauf. Ein Wechsel auf eine <strong>gleiche oder höhere</strong> Stufe ist immer erlaubt (gleich hoch: z.B. von «Erledigt mit Lagerbuchung» zu «Erledigt ohne Lagerbuchung»). Zurück auf eine tiefere Stufe geht nur, solange zum Auftrag keine Rechnung verbucht ist — danach ist eine Korrektur eine Gutschrift. Mehrere Status dürfen dieselbe Stufe haben.</p>
</section>

<section data-help-field="offer">
<h3>Offerte</h3>
<p>Der Auftrag ist ein Angebot: das PDF heisst «Offerte», es wird kein Bestand reserviert oder verbraucht und nichts fakturiert. Wird die Offerte zum Auftrag, behält sie ihre Nummer.</p>
</section>

<section data-help-field="reserves_stock">
<h3>Reserviert Bestand</h3>
<p>Die Mengen der Artikelpositionen gelten als reserviert (z.B. «Terminauftrag»). Ein Status reserviert <strong>oder</strong> verbraucht, nicht beides. Wirksam mit der Lagerbuchhaltung; bis dahin wird nichts gebucht.</p>
</section>

<section data-help-field="consumes_stock">
<h3>Verbraucht Bestand</h3>
<p>Die Mengen der Artikelpositionen gelten als ausgeliefert und verlassen den Bestand (z.B. «Geliefert», «Erledigt mit Lagerbuchung»). Eine negative Menge (Rücknahme) kommt zurück an Lager. Wechselt ein Auftrag später auf einen Status ohne Verbrauch, käme Bestand zurück — das verlangt einen Grund.</p>
</section>

<section data-help-field="invoiceable">
<h3>Fakturierbar</h3>
<p>In diesem Status wird der Buchungsdruck angeboten: er erstellt aus dem Auftrag die Rechnung, verbucht sie und setzt den Auftrag auf den gebuchten Status. Eine Offerte und ein abschliessender Status sind nie fakturierbar.</p>
</section>

<section data-help-field="invoice_booked">
<h3>Gebucht</h3>
<p>Den Status setzt <strong>nur der Buchungsdruck</strong> — von Hand ist er nicht wählbar (sonst gäbe es «gebucht» ohne Rechnung). Genau ein aktiver Status trägt diesen Schalter, und seine Stufe liegt höher als die jedes fakturierbaren Status. Fehlt er, wird der Buchungsdruck verweigert, bis einer markiert ist.</p>
</section>

<section data-help-field="delivery_note_target">
<h3>Ziel des Lieferscheins</h3>
<p>Der Status, auf den der Druck des Lieferscheins den Auftrag setzt — nur vorwärts: steht der Auftrag schon dort oder weiter, ändert ein erneuter Druck nichts. Höchstens ein aktiver Status trägt diesen Schalter (Handel: «Geliefert»; Dienstleistung: «Zu fakturieren»). Ohne ihn druckt der Lieferschein, ohne den Status zu ändern.</p>
</section>

<section data-help-field="final">
<h3>Abschliessend</h3>
<p>Der Auftrag ist erledigt und nicht mehr bearbeitbar. «Erledigt» wird von Hand gesetzt, nicht beim Zahlungseingang. Ein Storno vor der Rechnung ist «Erledigt ohne Lagerbuchung». Ein abschliessender Status ist weder fakturierbar noch gebucht.</p>
</section>
