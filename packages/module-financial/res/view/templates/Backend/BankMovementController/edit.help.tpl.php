<?php
/**
 * Help for the window of a bank movement (ADR-048) — a general part and a section per field
 * (`data-help-field` = the name of the field, or its `data-help-key`). Everything the window does
 * not say stands here (backend-screen.md §5). Also what the KI-Assistent knows (ADR-055).
 *
 * @var \Z77\Shared\Money\Money|null $voucherFrom  from which amount a posting with VAT needs a file
 */
use Z77\Shared\Money\AmountFormat;

$from = isset($voucherFrom) ? AmountFormat::of($voucherFrom) . ' ' . $voucherFrom->currency : 'dem eingestellten Betrag';
?>
<h3>Eine Bankbewegung entscheiden</h3>
<p>Jede Bewegung ist <strong>geschäftlich</strong>, <strong>privat</strong> oder <strong>zu prüfen</strong>. In diesem Fenster entscheidest du das für eine Bewegung.</p>
<p>Steht bei «Stand» <strong>nur Auskunft</strong>, liegt die Bewegung vor dem Datum «Abgleich ab» des Bankkontos (oder das Bankkonto hat keines). Dann gibt es hier nichts zu entscheiden.</p>
<ul>
    <li><strong>Buchen</strong> — die Bewegung ist geschäftlich und wird jetzt verbucht (Datum = Buchungsdatum der Bank).</li>
    <li><strong>Privat</strong> — die Bewegung ist privat und wird nie verbucht.</li>
    <li>Gehört die Bewegung zu einer Lieferantenrechnung, buchst du sie <strong>nicht hier</strong>: die Rechnung im Kreditorenjournal erfassen, sie findet ihre Zahlung.</li>
</ul>
<section data-help-field="kind">
<h3>Buchen als</h3>
<ul>
    <li><strong>Konto</strong> — der Normalfall: du gibst das Gegenkonto an (Aufwand, Ertrag, Spesen …).</li>
    <li><strong>Privat</strong> — Geld, das du privat aus dem Geschäftskonto nimmst (Privatbezug) oder privat einlegst (Privateinlage). Das Gegenkonto ist das Privatkonto, immer ohne MWST.</li>
    <li><strong>Übertrag eigenes Konto</strong> — ein Übertrag zwischen zwei Geschäftskonten. Beide Seiten werden gegen das Übertragskonto gebucht: einmal hier, einmal beim anderen Bankkonto. Steht das Übertragskonto danach nicht auf null, fehlt eine Seite — der Abgleich meldet das und sperrt den Abschluss.</li>
    <li>Welche Konten «Privat» und «Übertrag eigenes Konto» sind, steht im <strong>Mandanten</strong> (Stammdaten › Firma › Mandant, Reiter «Konten»). Ist dort nichts eingetragen, wird die Wahl abgelehnt.</li>
</ul>
<p>Läuft über ein Konto auch Privates, ist ein Übertrag auf dieses Konto dort einfach «privat» — gebucht wird nur die Seite des reinen Geschäftskontos, als «Privat».</p>
</section>
<section data-help-field="contra">
<h3>Gegenkonto</h3>
<p>Das Konto, gegen das die Bank gebucht wird — bei einer Belastung meist ein Aufwandkonto (z.B. Bankspesen), bei einer Gutschrift ein Ertragskonto. Tippe die Nummer oder einen Teil des Namens. Nur bei «Buchen als: Konto» nötig.</p>
<p>z77 merkt sich Konto und MWST-Code pro Empfänger oder Geschäft und schlägt sie beim nächsten Mal vor.</p>
<p>Ab «Abrechnung ab» (Mandant, Reiter «UID und MWST») stehen die MWST-Konten nicht in der Liste: auf sie bucht nur die MWST-Abrechnung. Zahlungen an die ESTV, Akontozahlungen und Rückerstattungen gehen auf das MWST-Abrechnungskonto (Mandant, Reiter «Konten»). War ein MWST-Konto gemerkt, fällt der Vorschlag weg — eine Zeile unter dem Feld sagt es.</p>
</section>
<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Leer («ohne MWST») für Spesen, Zinsen, Privates und Überträge. Mit einem Code rechnet z77 die Steuer aus dem Betrag der Bank heraus (der Betrag ist brutto).</p>
<p>Ist die MWST der Periode schon abgerechnet, geht eine Buchung mit MWST-Code dort nicht mehr.</p>
</section>
<section data-help-field="voucher">
<h3>Beleg</h3>
<p>Die Quittung oder Rechnung als PDF oder Foto (JPEG, PNG), höchstens 10 MB.</p>
<ul>
    <li><strong>Mit MWST-Code und ab <?= e($from) ?></strong> ist der Beleg Pflicht — ohne ihn wird nicht gespeichert.</li>
    <li>Darunter ist er freiwillig. Fehlt er, kann die Steuerverwaltung bei einer Kontrolle den Vorsteuerabzug streichen — das Risiko trägst du.</li>
    <li>Ohne MWST-Code braucht es keinen: der eingelesene Kontoauszug ist der Beleg.</li>
</ul>
<p>Der Beleg wird unverändert abgelegt und lässt sich nicht mehr austauschen.</p>
</section>
<section data-help-field="text">
<h3>Text</h3>
<p>Der Buchungstext. Vorgeschlagen wird, was die Bank schreibt — du kannst ihn kürzen oder ergänzen.</p>
</section>
<section data-help-field="flip">
<h3>«… ist als privat gemerkt. Gibt es dort auch Geschäftliches?»</h3>
<p>Die Frage kommt, wenn du ein Geschäft als privat gemerkt hast und jetzt eine Bewegung davon geschäftlich ist — meist, weil eine Lieferantenrechnung sie gefunden hat.</p>
<ul>
    <li><strong>Ja — jedes Mal prüfen</strong>: bei diesem Geschäft kaufst du privat <em>und</em> geschäftlich ein. Die Bewegungen, die bisher privat waren, und alle künftigen stehen auf «zu prüfen»; du entscheidest jede einzeln. Alle offenen Fragen zu diesem Geschäft sind damit beantwortet.</li>
    <li><strong>Nein — nur diese Bewegung</strong>: das war eine Ausnahme. Das Geschäft bleibt privat, auch künftig — eine weitere geschäftliche Bewegung ohne Rechnung würde privat.</li>
</ul>
</section>
<section data-help-field="only_this">
<h3>Privat — nur diese Bewegung</h3>
<p>Ohne Häkchen merkt sich z77 den Empfänger oder das Geschäft: alle seine Bewegungen sind privat, auch die künftigen. Mit Häkchen gilt «privat» nur für diese eine Bewegung.</p>
</section>
<section data-help-field="near_amount">
<h3>Privat — nur bei ähnlichem Betrag</h3>
<p>Für einen Absender oder Empfänger, von dem nur ein bestimmter Betrag privat ist — z.B. die monatliche Rente. Privat sind dann nur Bewegungen mit etwa diesem Betrag (10 % mehr oder weniger).</p>
</section>
<section data-help-field="near_day">
<h3>Privat — nur um diesen Tag des Monats</h3>
<p>Privat sind dann nur Bewegungen, die um diesen Tag herum gebucht werden (3 Tage früher oder später) — z.B. eine Gutschrift, die jeden Monat am selben Tag kommt.</p>
</section>
<h3>Zu prüfen · Muster aufheben</h3>
<p>Bei einer privaten Bewegung: <strong>Zu prüfen</strong> setzt nur diese Bewegung zurück. <strong>Muster aufheben</strong> vergisst das ganze Geschäft — alle seine Bewegungen stehen wieder auf «zu prüfen».</p>
<h3>Buchung zurücknehmen</h3>
<p>Für eine Bewegung, die du hier verbucht hast: z77 macht die Gegenbuchung (Storno) mit demselben Datum, und die Bewegung steht wieder auf «zu prüfen». Der Beleg bleibt abgelegt.</p>
<p>Das geht nicht mehr, wenn das Geschäftsjahr abgeschlossen ist oder — bei einer Buchung mit MWST-Code — die MWST der Periode abgerechnet ist. Dann korrigierst du mit einer Buchung von Hand im Journal, in der offenen Periode.</p>
<p>Eine Zuordnung zu einer Lieferantenrechnung nimmst du nicht hier zurück, sondern im Fenster der Rechnung («Zuordnung lösen»).</p>
