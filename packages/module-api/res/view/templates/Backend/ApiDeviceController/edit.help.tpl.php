<?php
/**
 * Help of the device window (ADR-048) — a general part and a section per field.
 */
?>
<h3>Gerät</h3>
<p>Ein gekoppeltes Handy oder ein PC. Ändern lassen sich nur der <strong>Name</strong> und die <strong>Berechtigungen</strong>; die Zeile darunter zeigt, was der Browser beim Koppeln über sich sagte, wann gekoppelt wurde und wann das Gerät zuletzt etwas gesendet hat (höchstens stündlich nachgeführt).</p>
<p><strong>«Sperren …»</strong> nimmt dem Gerät jede Berechtigung: ab sofort wird alles abgewiesen, was es sendet, auch was noch in seiner Warteschlange liegt. Was es bisher übermittelt hat, bleibt erhalten. Sperren ist <strong>endgültig</strong> — soll das Handy wieder arbeiten, wird es mit «+ Gerät koppeln» neu gekoppelt. Darum gibt es keinen Schalter «aktiv».</p>

<section data-help-field="label">
<h3>Gerätename</h3>
<p>Wie das Gerät in der Liste heisst, zum Beispiel «iPhone Peter». Leer gespeichert, nimmt es wieder den Namen, den sein Browser beim Koppeln nannte.</p>
</section>

<section data-help-field="scopes">
<h3>Berechtigungen</h3>
<p>Was das Gerät senden oder abfragen darf. Eine Änderung gilt ab der nächsten Anfrage des Geräts. Steht ein Schalter mit dem Zusatz «von keinem Modul mehr angeboten», hat das Gerät eine Berechtigung, die heute niemand mehr nutzt — ausschalten schadet nicht.</p>
</section>
