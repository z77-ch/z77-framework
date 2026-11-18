<?php
/**
 * Help for «Finanzen › Bankkonto» (ADR-048, HELP-003) — the list «Bewegungen», its states and the
 * upload. Everything the screen does not say stands here (backend-screen.md §5); field sections
 * for the search fields of the list. Also what the KI-Assistent knows (ADR-055).
 *
 * @var \Z77\Shared\Money\Money|null $voucherFrom  from which amount a posting with VAT needs a file
 */
use Z77\Shared\Money\AmountFormat;

$from = isset($voucherFrom) ? AmountFormat::of($voucherFrom) . ' ' . $voucherFrom->currency : 'dem eingestellten Betrag';
?>
<h3>Bankkonto — Kontoauszug und Bewegungen</h3>
<p>Hier liegt der <strong>Kontoauszug der Bank</strong>. Hat das Bankkonto ein Datum «Abgleich ab» (Stammdaten › Finanzen › Bankkonten), ist jede Bewegung ab diesem Tag entweder <strong>geschäftlich</strong>, <strong>privat</strong> oder <strong>zu prüfen</strong>. Nichts wird von selbst privat und nichts wird von selbst verbucht: was du nicht entschieden hast, bleibt «zu prüfen».</p>
<p>Bewegungen <strong>vor</strong> «Abgleich ab» — und alle Bewegungen eines Bankkontos ohne dieses Datum — sind <strong>nur Auskunft</strong>: die Spalte «Stand» ist leer, es gibt nichts zu entscheiden.</p>
<h3>Die drei Stände</h3>
<ul>
    <li><strong>zu prüfen</strong> — noch nicht entschieden. Das ist deine Arbeitsliste (oben «Zu prüfen»).</li>
    <li><strong>geschäftlich</strong> — die Bewegung gehört zu einer Rechnung oder Zahlung (Spalte «Zuordnung»), oder du hast sie hier mit «Geschäftlich buchen» verbucht.</li>
    <li><strong>privat</strong> — du hast sie als privat gemerkt. Private Bewegungen werden nie verbucht.</li>
</ul>
<h3>Privat merken</h3>
<p>Öffne eine Bewegung (Symbol links) und wähle <strong>Privat</strong> — oder kreuze mehrere an und wähle über der Liste <strong>Privat …</strong>. z77 merkt sich dabei den <strong>Empfänger oder das Geschäft</strong>: alle Bewegungen desselben Geschäfts sind damit privat, auch die künftigen. Einmal «Privat» beim Lebensmittelladen genügt.</p>
<p>Eine <strong>Rechnung geht vor</strong>: findet eine Lieferantenrechnung ihre Zahlung bei einem Geschäft, das du als privat gemerkt hast, ist diese Bewegung geschäftlich. Über der Liste steht dann ein roter Hinweis — mit «Entscheiden …» sagst du, ob das Geschäft künftig geprüft werden soll.</p>
<p>Eine <strong>Sammelgutschrift von QR-Zahlungen</strong> auf dem Konto der Zahlungseingänge lässt sich nicht privat setzen: das sind Kundenzahlungen. Zugeordnet werden sie unter Debitoren › Zahlungseingänge mit «Bankabgleich …».</p>
<h3>Geschäftlich buchen</h3>
<p>Für Bewegungen ohne Rechnung im Kreditorenjournal — Bankspesen, Zinsen, ein Einkauf mit Karte, ein Privatbezug: Bewegung öffnen, Gegenkonto wählen, <strong>Buchen</strong>. Die Einzelheiten stehen in der Hilfe des Fensters. Eine falsche Buchung nimmst du im selben Fenster mit «Buchung zurücknehmen» wieder zurück.</p>
<p>Mit MWST-Code und ab <?= e($from) ?> braucht die Buchung einen <strong>Beleg</strong> (PDF oder Foto).</p>
<h3>Abgleich …</h3>
<p>Oben neben den Ansichten steht <strong>Abgleich …</strong> (nur bei einem Bankkonto mit «Abgleich ab»). Das Fenster zeigt pro Monat, ob die Buchhaltung mit der Bank übereinstimmt, und nennt Buchungen ohne Bewegung. Solange dort etwas offen ist, sind Jahresabschluss und MWST-Abrechnung gesperrt. Einzelheiten in der Hilfe des Fensters.</p>
<h3>Kontoauszug einlesen</h3>
<ul>
    <li>Ein Klick auf <strong>camt.053 Upload</strong> zeigt zuerst pro Bankkonto, bis wann eingelesen ist und <strong>ab welchem Datum</strong> du im E-Banking den nächsten Kontoauszug (camt.053) laden musst («Laden ab»). Von dort wählst du die Dateien. Oder du ziehst die Dateien direkt auf die Seite — dann werden sie sofort eingelesen. XML oder ZIP, mehrere auf einmal.</li>
    <li>Die Antwort ist ein Satz: «45 neue Bewegungen, Saldo stimmt (eingelesen bis 31.03.2026 …)».</li>
    <li><strong>Eingelesen wird immer lückenlos.</strong> Die erste Datei setzt den Anfang — Früheres lässt sich später nicht mehr davor einfügen. Jede weitere Datei muss den <strong>letzten eingelesenen Tag überlappen</strong>: sie beginnt am Datum «eingelesen bis» oder früher. Das Doppelte wird erkannt und nicht nochmals gespeichert.</li>
    <li>Warum die Überlappung: hast du den letzten Auszug tagsüber geladen, kann die Bank am selben Tag noch gebucht haben. Nur ein Auszug, der diesen Tag nochmals enthält, bringt diese Buchungen sicher mit. Darum genügt auch der Folgetag nicht, und ein Wochenende dazwischen erst recht nicht.</li>
    <li>Beginnt eine Datei zu spät, wird sie abgelehnt und nennt das Datum, ab dem du laden musst.</li>
    <li>Das ZIP der Bank enthält oft einen Auszug für <strong>jedes Konto</strong> des Vertrags. Konten, die nicht als Bankkonto angelegt sind, werden übersprungen und am Schluss in einer Zeile genannt. Fehlt dort ein Geschäftskonto: unter Stammdaten › Finanzen › Bankkonten anlegen.</li>
    <li>Stimmt der Saldo einer überlappenden Datei nicht mit dem gespeicherten überein, wird sie ebenfalls abgelehnt — den Auszug neu aus dem E-Banking laden.</li>
    <li>Mehrere Dateien: die älteste zuerst hochladen.</li>
</ul>
<h3>Die Liste «Bewegungen»</h3>
<p>Eine Zeile pro Buchung der Bank, die neuste zuoberst. Im Kopf steht, bis wann eingelesen ist und der Saldo an diesem Tag. Oben wechselst du zwischen <strong>Alle</strong>, <strong>Zu prüfen</strong>, <strong>Geschäftlich</strong> und <strong>Privat</strong>. Gibt es mehrere Bankkonten mit Kontoauszug, wählt man das Konto oben in der Schiene.</p>
<section data-help-field="f_date">
<h3>Datum</h3>
<p>Das <strong>Buchungsdatum der Bank</strong>. Bei Kartenzahlungen steht das Datum des Einkaufs im Text. Suchen: ein Tag (30.03.2026), ein Monat (03.2026) oder ein Jahr (2026).</p>
</section>
<section data-help-field="f_text">
<h3>Text</h3>
<p>Der Empfänger oder Absender und was die Bank dazu schreibt. Bei Karte und TWINT steht der Händler nach «Bezugsort:». Die Suche findet auch die Referenz einer QR-Rechnung.</p>
</section>
<section data-help-field="f_amount">
<h3>Betrag</h3>
<p>Belastungen mit Minus, Gutschriften ohne. Die Suche findet den Betrag in Franken <strong>und</strong> den Originalbetrag: «4.91» findet die Zahlung über 4.91 USD.</p>
</section>
<h3>Zuordnung</h3>
<p>Steht hier «Rechnung … · Lieferant», hat diese Bewegung eine Lieferantenrechnung bezahlt. Das geschieht im <strong>Kreditorenjournal</strong>: Rechnungen mit QR-Referenz finden ihre Zahlung von selbst, für die übrigen gibt es dort «Zahlungen suchen …». Eine falsche Zuordnung löst du im Fenster der Rechnung. Steht hier «Buchung …», hast du die Bewegung hier verbucht.</p>
<h3>Original und Saldo</h3>
<p><strong>Original</strong> steht nur bei Zahlungen in fremder Währung: der Betrag, wie er auf der Rechnung steht. <strong>Saldo</strong> ist der Kontostand nach der Bewegung.</p>
<h3>Was nicht geht</h3>
<ul>
    <li>Kreditkarten mit einer Sammelabrechnung pro Monat: die einzelnen Einkäufe stehen nicht auf dem Kontoauszug.</li>
    <li>Banken ohne camt.053 und Konten in fremder Währung.</li>
    <li>Eine Bewegung nur zum Teil geschäftlich buchen — sie wird als Ganzes verbucht oder ist privat.</li>
    <li>Gespeichert wird jede Bewegung des Kontos, auch private. Ob ein Konto eingelesen wird, bestimmt der Schalter «Kontoauszug einlesen» unter Stammdaten › Finanzen › Bankkonten.</li>
</ul>
