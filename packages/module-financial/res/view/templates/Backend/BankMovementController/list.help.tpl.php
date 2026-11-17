<?php
/**
 * Help for «Finanzen › Bankkonto» (ADR-048, HELP-003) — the list «Bewegungen» and the upload.
 * Everything the screen does not say stands here (backend-screen.md §5); field sections for the
 * search fields of the list. Also what the KI-Assistent knows (ADR-055).
 */
?>
<h3>Bankkonto — Kontoauszug und Bewegungen</h3>
<p>Hier liegt der <strong>Kontoauszug der Bank</strong> zum Nachschauen: wann wurde eine Lieferantenrechnung bezahlt, und wie viele Franken hat die Bank dafür belastet (zum Beispiel für 4.91 USD). <strong>Verbucht wird hier nichts</strong> — verbucht wird wie bisher im Kreditorenjournal, im Belegjournal und bei den Zahlungseingängen.</p>
<h3>Kontoauszug einlesen</h3>
<ul>
    <li>Im E-Banking den Kontoauszug als Datei <strong>camt.053</strong> herunterladen und mit <strong>camt.053 Upload</strong> einlesen (oder die Datei auf die Seite ziehen). XML oder ZIP, mehrere auf einmal.</li>
    <li>Die Antwort ist ein Satz: «45 neue Bewegungen, Saldo stimmt (eingelesen bis 31.03.2026 …)».</li>
    <li><strong>Eingelesen wird immer lückenlos.</strong> Die erste Datei setzt den Anfang — Früheres lässt sich später nicht mehr davor einfügen. Jede weitere Datei muss an «eingelesen bis» anschliessen: sie darf überlappen (das Doppelte wird erkannt und nicht nochmals gespeichert), aber sie darf keine Lücke lassen.</li>
    <li>Beginnt eine Datei zu spät, wird sie abgelehnt und nennt das Datum, ab dem die nächste Datei beginnen muss. Am einfachsten: im E-Banking immer ab dem Datum «eingelesen bis» exportieren.</li>
    <li>Stimmt der Saldo einer überlappenden Datei nicht mit dem gespeicherten überein, wird sie ebenfalls abgelehnt — den Auszug neu aus dem E-Banking laden.</li>
    <li>Mehrere Dateien: die älteste zuerst hochladen.</li>
</ul>
<h3>Die Liste «Bewegungen»</h3>
<p>Eine Zeile pro Buchung der Bank, die neuste zuoberst. Im Kopf steht, bis wann eingelesen ist und der Saldo an diesem Tag. Gibt es mehrere Bankkonten mit Kontoauszug, wählt man das Konto oben in der Schiene.</p>
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
<p>Steht hier «Rechnung … · Lieferant», hat diese Bewegung eine Lieferantenrechnung bezahlt. Zugeordnet wird nicht hier, sondern im <strong>Kreditorenjournal</strong>: Rechnungen mit QR-Referenz finden ihre Zahlung von selbst, für die übrigen gibt es dort «Zahlungen suchen …». Eine falsche Zuordnung löst du im Fenster der Rechnung.</p>
<p>Oben wechselst du zwischen <strong>Alle</strong> und <strong>Ohne Zuordnung</strong> — das sind die Bewegungen, die noch zu nichts gehören. Auf einem Konto, über das auch Privates läuft, bleiben viele davon stehen; das ist in Ordnung.</p>
<h3>Original und Saldo</h3>
<p><strong>Original</strong> steht nur bei Zahlungen in fremder Währung: der Betrag, wie er auf der Rechnung steht. <strong>Saldo</strong> ist der Kontostand nach der Bewegung.</p>
<h3>Was nicht geht</h3>
<ul>
    <li>Kreditkarten mit einer Sammelabrechnung pro Monat: die einzelnen Einkäufe stehen nicht auf dem Kontoauszug.</li>
    <li>Banken ohne camt.053 und Konten in fremder Währung.</li>
    <li>Gespeichert wird jede Bewegung des Kontos, auch private. Ob ein Konto eingelesen wird, bestimmt der Schalter «Kontoauszug einlesen» unter Stammdaten › Finanzen › Bankkonten.</li>
</ul>
