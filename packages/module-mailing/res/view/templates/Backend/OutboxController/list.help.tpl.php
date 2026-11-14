<?php
/**
 * Help of Service › Postausgang (ADR-048, HELP-003: attached by
 * `OutboxControllerTrait::listAction()` with `toolbar: true`) — what the outbox is for, the
 * tabs, the bands, the row menu, how and when mails leave. Also prompt material of the
 * KI-Assistent (ADR-055). Facts: OutboxSettings::DEFAULTS, MailOutboxJob, OutboxService.
 */
?>
<h3>Postausgang</h3>
<p>Jede E-Mail, die das System verschickt hat oder noch verschickt (Rechnungen, Mahnungen, Offerten …): wurde sie gesendet, warum ging sie nicht hinaus, eine fehlgeschlagene erneut senden, eine wartende abbrechen.</p>
<p>Senden heisst im Backend immer: die E-Mail wird hier <strong>eingereiht</strong>. Hinaus geht sie kurz danach im Hintergrund (Job «mail-outbox», jede Minute vom Cron angestossen) — in der Grundeinstellung 10 E-Mails pro Minute. Text und Empfänger stehen beim Einreihen fest; Logo und PDF-Anhang werden beim Versand geholt.</p>

<h3>Reiter: wartend, gesendet, fehlgeschlagen</h3>
<ul>
    <li><strong>Wartend</strong> — noch nicht versendet, auch die, die gerade gesendet werden oder auf einen neuen Versuch warten.</li>
    <li><strong>Gesendet</strong> — vom Mailserver angenommen, mit Zeitpunkt. Ob die E-Mail im Postfach des Empfängers gelandet ist, sieht das System nicht.</li>
    <li><strong>Fehlgeschlagen</strong> — nach allen Versuchen nicht versendet; die Spalte <strong>Fehler</strong> sagt warum.</li>
    <li><strong>Alle</strong> — jede E-Mail, auch die abgebrochenen.</li>
</ul>
<p>Kommst du über «Postausgang ansehen» nach einem Versand hierher, zeigt die Liste nur die E-Mails dieses Versands (Kennzeichen <strong>Versand #…</strong> neben dem Titel); das <strong>×</strong> darin zeigt wieder alle.</p>

<h3>Anzeigen, erneut senden, abbrechen</h3>
<ul>
    <li>Das <strong>Symbol</strong> am Anfang einer Zeile öffnet die E-Mail: Empfänger, Absender, Antwortadresse, wer sie ausgelöst hat, Anhänge, die E-Mail so, wie sie aussieht, und als Text.</li>
    <li><strong>⋮ › Erneut senden</strong> (nur fehlgeschlagene) — reiht die E-Mail wieder ein, die Versuche beginnen von vorn. Zuerst den Fehler beheben (z.B. eine falsche Adresse beim Kontakt), sonst scheitert sie wieder.</li>
    <li><strong>⋮ › Abbrechen</strong> (nur wartende) — die E-Mail wird nie versendet; sie bleibt unter «Alle» sichtbar. Eine E-Mail, die gerade gesendet wird, lässt sich nicht mehr zurückholen. Eine gesendete oder abgebrochene E-Mail lässt sich hier nicht nochmals senden — dafür im Beleg erneut senden.</li>
</ul>

<h3>Wenn eine E-Mail scheitert</h3>
<p>Antwortet der Mailserver mit einem Fehler, versucht es das System in der Grundeinstellung nach 5 und nach 15 Minuten nochmals; nach dem dritten Fehlschlag ist die E-Mail <strong>fehlgeschlagen</strong>. Eine ungültige Empfängeradresse scheitert sofort («Nicht versendbar»). Brach ein Versand mittendrin ab (z.B. Serverabsturz), steht die E-Mail mit «Versand unterbrochen» unter Fehlgeschlagen und wird nie von selbst nochmals gesendet — ob sie hinausging, ist unbekannt; prüfe das, bevor du sie erneut sendest. Fehlgeschlagene E-Mails und solche, die seit über einer Stunde warten, meldet auch die Übersicht.</p>

<h3>Warum nichts hinausgeht</h3>
<p>Solange E-Mails warten, nennt ein Hinweis über der Liste den Grund, wenn der Versand stockt:</p>
<ul>
    <li><strong>E-Mail-Versand ist nicht eingerichtet</strong> — das Mail-Konto der Installation fehlt (Datei <code>config/client/mail.inc.php</code>, richtet der Entwickler ein). Die E-Mails bleiben unverändert stehen und gehen hinaus, sobald es eingerichtet ist.</li>
    <li><strong>Der Versand ist nicht eingereiht</strong> — <strong>Versand starten</strong> reiht den Job ein; er beginnt beim nächsten Cron-Lauf.</li>
    <li><strong>Der Cron-Lauf ist nicht mehr gelaufen</strong> — der Server stösst die Hintergrund-Jobs nicht mehr an (Service › Jobs); das muss der Entwickler oder Hoster beheben.</li>
</ul>

<h3>Suchen und sortieren</h3>
<p>Ein Spaltentitel sortiert, die Lupe daneben sucht in der Spalte (Erstellt, Empfänger, Betreff, Quelle); ein Klick auf eine Zelle öffnet die Suche ihrer Spalte. <strong>Quelle</strong> sagt, welcher Teil des Systems die E-Mail ausgelöst hat. <strong>Zurücksetzen</strong> hebt Suche und Sortierung auf.</p>

<h3>Noch nicht verfügbar</h3>
<p>Newsletter und Serien-E-Mails an viele Empfänger gibt es noch nicht — folgt. Der Postausgang zeigt nur, was das System selbst verschickt; E-Mails aus deinem eigenen Mailprogramm stehen nicht hier.</p>
