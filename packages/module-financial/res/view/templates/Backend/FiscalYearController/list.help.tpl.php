<?php
/**
 * Help for the list «Geschäftsjahre» (ADR-048, HELP-003: «Hilfe» at the right end of the toolbar) —
 * opening a year (next or prior), the year opening, closing in order, the admin reopen and the
 * guarded delete; every action of the year's ⋮ named as on screen (`actions.tpl.php`). Also the
 * KI-Assistent's knowledge (ADR-055): «Jahr abschliessen», «Eröffnung erstellen», «Vorjahr».
 */
?>
<h3>Geschäftsjahre</h3>
<p>Hier eröffnest und schliesst du <strong>Geschäftsjahre</strong> — ohne eröffnetes Jahr wird nichts gebucht. Auch: die Eröffnungsbilanz (Saldovortrag) erstellen, ein Jahr abschliessen oder wieder öffnen, ein falsch eröffnetes Jahr löschen.</p>
<ul>
    <li><strong>+ Geschäftsjahr</strong> öffnet «Geschäftsjahr eröffnen» — vorgeschlagen ist das <strong>nächste Jahr</strong> (es beginnt am Tag nach dem letzten). Mit <strong>«Vorjahr»</strong> eröffnest du stattdessen ein Jahr vor dem ersten, z.B. um ältere Buchungen nachzutragen.</li>
    <li>Pro Jahr: der Zustand <strong>offen</strong> oder <strong>abgeschlossen</strong>, die Monate, darunter der Stand der Eröffnung und die letzten Einträge des Protokolls (wer wann abgeschlossen oder wieder geöffnet hat).</li>
    <li>Das <strong>⋮</strong> vor dem Jahr zeigt, was mit diesem Jahr jetzt möglich ist — nur das.</li>
</ul>

<h3>Geschäftsjahr eröffnen</h3>
<p><strong>Beginn</strong> und <strong>Ende</strong> sind frei (z.B. ein verkürztes oder verlängertes erstes Jahr), höchstens 24 Monate. Die Jahre schliessen lückenlos aneinander an. Das <strong>Kürzel</strong> (z.B. 2026 oder 2026-27) wird aus den Daten vorgeschlagen und ist danach fix; es steht vor jeder Buchungsnummer (2026/17). Jedes Jahr hat seine eigene, lückenlose Nummernfolge.</p>

<h3>Eröffnung erstellen (Saldovortrag)</h3>
<p><strong>«Eröffnung erstellen …»</strong> im ⋮ eines offenen Jahres übernimmt die Schlusssalden des Vorjahres: pro Bilanzkonto eine Buchung am ersten Tag des Jahres gegen das Konto «Eröffnungsbilanz» (KMU 9100), der Gewinn oder Verlust des Vorjahres auf «Jahresgewinn oder Jahresverlust» (KMU 2979). Beide Konten stehen im Mandanten (Reiter «Konten»). Das Fenster zeigt vorher alle Zeilen.</p>
<p>Ändert sich das Vorjahr danach, steht unter dem Jahr <strong>«nicht aktuell»</strong> — dann <strong>«Eröffnung neu erstellen …»</strong>: geänderte Konten werden mit derselben Nummer nachgeführt, neue dazugebucht, weggefallene entfernt (alles im Änderungsprotokoll). Ist das Vorjahr noch nicht abgeschlossen, gilt die Eröffnung als <strong>provisorisch</strong>. Die Gewinnverwendung (z.B. auf Reserven) buchst du danach selbst.</p>

<h3>Jahr abschliessen</h3>
<p><strong>«Jahr abschliessen …»</strong> sperrt das ganze Jahr: danach wird darin nichts mehr gebucht, geändert oder gelöscht. Abgeschlossen wird <strong>der Reihe nach</strong> — zuerst das ältere Jahr. Vorher prüft das System, ob noch etwas offen ist:</p>
<ul>
    <li><strong>Sperrend</strong> (das Jahr lässt sich nicht abschliessen): Rechnungen und Gutschriften des Jahres, die noch Entwurf sind oder im Faktura-Journal auf «Journal verbuchen» warten, nicht verbuchte Zahlungseingänge, Belege im Belegjournal dieses Jahres.</li>
    <li><strong>Warnungen</strong> (abschliessen geht, aber nur mit Häkchen «bestätigt»): das Jahr ist noch nicht zu Ende, noch nicht fakturierte Aufträge, ein Sammelkonto (z.B. 1100 Debitoren) stimmt nicht mit den offenen Posten überein.</li>
</ul>

<h3>Wieder öffnen</h3>
<p><strong>«Wieder öffnen …»</strong> gibt es nur für das zuletzt abgeschlossene Jahr und nur für Administratoren. Ein <strong>Grund</strong> ist Pflicht; er steht mit Name und Zeitpunkt im Protokoll.</p>

<h3>Geschäftsjahr löschen</h3>
<p><strong>«Löschen …»</strong> erscheint nur beim ersten oder letzten Jahr und nur, solange darin <strong>nie</strong> gebucht wurde — auch eine gelöschte Buchung zählt, weil ihre Nummer verbraucht ist. Ein abgeschlossenes Jahr lässt sich nicht löschen — zuerst wieder öffnen. Nach dem Löschen lässt sich das Jahr mit demselben Kürzel neu eröffnen.</p>

<h3>Noch nicht verfügbar</h3>
<p><strong>MWST abrechnen</strong>: die MWST-Abrechnung lässt sich unter Finanzen › MWST-Abrechnungen lesen und drucken; das <strong>Buchen</strong> der Abrechnung und damit die Sperre einer abgerechneten Periode folgen. Ob eine Periode abgerechnet ist, steht bei den MWST-Abrechnungen — die Monate hier kennen nur «offen» und «abgeschlossen».</p>
