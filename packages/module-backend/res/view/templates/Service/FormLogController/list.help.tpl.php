<?php
/**
 * Help for the form log (geo guard, ADR-048, HELP-003) — attached by
 * `FormLogController::listAction()` with `toolbar: true`. Tallies, outcomes, the country
 * blocklist (Land sperren / Aufheben), retention. Static text, no context. Facts: forms.md,
 * geoip.md, FormLogController.
 */
?>
<h3>Formular-Protokoll</h3>
<p>Was über die Formulare der Webseite hereinkommt: Spam aus dem Ausland erkennen, ein Land sperren, Sperre aufheben, prüfen ob eine Kontaktanfrage angekommen ist oder abgewiesen wurde.</p>
<p>Aufgezeichnet wird jede Übermittlung an ein öffentliches Formular, dessen Geo-Guard eingeschaltet ist — die jüngsten zuerst, mit Zeitpunkt, Formular, Land, Herkunft (IP-Adresse), Ausgang, Identität (nur wo das Formular sie aufzeichnet) und «Über» (ein technischer Hinweis des Formulars, z.B. woher eine Registrierung kam). Gibt es mehrere Formulare, filtert die Zeile «Formular:» auf eines.</p>

<h4>Auszählung</h4>
<p><strong>Woher</strong> zählt die Übermittlungen pro Land, <strong>Was daraus wurde</strong> pro Ausgang: «Angenommen», «Bot abgewiesen», «Land gesperrt», «Limit erreicht», «Eingabe abgelehnt», «Token ungültig», «Versand scheiterte», «Adress-Drossel», «Herkunfts-Drossel».</p>

<h4>Land sperren und Sperre aufheben</h4>
<p><strong>Land sperren</strong> neben einem Land öffnet die Rückfrage mit einem <strong>Grund</strong> (Pflicht, vorgeschlagen aus der Auszählung). Danach weisen alle Formulare mit Geo-Guard jede Übermittlung aus diesem Land ab; andere Formulare sind nicht betroffen. Ein Land, das sich nicht bestimmen lässt, wird nie gesperrt. <strong>Gesperrte Länder</strong> listet die Sperren mit Grund, Datum und wer; <strong>Aufheben</strong> hebt eine auf.</p>
<p>Vorsicht: Kunden in den Ferien oder hinter einem VPN sehen aus wie Anfragen aus dem Ausland. Sperre nur, was die Auszählung belegt.</p>

<h4>Wenn etwas fehlt</h4>
<p>Ohne Länder-Datenbestand bleibt die Spalte «Land» leer und keine Sperre greift; den Datenbestand hält der Job <strong>GeoIP-Datenbank erneuern</strong> aktuell. Ist die Sperrliste unlesbar, steht das rot auf der Seite und die Sperre ist aus, bis die Datei repariert ist. Einträge werden nach der angezeigten Anzahl Tage vom Job <strong>Formular-Protokoll-Bereinigung</strong> gelöscht, sofern er eingeschaltet ist.</p>
