<?php
/**
 * Help for «Zahlungsziele» (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`,
 * opened with «Hilfe» at the right end of the toolbar. The list, the switch, and the dialog
 * «Zahlungsziel anlegen / bearbeiten» (a modal: its fields are explained here, one section per
 * field name; the six holder fields share one section, `holder_name`). Static text.
 */
?>
<h3>Zahlungsziele</h3>
<p>Die eigenen Bankkonten, auf die Kunden bezahlen: IBAN oder QR-IBAN für den QR-Einzahlungsschein der Rechnung, das Konto in der Buchhaltung und der Zahlungsempfänger.</p>
<p>Ohne aktives Zahlungsziel hat eine Rechnung keinen Zahlteil, und Zahlungseingänge (camt.054) werden nur zu einem erfassten Konto eingelesen.</p>
<p><strong>Barverkauf (Kasse):</strong> Ein Zahlungsziel ist immer ein <strong>Bankkonto</strong> — IBAN oder QR-IBAN ist Pflicht. Für die Kasse wird keines angelegt. Eine bar bezahlte Rechnung: in der Rechnung (Auftragswesen › Rechnungen) beim Zahlungsziel <strong>kein Zahlteil</strong> wählen, nach dem Verbuchen die Zahlung mit <strong>Zahlung erfassen</strong> auf Konto <code>1000</code> Kasse buchen.</p>
<p>Ein <strong>Auftrag</strong> übernimmt beim Anlegen das erste aktive Zahlungsziel; im Auftrag lässt es sich nicht wählen. Die Rechnung, die «Fakturieren …» im Auftrag erstellt, trägt darum dessen QR-Zahlteil. Wird bar bezahlt, bleibt der Zahlteil ungenutzt — die Zahlung ebenso mit «Zahlung erfassen» auf <code>1000</code> Kasse buchen.</p>
<p>Je Zeile: Code und Bezeichnung, die QR-IBAN und / oder IBAN, das Buchhaltungskonto und der <strong>Empfänger</strong>, wie er auf dem Zahlteil steht («vom Mandanten», wenn die Felder leer sind). <strong>Konto prüfen</strong> heisst: das Konto fehlt in der Buchhaltung, ist eine Gruppe oder inaktiv.</p>
<ul>
    <li><strong>+ Zahlungsziel</strong> legt eines an; <strong>⋮</strong> öffnet eines zum Bearbeiten.</li>
    <li><strong>Schalter</strong> — inaktiv wird das Zahlungsziel für neue Rechnungen nicht mehr angeboten und nimmt keine neuen Zahlungseingänge an; ausgestellte Rechnungen behalten ihren Zahlteil. Gelöscht wird ein Zahlungsziel nie.</li>
    <li>Eine Änderung (z.B. eine neue QR-IBAN) gilt für Rechnungen, die danach fakturiert werden; der Zahlteil einer fakturierten Rechnung bleibt, wie er gedruckt wurde.</li>
</ul>

<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den Rechnungen und Zahlungen speichern: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend (z.B. <code>bank</code>). Nach dem Anlegen fix.</p>
</section>

<section data-help-field="label">
<h3>Bezeichnung</h3>
<p>So heisst das Zahlungsziel in der Auswahl der Rechnung, z.B. «Bank Geschäftskonto».</p>
</section>

<section data-help-field="qr_iban">
<h3>QR-IBAN</h3>
<p>Die QR-IBAN des Kontos (von der Bank, Stellen 5–9 zwischen 30000 und 31999). Mit ihr trägt die Rechnung eine <strong>QR-Referenz</strong> (Kundennummer und Rechnungsnummer), über die eine Zahlung beim Einlesen sicher zugeordnet wird. Ist sie erfasst, druckt die Rechnung sie — auch wenn eine IBAN daneben steht. Beispiel: <code>CH44 3199 9123 0008 8901 2</code>.</p>
</section>

<section data-help-field="iban">
<h3>IBAN</h3>
<p>Die normale IBAN des Kontos, für einen Zahlteil <strong>ohne Referenz</strong>: die Rechnung wird dann in der Mitteilung genannt («Rechnung 26100012»). Mindestens eine der beiden IBAN ist nötig. Nur Schweiz und Liechtenstein (21 Zeichen); Prüfziffern werden geprüft. Beispiel: <code>CH93 0076 2011 6238 5295 7</code>.</p>
</section>

<section data-help-field="account_number">
<h3>Konto in der Buchhaltung</h3>
<p>Die Kontonummer der Bank im Kontenplan, z.B. <code>1020</code>: darauf werden die Zahlungen gebucht, die über dieses Zahlungsziel eingehen. Mit der Buchhaltung im System wird geprüft, dass es das Konto gibt und es bebuchbar ist.</p>
</section>

<section data-help-field="holder_name">
<h3>Zahlungsempfänger</h3>
<p>Kontoinhaber, Strasse, Nr., PLZ, Ort und Land, wie sie bei der Bank registriert sind — sie stehen auf dem QR-Zahlteil. Leer = die Angabe des Mandanten (sie steht grau im Feld). Nur ausfüllen, wenn der Kontoinhaber anders lautet als der Mandant. Land: zwei Buchstaben, z.B. <code>CH</code>.</p>
</section>
