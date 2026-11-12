<?php
/**
 * Help of Stammdaten › System › Geräte (ADR-048, HELP-003: attached with `toolbar: true`) — what
 * the list is for, the tabs, the main actions. Also prompt material of the KI-Assistent (ADR-055).
 */
?>
<h3>Geräte</h3>
<p>Hier stehen die <strong>gekoppelten Geräte</strong> — Handys oder PCs, die ohne Anmeldung über die App etwas an diese Installation senden dürfen, zum Beispiel Belege erfassen. Jedes Gerät gehört einem Backend-Benutzer, seinem <strong>Besitzer</strong>, und darf nur, was seine <strong>Berechtigungen</strong> erlauben — unabhängig von den Rollen des Besitzers; ins Backend kommt ein Gerät nie.</p>
<p>Jeder Benutzer sieht und verwaltet seine eigenen Geräte. Wer Benutzer verwalten darf (Admin und höher), sieht alle Geräte und koppelt auch für andere — aber nicht für einen Benutzer mit einer höheren Rolle als der eigenen.</p>
<h3>Reiter</h3>
<p><strong>Aktiv</strong> zeigt die Geräte, die heute senden dürfen. <strong>Alle</strong> zeigt auch die gesperrten: sie bleiben sichtbar, damit nachvollziehbar bleibt, wer was erfasst hat.</p>
<h3>Aktionen</h3>
<ul>
    <li><strong>+ Gerät koppeln</strong> öffnet ein Fenster mit einem Code und einem QR-Code, den das Handy einliest. Der Code gilt 10 Minuten und nur einmal.</li>
    <li>Das <strong>Telefon-Symbol</strong> am Anfang einer Zeile öffnet das Gerät: Name und Berechtigungen ändern, «Sperren …». Ein Schloss zeigt ein gesperrtes Gerät.</li>
    <li><strong>⋮</strong> öffnet «Öffnen» und «Sperren …» direkt an der Zeile.</li>
</ul>
<p><strong>Zuletzt benutzt</strong> wird höchstens einmal pro Stunde nachgeführt. Ein Gerät, das 180 Tage nicht benutzt wurde, gilt als abgelaufen und muss neu gekoppelt werden; pro Besitzer sind höchstens 10 Geräte aktiv — beim elften wird das am längsten unbenutzte gesperrt.</p>
