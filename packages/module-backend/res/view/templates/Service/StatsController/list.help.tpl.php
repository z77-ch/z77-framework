<?php
/**
 * Help for the statistics page (ADR-048, HELP-003) — attached by `StatsController::listAction()`
 * with `toolbar: true`. Months, the client link, the monthly mail, «Bericht … senden», what is
 * counted. Static text, no context. Facts: stats.md (what counts, rollup 04:40, link validity).
 */
?>
<h3>Statistik</h3>
<p>Wie viele Besucher die Webseite hat: Besuche pro Monat, meistbesuchte Seiten, woher die Besucher kommen, Geräte, Link mit der Statistik für die Kundschaft, Monatsbericht per E-Mail senden.</p>
<p>Die Zahlen zählt die Installation selbst — ohne fremden Dienst und ohne Cookie. Gezählt werden Seitenaufrufe von Besuchern; Bots und angemeldete Redaktoren und Admins zählen nicht. Ein Besuch endet nach 30 Minuten ohne Seitenaufruf. Die Monatszahlen entstehen jede Nacht um 04:40 (Job <strong>Statistik-Verdichtung</strong>); der laufende Tag erscheint erst am nächsten Morgen.</p>

<h4>Monat wählen</h4>
<p>Die Knöpfe oben wählen den Monat; darunter steht der Bericht mit Vergleich zum Vormonat.</p>

<h4>Link für die Kundschaft</h4>
<p>Ein Link, der den gewählten Monat ohne Anmeldung öffnet, bis zum angegebenen Datum. Jeder Aufruf dieser Seite erzeugt einen neuen Link; alle bleiben bis zu ihrem Datum gültig. <strong>Öffnen</strong> zeigt die Seite so, wie die Kundschaft sie sieht.</p>

<h4>Monatsmail</h4>
<p>Zeigt, an wen der Bericht geht. Die Empfänger stehen unter Service › E-Mail (Eintrag «statsReport»). Automatisch versendet der Job <strong>Statistik-Bericht per Mail</strong>, wenn unter Service › Jobs ein Zeitplan gesetzt ist (empfohlen <code>monthly@1,06:00</code>): er schickt den Vormonat. <strong>Bericht … senden</strong> in der Werkzeugzeile schickt den gewählten Monat sofort. Jede Mail trägt einen eigenen, frischen Link.</p>
