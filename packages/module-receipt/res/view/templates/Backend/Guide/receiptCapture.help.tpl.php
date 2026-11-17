<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — receipts across the screens: the phone app
 * (capture), the master data (Belegvorlagen, Zahlungsarten), the Belegjournal (check, split,
 * delete) and «Journal verbuchen», the line to the creditor journal, plus what is not built yet.
 * Not attached to a screen: `HelpKnowledge` indexes every `*.help.tpl.php` under the module's
 * template root. Static text only — no controller variables.
 */
?>
<h3>Belege erfassen: App, Belegjournal, Vorlagen, Zahlungsarten</h3>
<p>Wie ein Kassenzettel oder eine Quittung vom Handy in die Buchhaltung kommt: Beleg fotografieren, im Belegjournal prüfen, ergänzen oder aufteilen, dann «Journal verbuchen» — und was dafür einmal eingerichtet wird.</p>

<h4>Beleg oder Lieferantenrechnung?</h4>
<p>Das Belegjournal ist für Belege, die mit dem Handy fotografiert werden und schon bezahlt sind — ohne Lieferant und Rechnungsnummer; Lieferantenrechnungen als PDF gehören ins Kreditorenjournal — in Arbeit, folgt.</p>

<h4>1. Einrichten: Belegvorlagen, Zahlungsarten, Handy-Zugang</h4>
<ul>
    <li><strong>Belegvorlagen</strong> (Stammdaten › Finanzen › Belegvorlagen): die Kacheln auf dem Handy, z.B. «Tanken», «Büromaterial». Jede sagt, <strong>was</strong> gekauft wurde: Soll-Konto und MWST-Code.</li>
    <li><strong>Zahlungsarten</strong> (Stammdaten › Finanzen › Zahlungsarten): die Auswahl «Bezahlt mit» — Kasse, Firmenkarte, privat ausgelegt. Jede liefert das Haben-Konto.</li>
    <li><strong>Handy-Zugang</strong> (Stammdaten › System › App-Benutzer): ein App-Benutzer mit der Funktion «Belege erfassen», verknüpft mit einer Person, die im Backend mindestens die Rolle «Redaktor» hat. Das Handy meldet sich unter <code>/app</code> an.</li>
</ul>

<h4>2. Beleg mit dem Handy fotografieren und senden</h4>
<p>In der App eine Kachel tippen (oder «Ohne Vorlage»), den Beleg fotografieren, <strong>Betrag</strong> (inkl. MWST) eintippen, Datum (heute) und Text prüfen, «Bezahlt mit» wählen, senden. Die Bestätigung lautet «Beleg erfasst»; ohne Netz «Beleg gespeichert» — er wird später gesendet. Der Beleg steht danach im <strong>Belegjournal</strong>, in der Buchhaltung noch <strong>nicht</strong>. Das Foto liegt in den Dokumenten (Drive) unter «Belege/Jahr/Monat».</p>

<h4>3. Belege im Belegjournal prüfen, aufteilen, löschen</h4>
<p><strong>Finanzen › Belegjournal</strong>: jeder Beleg mit Foto, Betrag, Soll, Haben, MWST. «vollständig» heisst: Soll, Haben, die MWST-Entscheidung (Code oder «ohne MWST») und ein Text sind gesetzt; sonst steht «Konten fehlen» (bzw. «Text fehlt»). Das Zeichen am Zeilenanfang öffnet den Beleg: Felder korrigieren, eine andere Vorlage oder Zahlungsart wählen (füllt Soll / MWST bzw. Haben), <strong>«Aufteilen …»</strong> für einen Zettel mit zwei MWST-Sätzen, <strong>«Löschen …»</strong> für einen Fehlgriff (das Foto bleibt, ausser «Foto auch löschen»).</p>

<h4>4. Journal verbuchen: Stichtag, alles oder nichts, Aufbewahrung</h4>
<p><strong>«Journal verbuchen …»</strong> mit einem Stichtag (vorgeschlagen heute): alle Belege bis und mit dem Stichtag werden in einem Schritt gebucht — jeder als eigene Buchung mit seinem Belegdatum, die MWST aus dem Betrag herausgerechnet und auf das Vorsteuerkonto des Mandanten gebucht. Alles oder nichts: ist ein Beleg unvollständig, hat sein Datum kein Geschäftsjahr, liegt es in einer abgeschlossenen Periode (oder in einer, deren MWST schon abgerechnet ist) oder lassen sich seine Konten so nicht buchen, zeigt das Fenster nur die Fehler. Danach steht der Lauf unter «Verbuchte Journale», die Belege sind im Journal (Finanzen › Journal) mit dem Blitz-Zeichen und im Belegjournal nicht mehr änderbar. Das Foto eines verbuchten Belegs wird 10 Jahre nach Ende seines Geschäftsjahres aufbewahrt (gesetzliche Aufbewahrungspflicht) und lässt sich bis dahin nicht endgültig löschen — auch nicht über den Papierkorb der Dokumente.</p>
<p>Solange Belege eines Jahres im Belegjournal stehen, lässt sich das Jahr nicht abschliessen, und die Auswertungen weisen in einem Hinweis darauf hin.</p>

<h4>5. Mit Bank oder Karte bezahlt: die Zahlung auf dem Kontoauszug finden</h4>
<p>Liest du den Kontoauszug ein (Finanzen › Bankkonto), findet ein Beleg, der mit Bank oder Karte bezahlt ist, dort seine Bewegung: <strong>«Zahlungen suchen …»</strong> im Belegjournal zeigt zu jedem Beleg die Bewegungen mit gleichem Betrag um das Belegdatum — genau eine ist schon angekreuzt, bei mehreren wählst du. Übernommen gilt die Bewegung als geschäftlich. Im Beleg steht dann «Bank · Datum · Betrag»; «Zuordnung lösen» nimmt es zurück. Belege aus der Kasse haben keine Bankbewegung.</p>

<h4>Noch nicht verfügbar</h4>
<ul>
    <li><strong>Einen verbuchten Beleg korrigieren</strong> — folgt. Bis dahin im Journal eine Korrekturbuchung von Hand erfassen.</li>
    <li><strong>«Journal drucken»</strong> (die Belegliste als PDF), Zoomen oder Drehen des Fotos im Fenster.</li>
    <li>Die Belege eines bestimmten Handys oder App-Benutzers gesammelt anzeigen; aus einer Buchung im Journal direkt zum Foto springen.</li>
    <li>Eine Meldung auf der Startseite («n Belege im Belegjournal»).</li>
    <li>Die <strong>MWST-Abrechnung</strong> — folgt; sobald es sie gibt, sperren noch nicht verbuchte Belege ihres Zeitraums sie wie den Jahresabschluss.</li>
    <li>Die Mehrwertsteuerpflicht des Mandanten gilt für Belege nicht: ist die Firma nicht mehrwertsteuerpflichtig, für Vorlagen und Belege «ohne MWST» wählen.</li>
    <li>Wie lange ein Foto noch aufbewahrt werden muss, zeigen die Dokumente (Drive) erst, wenn das endgültige Löschen abgelehnt wird.</li>
</ul>
