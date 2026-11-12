<?php
/**
 * Help of the app-user window (ADR-048) — a general part and a section per field. Also prompt
 * material of the KI-Assistent (ADR-055).
 */
?>
<h3>App-Benutzer</h3>
<p>Ein App-Benutzer darf die <strong>z77 App</strong> auf dem Handy benutzen — genau die Funktionen, die hier eingeschaltet sind. Er hat keinen eigenen Namen und keine Rolle im Backend: er gehört zu einer Person und trägt ihren Namen. Angemeldet wird mit einem Code, der an die App-E-Mail geht; ein Passwort gibt es nicht.</p>
<p>Ist die Person in einer höheren Rolle als du, ist das Fenster nur zum Ansehen.</p>

<section data-help-field="linked_id">
<h3>Person</h3>
<p>Mit wem der App-Benutzer verknüpft ist — heute ein Backend-Benutzer, Mitglieder folgen später. Jede Person hat höchstens einen App-Benutzer. In der Auswahl grau: wer schon einen hat, und wer eine höhere Rolle hat als du. Nach dem Anlegen lässt sich die Person nicht mehr ändern; wird sie gelöscht, wird ihr App-Benutzer deaktiviert.</p>
</section>

<section data-help-field="email">
<h3>App-E-Mail</h3>
<p>Hierhin geht der Anmelde-Code. Beim Anlegen steht die E-Mail der Person als Vorschlag drin; sie lässt sich ändern. Jede App-E-Mail gehört genau einem App-Benutzer. Wird sie geändert, bleiben die Geräte angemeldet, offene Codes verfallen, und die alte Adresse bekommt eine Notiz.</p>
</section>

<section data-help-field="scopes">
<h3>Funktionen</h3>
<p>Ein Schalter pro Funktion der App, zum Beispiel «Belege erfassen». Jede Funktion verlangt eine Mindestrolle der Person. Reicht ihre Rolle nicht, ist der Schalter grau und der Grund steht darunter («Braucht mindestens Redaktor — … ist …»). Vergeben kannst du nur, was du selbst halten könntest. Fällt die Rolle der Person später tiefer, gilt die Funktion ab der nächsten Anfrage nicht mehr — auch wenn der Schalter noch an ist.</p>
</section>

<section data-help-field="active">
<h3>Deaktivieren und Aktivieren</h3>
<p><strong>«Deaktivieren …»</strong> meldet alle Geräte des App-Benutzers sofort ab, offene Anmelde-Codes verfallen. Was erfasst wurde, bleibt erhalten. Für die Geräte ist das endgültig: <strong>«Aktivieren …»</strong> erlaubt die Anmeldung wieder, aber jedes Handy meldet sich neu per Code an.</p>
</section>

<section data-help-field="devices">
<h3>Geräte</h3>
<p>Jedes Handy oder jeder Browser, der sich mit diesem App-Benutzer per Code angemeldet hat. «Zuletzt benutzt» wird höchstens einmal pro Stunde nachgeführt. Abgelaufen ist ein Gerät, das 180 Tage nichts gesendet hat; pro App-Benutzer sind höchstens 10 Geräte aktiv — beim elften wird das am längsten unbenutzte gesperrt.</p>
<p><strong>«Sperren …»</strong> nimmt einem Gerät jede Berechtigung: ab sofort wird alles abgewiesen, was es sendet, auch was noch in seiner Warteschlange liegt. Sperren ist endgültig — soll das Handy wieder arbeiten, meldet es sich neu mit einem Code an.</p>
</section>
