<?php
/**
 * Help of the backend entry page (ADR-048, ADR-052) — attached by
 * `DashboardController::overviewAction()`, opened with «Hilfe» in the top bar.
 */
?>
<h3>Übersicht</h3>
<p>Die Startseite nach der Anmeldung: was offen ist (überfällige Rechnungen, fehlgeschlagene Jobs, Backup zu alt …), schnell etwas Neues erfassen und in jeden Bereich springen.</p>
<p>Eine <strong>Kachel</strong> pro Bereich des Menüs, mit seinen Seiten als Links. Es erscheint nur, was der angemeldete Benutzer öffnen darf — Bereiche, Links, Zahlen und Knöpfe.</p>
<p><strong>+ Neuer …</strong> im Kopf einer Kachel (z.B. «+ Neuer Kontakt», «+ Neuer Auftrag») öffnet die Erfassung direkt, wie der Knopf oben links in der Liste.</p>
<p>Die <strong>Marken</strong> einer Kachel zeigen offene Arbeit des Bereichs: rot = <strong>dringend</strong>, gelb = <strong>zu erledigen</strong>. Ein Klick öffnet die passende Liste, wo nötig schon gefiltert (z. B. Debitoren › überfällig).</p>
<p><strong>Pendenzen</strong> unter den Kacheln: alles Offene aus allen Bereichen, Dringendes zuerst. «Nichts offen» heisst: es wartet nichts.</p>
<p><strong>Ersteinrichtung:</strong> Solange die Installation noch nicht fertig eingerichtet ist, stehen unter den Pendenzen die nächsten Schritte in der richtigen Reihenfolge — zum Beispiel «Kontenplan übernehmen», «Mandant erfassen», «Geschäftsjahr eröffnen», «Artikel erfassen». Es sind immer nur die nächsten drei; ist ein Schritt erledigt, verschwindet er und der folgende erscheint. Ein Klick öffnet die Seite, auf der er erledigt wird.</p>
<p>Die Zahlen sind live: neu laden zeigt den aktuellen Stand.</p>
