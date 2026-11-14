<?php
/**
 * Help for «Mahnstufen» (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`,
 * opened with «Hilfe» at the right end of the toolbar. The ladder, the switch, the fee account, and
 * the dialog «Mahnstufe anlegen / bearbeiten» (a modal: its fields are explained here, one section
 * per field name). Static text.
 */
?>
<h3>Mahnstufen</h3>
<p>Die Stufen des Mahnwesens festlegen: Zahlungserinnerung, 1. und 2. Mahnung — wie viele Tage nach Fälligkeit sie kommen, welche Mahngebühr sie kosten und welcher Text auf der Mahnung steht.</p>
<p>Je Zeile: Code und Bezeichnung, Stufe, Tage nach Verfall, der Mindestabstand zur letzten Mahnung, die Gebühr und je Sprache ob ein Mahntext da ist. Oben steht das Konto, auf das Mahngebühren gebucht werden (beim Mandanten festgelegt) — mit einem roten Hinweis, wenn es fehlt oder nicht bebuchbar ist; dann bricht ein Mahnlauf mit Gebühr ab.</p>
<ul>
    <li><strong>+ Mahnstufe</strong> legt eine an; <strong>⋮</strong> öffnet eine zum Bearbeiten.</li>
    <li><strong>Schalter</strong> — nur aktive Stufen zählen im Mahnlauf; eine Rechnung steigt immer auf die nächste aktive Stufe über ihrer bisherigen, nach der letzten wird nicht mehr gemahnt. Bestehende Mahnungen behalten ihre Stufe. Gelöscht wird eine Stufe nie.</li>
    <li>Gemahnt wird unter Auftragswesen › Mahnungen.</li>
</ul>

<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den jede Mahnung speichert: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend (z.B. <code>dunning-1</code>). Nach dem Anlegen fix.</p>
</section>

<section data-help-field="label">
<h3>Bezeichnung</h3>
<p>Der Titel der Mahnung, so gedruckt, z.B. «Zahlungserinnerung» oder «1. Mahnung».</p>
</section>

<section data-help-field="level">
<h3>Stufe</h3>
<p>Die Reihenfolge im Mahnlauf, 1 bis 9; jede Zahl nur einmal. Eine Rechnung bekommt zuerst die kleinste aktive Stufe, dann die nächste.</p>
</section>

<section data-help-field="days_after_due">
<h3>Tage nach Verfall</h3>
<p>Frühestens so viele Tage nach dem Fälligkeitsdatum der Rechnung ist diese Stufe fällig (0–365).</p>
</section>

<section data-help-field="days_after_previous">
<h3>Tage nach der letzten Mahnung</h3>
<p>Frühestens so viele Tage nach der letzten Mahnung derselben Rechnung — auch wenn die Tage nach Verfall schon erreicht sind (0–365). Für die erste Mahnung einer Rechnung ohne Bedeutung.</p>
</section>

<section data-help-field="fee">
<h3>Mahngebühr</h3>
<p>Der Betrag, z.B. <code>20.00</code>; 0 = keine Gebühr. Der Mahnlauf erstellt dafür einen eigenen Gebühren-Beleg und verbucht ihn — <strong>ohne MWST</strong> (eine Mahngebühr ist keine Leistung). Die Mahnung druckt offenen Betrag und Gebühr mit einem QR-Zahlteil über das Total.</p>
</section>

<section data-help-field="document_text">
<h3>Text auf der Mahnung</h3>
<p>Der Text über der Tabelle der Mahnung, je Sprache ein Feld; gedruckt wird er in der Sprache des Kunden, sonst in der Standardsprache. Ist eine Sprache ausgefüllt, muss die Standardsprache es auch sein. Leer = die Mahnung zeigt nur Titel und Tabelle.</p>
</section>
