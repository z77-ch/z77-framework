<?php
/**
 * Help for the payment form «Zahlung erfassen» / «Zahlung ändern» of a posted document (ADR-048) —
 * attached by `invoicePaymentPage()`, opened with «Hilfe» in the window's title bar. A section per
 * field (`data-help-field` = the field's `name`). Static text: no controller variables.
 */
?>
<h3>Zahlung erfassen</h3>
<p>Eine Zahlung von Hand auf eine verbuchte Rechnung oder Mahngebühr buchen — auch Skonto abziehen, Gebühren eines Zahlungsanbieters ausbuchen oder einen Rest als Verlust ausbuchen — oder eine erfasste Zahlung ändern oder löschen.</p>
<p>Oben steht der Beleg mit seinem <strong>offenen</strong> Betrag. <strong>Erfassen und verbuchen</strong> bucht sofort: die Zahlung auf das gewählte Konto, Skonto, Gebühren und Verlust auf die Konten beim Mandanten. Zusammen dürfen Zahlung, Skonto, Gebühren und Verlust den offenen Betrag nicht übersteigen. Eine bestehende Zahlung (in der Rechnung bei der Zahlung «ändern») wird mit <strong>Änderung verbuchen</strong> umgebucht oder mit <strong>Löschen …</strong> entfernt — beides nur, solange das Geschäftsjahr offen ist; danach korrigiert die Buchhaltung mit einer Buchung von Hand. Zahlungen von der Bank kommen besser über Zahlungseingänge (camt.054).</p>

<section data-help-field="date">
<h3>Valuta</h3>
<p>Der Tag, an dem das Geld eingegangen ist — an diesem Datum wird gebucht. Nicht vor dem Rechnungsdatum.</p>
</section>

<section data-help-field="payment">
<h3>Zahlung</h3>
<p>Der Betrag, der eingegangen ist. Weniger als offen: der Rest bleibt offen (Teilzahlung). Leer oder 0.00, wenn nur Skonto, Gebühren oder Verlust gebucht wird.</p>
</section>

<section data-help-field="account">
<h3>Konto</h3>
<p>Das Konto, auf dem das Geld eingegangen ist: die Bank (vorgeschlagen ist das Konto des Zahlungsziels der Rechnung), die Kasse oder ein anderes bebuchbares Konto. Die Liste schlägt die bebuchbaren Konten vor.</p>
</section>

<section data-help-field="discount">
<h3>Skonto</h3>
<p>Was der Kunde nach den Zahlungskonditionen abziehen durfte, als Betrag. Skonto wird nie automatisch abgezogen — auch nicht bei Zahlungen über Zahlungseingänge. Es kürzt Umsatz und MWST der Rechnung anteilig pro Steuercode.</p>
</section>

<section data-help-field="charges">
<h3>Gebühren</h3>
<p>Was zwischen dem Kunden und deinem Konto abgezogen wurde und <strong>dein Aufwand</strong> ist: die Kommission eines Kartenterminals oder Zahlungsanbieters (Kreditkarte, TWINT, PayPal …) oder Spesen, die die Bank des Zahlers abgezogen hat. Beispiel: Rechnung 100.00, ausbezahlt 97.50 — Zahlung 97.50, Gebühren 2.50.</p>
<p>Gebühren kürzen <strong>weder Umsatz noch MWST</strong> der Rechnung: der Kunde hat den vollen Betrag bezahlt, die Abrechnung deklariert ihn brutto (MWST-Info 07, Ziffer 1.1). Gebucht wird auf das Konto «Gebühren Zahlungsverkehr» beim Mandanten (KMU 6940 Bankspesen), ohne MWST-Code.</p>
<p>Nicht für Abzüge, die du dem Kunden gewährst (das ist <strong>Skonto</strong>), und nicht für Beträge, die nicht mehr eingehen (das ist <strong>Verlust</strong>) — nur diese beiden kürzen Umsatz und MWST.</p>
</section>

<section data-help-field="loss">
<h3>Verlust</h3>
<p>Was nicht mehr bezahlt wird und abgeschrieben wird (Debitorenverlust). Kürzt wie Skonto Umsatz und MWST anteilig.</p>
<p>Weil Skonto und Verlust die MWST kürzen, gehen sie nicht in eine MWST-Periode, deren Abrechnung schon gebucht ist: eine neue Zahlung mit Valuta dort nur ohne Skonto und Verlust erfassen — diese separat mit einem Datum in der laufenden Periode. Eine Zahlung, die dort schon Skonto oder Verlust trägt, lässt sich nicht mehr ändern oder löschen.</p>
</section>

<section data-help-field="rest_loss">
<h3>Rest als Verlust ausbuchen</h3>
<p>Angekreuzt füllt das Speichern den Verlust mit dem, was nach Zahlung, Skonto und Gebühren noch offen ist — der Beleg ist danach erledigt. Praktisch für einen kleinen Rest, den niemand mehr einfordert.</p>
</section>

<section data-help-field="note">
<h3>Bemerkung</h3>
<p>Freier Text zur Zahlung, höchstens 140 Zeichen — steht bei der Zahlung in den Fakten der Rechnung.</p>
</section>
