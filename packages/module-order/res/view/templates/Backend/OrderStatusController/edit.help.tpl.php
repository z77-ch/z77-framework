<?php
/**
 * Help for the order-status window (ADR-048) — attached by the trait, opened with «Hilfe» in the
 * title bar or F1; a section per field (`data-help-field` = the field's `name`), the phases in
 * words (ADR-043 addendum 2026-10-15). The form itself carries no explanations.
 */
?>
<h3>Auftragsstatus</h3>
<p>Ein Status ist eine <strong>Bezeichnung</strong>, nach der die Aufträge geordnet und gesucht werden — «Terminauftrag», «Bestellung», «bereitgestellt», «zu fakturieren». Es dürfen beliebig viele sein. Was mit einem Auftrag geschieht (Lager, Fakturieren, Dokumente), bestimmt allein die <strong>Phase</strong>, zu der sein Status gehört — darum kann ein Status nichts falsch buchen.</p>
<p>Ein Status wird nie gelöscht, nur <strong>deaktiviert</strong> (Schalter «Aktiv» in der Liste): Aufträge, die darin stehen, behalten ihn; er wird nur nicht mehr vergeben. Jeder Statuswechsel eines Auftrags wird protokolliert (wer, wann, von → nach). «Bezahlt» ist kein Status — ob eine Rechnung bezahlt ist, zeigt der Auftrag aus der Debitorenbuchhaltung.</p>

<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den jeder Auftrag speichert: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich. Nach dem Anlegen fix — für eine andere Bedeutung einen neuen Status anlegen.</p>
</section>

<section data-help-field="label">
<h3>Bezeichnung</h3>
<p>Was die Liste und das Auftragsfenster zeigen und wonach in der Auftragsliste gesucht wird. Kann jederzeit geändert werden.</p>
</section>

<section data-help-field="phase">
<h3>Phase</h3>
<p>Die Phasen sind fest — die Leiter, an der jeder Auftrag hochsteigt:</p>
<ul>
    <li><strong>Eröffnet</strong> — Offerte, Anfrage, erfasst: kein Lager, kein Fakturieren, das PDF heisst «Offerte». «Auftragsbestätigung …» bestätigt den Auftrag.</li>
    <li><strong>Termin</strong> — Bestellung, Terminauftrag, Dienstleistung: lagerführende Artikel sind <strong>reserviert</strong>; Fakturieren möglich. «Lieferschein …» liefert.</li>
    <li><strong>Geliefert</strong> — bereitgestellt, geliefert, zu fakturieren: die Ware ist aus dem Lager, der Bestand ist <strong>verbraucht</strong>; Fakturieren möglich.</li>
    <li><strong>Abgeschlossen</strong> — gebucht, erledigt: verbraucht, nicht mehr bearbeitbar.</li>
    <li><strong>Storniert</strong> — der Auftrag findet nicht statt: nichts reserviert oder verbraucht, nicht mehr bearbeitbar. Nach einer fakturierten Rechnung wird nicht storniert.</li>
</ul>
<p>Ob eine Position überhaupt Lager bewegt, entscheidet der <strong>Artikel</strong> («führt Bestand») — eine Dienstleistung oder eine freie Position nie, in keiner Phase. Ein Wechsel in eine gleiche oder spätere Phase ist immer erlaubt; zurück nur, solange keine Rechnung verbucht ist. Kommt dabei verbrauchte Ware zurück ins Lager, wird ein Grund verlangt.</p>
</section>

<section data-help-field="position">
<h3>Sortierung</h3>
<p>Nur die Anzeige: in welcher Folge die Status einer Phase in dieser Liste und in den Auswahlen stehen (z.B. «Bereitgestellt» vor «Geliefert»). Mehrere Status dürfen dieselbe Zahl haben. Auf das Verhalten hat die Zahl keinen Einfluss.</p>
</section>

<section data-help-field="start">
<h3>Startstatus für neue Aufträge</h3>
<p>Mit diesem Status beginnt jeder neue Auftrag, wenn im Formular nichts anderes gewählt wird. Es gibt <strong>einen</strong> Startstatus für alle — in der Phase <strong>Eröffnet</strong> (z.B. «Offerte») oder <strong>Termin</strong> (z.B. «Terminauftrag», wenn Aufträge meist direkt bestätigt sind). Wird er hier eingeschaltet, verliert ihn der Status, der ihn bisher hatte.</p>
</section>

<section data-help-field="phase_default">
<h3>Wird gesetzt beim …</h3>
<p>Welchen Status ein Auftrag bekommt, wenn eine <strong>Aktion</strong> ihn in diese Phase bringt. Der Schalter heisst je nach Phase:</p>
<ul>
    <li><strong>Wird gesetzt beim Druck der Auftragsbestätigung</strong> (Termin) — z.B. «Terminauftrag»;</li>
    <li><strong>Wird gesetzt beim Druck des Lieferscheins</strong> (Geliefert) — z.B. «Geliefert»;</li>
    <li><strong>Wird gesetzt beim Fakturieren</strong> (Abgeschlossen) — z.B. «Gebucht». Diesen Status setzt nur das Fakturieren, von Hand ist er nicht wählbar.</li>
</ul>
<p>Pro Phase hat ihn ein Status; wird er hier eingeschaltet, verliert ihn der Status, der ihn bisher hatte. Ist keiner markiert, nimmt die Aktion den ersten der Phase (nach Sortierung). Steht der Auftrag schon in dieser Phase oder weiter, ändert die Aktion den Status nicht. Bei «Status ändern …» von Hand spielt der Schalter keine Rolle. Eröffnet und Storniert haben keinen solchen Schalter — keine Aktion führt dorthin (ein neuer Auftrag nimmt den Startstatus, storniert wird von Hand).</p>
</section>
