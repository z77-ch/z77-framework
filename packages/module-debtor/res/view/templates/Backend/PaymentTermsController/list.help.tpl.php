<?php
/**
 * Help for «Zahlungskonditionen» (ADR-048, HELP-003) — attached by `listAction()` with
 * `toolbar: true`, opened with «Hilfe» at the right end of the toolbar. The list, «Standard», the
 * switch, and the dialog «Zahlungskonditionen anlegen / bearbeiten» (a modal: its fields are
 * explained here, one section per field name). Static text.
 */
?>
<h3>Zahlungskonditionen</h3>
<p>Zahlungsfrist und Skonto für Rechnungen festlegen: «30 Tage netto», «10 Tage 2 %, 30 Tage netto» und was dazu auf der Rechnung steht — und welche Kondition neue Debitoren bekommen.</p>
<p>Je Zeile: Code und Bezeichnung, Frist und Skonto-Stufen, <strong>Standard</strong> (die Kondition neuer Debitoren), je Sprache ob ein Text für die Rechnung da ist, und wie viele Debitoren sie tragen.</p>
<ul>
    <li><strong>+ Kondition</strong> legt eine neue an; <strong>⋮</strong> öffnet eine zum Bearbeiten.</li>
    <li><strong>Schalter</strong> — inaktiv wird die Kondition für neue Debitoren und Rechnungen nicht mehr angeboten; wer sie schon trägt, behält sie. Gelöscht wird eine Kondition nie.</li>
    <li>Eine Änderung gilt für neue Rechnungen (und Entwürfe beim nächsten Speichern). Fakturierte Rechnungen behalten Fälligkeit, Skonto und Text, mit denen sie fakturiert wurden.</li>
</ul>
<p>Das Fälligkeitsdatum («Zahlbar bis») druckt jede Rechnung selbst. Das <strong>Skonto</strong> steht nur im Text — und wird nie automatisch abgezogen: bei der Zahlung im Feld «Skonto» erfassen.</p>

<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den Debitoren und Rechnungen speichern: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend (z.B. <code>net-30</code>). Nach dem Anlegen fix — für andere Konditionen eine neue anlegen.</p>
</section>

<section data-help-field="label">
<h3>Bezeichnung</h3>
<p>So heisst die Kondition in den Auswahllisten, z.B. «30 Tage netto».</p>
</section>

<section data-help-field="due_days">
<h3>Zahlungsfrist in Tagen</h3>
<p>Tage ab Rechnungsdatum bis zur Fälligkeit (0–365; 0 = zahlbar sofort). Daraus rechnet die Rechnung ihr «Zahlbar bis»; danach gilt sie als überfällig und kann gemahnt werden.</p>
</section>

<section data-help-field="default">
<h3>Standard</h3>
<p>Angekreuzt bekommen neue Debitoren diese Konditionen. Es gibt nur einen Standard — das Kreuz hier nimmt es der bisherigen. Eine inaktive Kondition kann nicht Standard sein.</p>
</section>

<section data-help-field="discount_days">
<h3>Skonto — Tage und Prozent</h3>
<p>Bis zu zwei Stufen, je Tage ab Rechnungsdatum und Prozent (z.B. 10 Tage, 2 %; Prozent wie «2» oder «2.5»). Leer lassen heisst kein Skonto. Die Tage liegen innerhalb der Zahlungsfrist, die frühere Stufe gibt mehr Prozent als die spätere.</p>
</section>

<section data-help-field="discount_percent">
<h3>Skonto in Prozent</h3>
<p>Der Abzug der Stufe in Prozent, über 0 und unter 100, höchstens zwei Dezimalen (z.B. <code>2</code> oder <code>2.5</code>).</p>
</section>

<section data-help-field="document_text">
<h3>Text auf dem Beleg</h3>
<p>Der Satz, der auf der Rechnung unter den Beträgen steht, z.B. «Zahlbar innert 30 Tagen netto, innert 10 Tagen mit 2 % Skonto.» Je Sprache ein Feld; die Rechnung nimmt den Text in der Sprache des Kunden, sonst den der Standardsprache. Ist eine Sprache ausgefüllt, muss die Standardsprache es auch sein. Leer = kein Satz.</p>
</section>
