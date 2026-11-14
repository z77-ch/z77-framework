<?php
/**
 * Help for the payment form «Zahlung erfassen» / «Zahlung ändern» of a posted document (ADR-048) —
 * attached by `invoicePaymentPage()`, opened with «Hilfe» in the window's title bar. A section per
 * field (`data-help-field` = the field's `name`). Static text: no controller variables.
 */
?>
<h3>Zahlung erfassen</h3>
<p>Eine Zahlung von Hand auf eine verbuchte Rechnung oder Mahngebühr buchen — auch Skonto abziehen oder einen Rest als Verlust ausbuchen — oder eine erfasste Zahlung ändern oder löschen.</p>
<p>Oben steht der Beleg mit seinem <strong>offenen</strong> Betrag. <strong>Erfassen und verbuchen</strong> bucht sofort: die Zahlung auf das gewählte Konto, Skonto und Verlust auf die Konten beim Mandanten. Zusammen dürfen Zahlung, Skonto und Verlust den offenen Betrag nicht übersteigen. Eine bestehende Zahlung (in der Rechnung bei der Zahlung «ändern») wird mit <strong>Änderung verbuchen</strong> umgebucht oder mit <strong>Löschen …</strong> entfernt — beides nur, solange das Geschäftsjahr offen ist; danach korrigiert die Buchhaltung mit einer Buchung von Hand. Zahlungen von der Bank kommen besser über Zahlungseingänge (camt.054).</p>

<section data-help-field="date">
<h3>Valuta</h3>
<p>Der Tag, an dem das Geld eingegangen ist — an diesem Datum wird gebucht. Nicht vor dem Rechnungsdatum.</p>
</section>

<section data-help-field="payment">
<h3>Zahlung</h3>
<p>Der Betrag, der eingegangen ist. Weniger als offen: der Rest bleibt offen (Teilzahlung). Leer oder 0.00, wenn nur Skonto oder Verlust gebucht wird.</p>
</section>

<section data-help-field="account">
<h3>Konto</h3>
<p>Das Konto, auf dem das Geld eingegangen ist: die Bank (vorgeschlagen ist das Konto des Zahlungsziels der Rechnung), die Kasse oder ein anderes bebuchbares Konto. Die Liste schlägt die bebuchbaren Konten vor.</p>
</section>

<section data-help-field="discount">
<h3>Skonto</h3>
<p>Was der Kunde nach den Zahlungskonditionen abziehen durfte, als Betrag. Skonto wird nie automatisch abgezogen — auch nicht bei Zahlungen über Zahlungseingänge. Es kürzt Umsatz und MWST der Rechnung anteilig pro Steuercode.</p>
</section>

<section data-help-field="loss">
<h3>Verlust</h3>
<p>Was nicht mehr bezahlt wird und abgeschrieben wird (Debitorenverlust). Kürzt wie Skonto Umsatz und MWST anteilig.</p>
</section>

<section data-help-field="rest_loss">
<h3>Rest als Verlust ausbuchen</h3>
<p>Angekreuzt füllt das Speichern den Verlust mit dem, was nach Zahlung und Skonto noch offen ist — der Beleg ist danach erledigt. Praktisch für einen kleinen Rest, den niemand mehr einfordert.</p>
</section>

<section data-help-field="note">
<h3>Bemerkung</h3>
<p>Freier Text zur Zahlung, höchstens 140 Zeichen — steht bei der Zahlung in den Fakten der Rechnung.</p>
</section>
