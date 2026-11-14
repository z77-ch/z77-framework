<?php
/**
 * Help for one camt.054 message of «Zahlungseingänge» (ADR-048) — attached by `detailAction()`,
 * opened with «Hilfe» in the top bar. The transactions, their states and notes, «Zuordnen» /
 * «Zuordnen zu …» / «Ignorieren» / «Zurücknehmen», «Verbuchen» and what booking does with an
 * overpayment. One field section: the document number of «Zuordnen» (`number`).
 */
?>
<h3>Meldung — Zahlungseingänge prüfen und verbuchen</h3>
<p>Eine eingelesene camt.054-Meldung mit ihren Gutschriften: prüfen, welche Zahlung zu welcher Rechnung gehört, offene von Hand zuordnen oder ignorieren — und alles Zugeordnete verbuchen.</p>
<p>Je Zeile: Valuta, Betrag, der Zahler mit Referenz und Mitteilung, die zugeordnete Rechnung mit ihrem offenen Betrag (ein Klick öffnet sie), der <strong>Stand</strong> und ein <strong>Hinweis</strong>, warum eine Transaktion offen ist.</p>
<ul>
    <li><strong>nicht zugeordnet</strong> — keine passende Rechnung gefunden, die QR-Referenz ungültig, die Rechnung noch nicht verbucht, schon bezahlt …: der Hinweis sagt es.</li>
    <li><strong>zugeordnet</strong> — die Rechnung ist bestimmt, aber noch <strong>nichts verbucht</strong>. Nach der QR-Referenz ist das sicher; nach der Mitteilung («Rechnung 26100012») ist es ein Vorschlag — vor dem Verbuchen prüfen.</li>
    <li><strong>verbucht</strong> — die Zahlung steht auf der Rechnung («Zahlung #…»). Sie wird hier nicht mehr geändert; eine Korrektur macht man an der Zahlung in der Rechnung.</li>
    <li><strong>ignoriert</strong> — keine Kundenzahlung (Belastung, Rückbuchung, 0.00 — von selbst) oder von Hand beiseitegelegt; <strong>Zurücknehmen</strong> macht sie wieder offen.</li>
</ul>
<p><strong>Zuordnen zu …</strong> erscheint, wenn die Referenz eine Rechnung nennt, die inzwischen <strong>ersetzt</strong> ist: vorgeschlagen ist die neue Rechnung, ein Klick bestätigt (erst, wenn sie verbucht ist).</p>
<p><strong>Verbuchen</strong> (oben, solange etwas zugeordnet ist) legt je zugeordnete Transaktion die Zahlung auf der Rechnung an, mit dem Valutadatum und dem Konto des Zahlungsziels — alle zusammen oder keine. Mehr als offen: der offene Betrag wird gebucht, der Rest bezahlt offene Mahngebühren des Kunden, was dann bleibt, steht als <strong>Überzahlung</strong> da und wird von Hand erledigt. Ist die Rechnung inzwischen bezahlt oder liegt die Valuta vor dem Rechnungsdatum, wird nichts gebucht und die Transaktion ist wieder offen. Ein Skonto wird nie abgezogen — siehe Zahlung in der Rechnung.</p>
<p><strong>Zurück zur Liste</strong> führt zu allen Meldungen.</p>

<section data-help-field="number">
<h3>Rechnung Nr. — zuordnen</h3>
<p>Die Belegnummer der Rechnung (oder des Gebühren-Belegs), zu der die Zahlung gehört, z.B. <code>26100012</code>, dann <strong>Zuordnen</strong>. Nur eine verbuchte, nicht ersetzte oder gelöschte Rechnung lässt sich zuordnen. Zugeordnet ist noch nichts verbucht.</p>
</section>
