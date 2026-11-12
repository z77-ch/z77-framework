<?php
/**
 * Help for the payment-method window (ADR-048) — a section per field (`data-help-field` = the
 * field's `name`). Also the KI-Assistent's knowledge (ADR-055).
 */
?>
<h3>Zahlungsart</h3>
<p>Eine Zahlungsart sagt, <strong>wie ein Beleg bezahlt wurde</strong> — Kasse, Firmenkarte, privat ausgelegt. Auf dem Handy heisst die Auswahl <strong>«Bezahlt mit»</strong> und steht neben der Vorlage; im Belegjournal ebenso. Sie liefert das <strong>Haben-Konto</strong> des Belegs; die Vorlage liefert Soll und MWST.</p>
<p>Eine Änderung gilt für neue Belege; ein erfasster Beleg hat sein Haben beim Erfassen übernommen und behält es (im Belegjournal pro Beleg änderbar). Eine Zahlungsart wird nie gelöscht, nur <strong>deaktiviert</strong> (Schalter «Aktiv» in der Liste).</p>
<section data-help-field="label">
<h3>Name</h3>
<p>So steht die Zahlungsart auf dem Handy unter «Bezahlt mit», z.B. «Kasse», «Firmenkarte», «privat ausgelegt».</p>
</section>
<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den jeder Beleg speichert: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend. Nach dem Anlegen fix.</p>
</section>
<section data-help-field="credit_account">
<h3>Haben</h3>
<p>Das Konto, gegen das der Betrag gebucht wird: die Kasse (1000), das Abrechnungskonto der Firmenkarte, oder bei «privat ausgelegt» das Konto, über das die Auslage zurückbezahlt wird (z.B. ein Kontokorrent des Inhabers). Nur bebuchbare, aktive Konten.</p>
</section>
<section data-help-field="position">
<h3>Reihenfolge</h3>
<p>In welcher Folge die Zahlungsarten auf dem Handy und in dieser Liste stehen — kleinere Zahl zuerst. Mehrere dürfen dieselbe Zahl haben.</p>
</section>
