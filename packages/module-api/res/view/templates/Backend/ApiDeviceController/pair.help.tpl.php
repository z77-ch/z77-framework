<?php
/**
 * Help of the pairing window (ADR-048) — a general part and a section per field
 * (`data-help-field` = the field's `name` or its `data-help-key`).
 */
?>
<h3>Gerät koppeln</h3>
<p>Das Fenster erzeugt beim Öffnen einen <strong>Code</strong> und zeigt ihn als Text und als QR-Code. Das Handy liest ihn ein und ist danach gekoppelt — ohne Passwort: es bekommt einen Schlüssel, den nur der Browser des Handys kennt und den keine Webseite auslesen kann. Sobald das Handy gekoppelt ist, wechselt das Fenster auf das neue Gerät, und die Liste zeigt die Zeile.</p>

<section data-help-field="owner_id">
<h3>Besitzer</h3>
<p>Der Backend-Benutzer, dem das Gerät gehört. Was das Gerät erfasst, wird ihm zugeschrieben («erfasst von»). Normalerweise bist das du; wer Benutzer verwalten darf, wählt hier auch einen anderen Benutzer — nicht aber einen mit einer höheren Rolle. Wird der Benutzer gelöscht, sind seine Geräte tot.</p>
</section>

<section data-help-field="label">
<h3>Gerätename</h3>
<p>Wie das Gerät in der Liste heisst, zum Beispiel «iPhone Peter». Leer lassen: dann nimmt das Gerät den Namen, den sein Browser beim Koppeln nennt («Safari · iPhone»). Der Name lässt sich später im Gerätefenster ändern.</p>
</section>

<section data-help-field="scopes">
<h3>Berechtigungen</h3>
<p>Was das Gerät senden oder abfragen darf — je Schalter ein Bereich, den ein Modul Geräten anbietet (zum Beispiel «Belege erfassen»). Nur einschalten, was das Gerät wirklich braucht. Die Berechtigungen hängen nicht von den Rollen des Besitzers ab, und keine öffnet das Backend.</p>
</section>

<section data-help-field="code">
<h3>Code und Gültigkeit</h3>
<p>Der Code gilt <strong>10 Minuten</strong> und nur <strong>einmal</strong>. Besitzer und Berechtigungen stecken im Code: wer sie ändert, klickt <strong>«Neuer Code»</strong> — der alte wird sofort ungültig. Der Balken und «noch m:ss» zeigen die verbleibende Zeit; ist sie um, hilft «Neuer Code».</p>
<ul>
    <li><strong>Kamera:</strong> die Handy-Kamera auf den QR-Code richten und die angezeigte Adresse öffnen.</li>
    <li><strong>Von Hand:</strong> auf dem Handy die Adresse unter dem QR-Code öffnen (…/app) und den Code eintippen — Gross/Klein und Bindestrich spielen keine Rolle.</li>
</ul>
</section>
