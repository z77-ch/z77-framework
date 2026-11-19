<?php
/**
 * Help for the window «Abgleich …» of «Finanzen › Bankkonto» (ADR-048; ADR-061 stage C4) —
 * everything the window does not say (backend-screen.md §5). Also what the KI-Assistent knows
 * (ADR-055).
 */
?>
<h3>Abgleich — stimmt die Buchhaltung mit der Bank?</h3>
<p>Der Abgleich vergleicht das <strong>Konto der Buchhaltung</strong> (z.B. 1020) mit dem <strong>Kontoauszug</strong>. Er gilt ab dem Datum «Abgleich ab» des Bankkontos (Stammdaten › Finanzen › Bankkonten).</p>
<p>Die Regel ist einfach: <strong>Was die Bank geschäftlich bewegt hat, muss in der Buchhaltung stehen — nicht mehr und nicht weniger.</strong> Private Bewegungen werden nie verbucht und zählen nicht mit.</p>

<h3>Die Differenz am Anfang</h3>
<p>Oben steht einmal «Bank … · Fibu … · Differenz …»: der Kontostand bei der Bank und der Saldo in der Buchhaltung am Tag «Abgleich ab».</p>
<ul>
<li>Bei einem reinen Geschäftskonto sollte die Differenz <strong>0.00</strong> sein.</li>
<li>Läuft über das Konto auch <strong>Privates</strong>, ist die Differenz nicht null — das Private war ja nie in der Buchhaltung. Das ist richtig so.</li>
</ul>
<p>Schau die drei Zahlen an und wähle <strong>Differenz bestätigen</strong>. Damit merkt sich z77 den Ausgangspunkt. Du machst das einmal. Das gibt es nur bei einem Konto, das «gemischt geführt» ist (nächster Abschnitt).</p>
<section data-help-field="difference">
<h3>Rein geschäftliches Konto: keine Differenz bestätigen</h3>
<p>Ist das Bankkonto <strong>nicht «gemischt geführt»</strong> (Stammdaten › Finanzen › Bankkonten), steht jede Bewegung in der Buchhaltung. Bank und Fibu müssen dann <strong>genau gleich</strong> sein — z77 erwartet die Differenz 0.00 und bietet «Differenz bestätigen» nicht an. Eine Differenz ist ein Fehler: meist fehlt eine Buchung, eine ist doppelt, oder die Eröffnung des Jahres stimmt nicht. Korrigiere sie mit einer Buchung. Läuft über das Konto doch Privates, schalte «gemischt geführt» ein — dann lässt sich die Differenz bestätigen.</p>
</section>
<p><strong>Neues Geschäftsjahr:</strong> z77 rechnet die Differenz selbst weiter (alte Differenz plus das Private des Jahres). Stimmt sie, steht «berechnet, stimmt» und du musst nichts tun. Nur wenn sie <strong>abweicht</strong>, fragt z77 nochmals — dann fehlt meist die Eröffnung des neuen Jahres oder im alten Jahr ist noch etwas offen.</p>
<p>Steht hier «nicht eingelesen», reicht der Kontoauszug nicht bis zu diesem Tag: zuerst einlesen, oder «Abgleich ab» auf den Beginn des Kontoauszugs setzen.</p>

<h3>Die Tabelle: eine Zeile pro Monat</h3>
<ul>
<li><strong>Bank geschäftlich</strong> — die Summe der geschäftlichen Bewegungen des Monats (Gutschriften minus Belastungen).</li>
<li><strong>Fibu</strong> — um wie viel sich das Konto der Buchhaltung im Monat verändert hat. Die Eröffnung des Jahres zählt nicht mit.</li>
<li><strong>Differenz</strong> — Fibu minus Bank. Muss <strong>0.00</strong> sein.</li>
<li><strong>Zu prüfen</strong> — Bewegungen, über die du noch nicht entschieden hast. Muss 0 sein.</li>
<li><strong>Ohne Bewegung</strong> — Buchungen auf dem Konto, zu denen es keine Bewegung der Bank im Monat gibt. Muss 0 sein.</li>
<li><strong>Stand</strong> — «ok», «offen» (etwas stimmt nicht) oder «nicht eingelesen» (der Kontoauszug reicht nicht bis zum Monatsende).</li>
</ul>

<h3>Buchungen ohne Bewegung</h3>
<p>Unter der Tabelle stehen diese Buchungen einzeln; die Nummer öffnet die Buchung. Die häufigsten Gründe:</p>
<ul>
<li>Eine <strong>Rechnung, die du früher von Hand als bezahlt markiert hast</strong>: im Kreditorenjournal «Zahlungen suchen …» — sie wird ihrer Bewegung zugeordnet, ohne dass neu gebucht wird.</li>
<li>Eine <strong>Buchung von Hand</strong> auf das Bankkonto: die Bewegung dazu fehlt. Entweder stornieren und die Bewegung unter «Bankkonto» mit «Geschäftlich buchen» verbuchen, oder die Bewegung ist privat und die Buchung gehört nicht hierher.</li>
<li>Ein <strong>Beleg</strong> aus dem Belegjournal, der über dieses Bankkonto bezahlt wurde.</li>
<li>Steht in der Spalte «Hinweis» ein Datum («Bewegung am …»), gibt es die Bewegung — aber in einem <strong>anderen Monat</strong> als die Buchung. Das kommt bei Kundenzahlungen vor, wenn die Bank an einem anderen Tag bucht, als die Zahlung verbucht ist.</li>
</ul>
<p><strong>Geschäftlich ohne Buchung</strong> heisst das Umgekehrte: eine Bewegung ist einer Rechnung oder Zahlung zugeordnet, aber diese ist noch nicht verbucht (z.B. «Journal verbuchen» im Kreditorenjournal steht noch aus).</p>

<h3>Übertrag eigene Bankkonten</h3>
<p>Geld von einem eigenen Geschäftskonto auf ein anderes buchst du auf beiden Seiten mit «Übertrag eigenes Konto». Das Durchgangskonto steht danach auf <strong>0.00</strong>. Steht hier ein Saldo, fehlt eine Seite. Welches Konto das ist, steht im Mandanten (Stammdaten › Firma › Mandant, Reiter «Konten»).</p>

<h3>Vorsteuer ohne Beleg</h3>
<p>So viele Buchungen hast du mit MWST-Code, aber ohne Beleg verbucht. Das ist erlaubt (unter dem eingestellten Betrag), aber dein Risiko bei einer MWST-Kontrolle. Die Zeile erscheint auch bei der MWST-Abrechnung als Hinweis; sie sperrt nichts.</p>

<h3>Was der Abgleich sperrt</h3>
<p>Der <strong>Jahresabschluss</strong> und die <strong>MWST-Abrechnung</strong> einer Periode sind gesperrt, solange für ein Bankkonto mit Abgleich:</p>
<ul>
<li>der Kontoauszug nicht bis zum Ende der Periode eingelesen ist,</li>
<li>Bewegungen der Periode «zu prüfen» sind,</li>
<li>die Differenz nicht 0.00 ist oder Buchungen ohne Bewegung dastehen,</li>
<li>das Konto «Übertrag eigene Bankkonten» nicht auf 0.00 steht.</li>
</ul>
<p>Schaltest du «Kontoauszug einlesen» beim Bankkonto aus oder nimmst «Abgleich ab» weg, hört der Abgleich auf. Was zugeordnet und gebucht ist, bleibt.</p>
