<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): e-mail across the screens — the installation's
 * mail account (developer config), the personal sender «Senden als» (backend user), the texts
 * (Service › E-Mail: form mails, «Beleg-Mails»), a send action in an order / invoice / dunning,
 * the outbox and its job (Service › Postausgang). Lives in module-mailing because the outbox is
 * the hub every business mail passes. Not attached to a screen: the help index finds every
 * `*.help.tpl.php` under the module's templates. Static text, no context. Facts: mail.md,
 * mailing.md.
 */
?>
<h3>E-Mails: Absender, Beleg-Mails, Postausgang</h3>
<p>Rechnung, Mahnung oder Offerte per E-Mail senden, Absender und Antwortadresse, Text der E-Mail ändern, nachsehen, ob sie versendet wurde, und was tun, wenn eine E-Mail nicht ankommt.</p>

<h4>1. Das Mail-Konto der Installation</h4>
<p>Alle E-Mails gehen über <strong>ein</strong> Mail-Konto hinaus (Absenderadresse, Mailserver, Zugang). Das richtet der Entwickler ein (<code>config/client/mail.inc.php</code>); im Backend gibt es dafür keinen Bildschirm. Fehlt es, bleiben die E-Mails im Postausgang stehen, und der Postausgang sagt das.</p>

<h4>2. Wer als Absender erscheint — «Senden als»</h4>
<p>Jeder Benutzer erfasst bei sich (System › Benutzer › Bearbeiten › <strong>Absender für E-Mails</strong>) einen oder mehrere Absender: Name, E-Mail, Telefon; der erste ist der Standard. Beim Senden wählst du einen davon. Der Empfänger sieht deinen Namen als Absender, und seine <strong>Antwort geht an deine E-Mail</strong>; die technische Absenderadresse bleibt die des Mail-Kontos. Name, E-Mail und Telefon stehen im Abschluss der E-Mail. Hast du keinen Absender erfasst, bietet der Sende-Dialog keinen Knopf «Senden» an, sondern «Erfasse zuerst eine E-Mail an deinem User.»</p>

<h4>3. Die Texte — Service › E-Mail</h4>
<p>Betreff, Faktenzeile und Text der <strong>Beleg-Mails</strong> (Rechnung, Rechnung als Ersatz, Gutschrift, Mahnung, Offerte, Auftragsbestätigung, Lieferschein) je Sprache, mit Platzhaltern wie <code>{nummer}</code>, <code>{betrag}</code>, <code>{faellig}</code>. Ohne eigene Texte gelten die des Moduls. Dort stehen auch die <strong>Formular-Mails</strong>: wer die Anfragen des Kontaktformulars der Website und die Alarm-Meldungen bekommt.</p>

<h4>4. Senden aus einem Beleg</h4>
<p>Gesendet wird dort, wo der Beleg ist: in der Rechnung <strong>Per E-Mail …</strong> (wurde sie schon gesendet: <strong>Erneut senden</strong>), nach dem Fakturieren <strong>Alle per E-Mail senden …</strong>, im Auftrag <strong>Senden ▾</strong> (Offerte, Auftragsbestätigung, Lieferschein), bei den Mahnungen. Die E-Mail geht an die <strong>E-Mail der Rechnungsadresse</strong> des Kunden (nicht an die E-Mail des Kontakts), beginnt mit der <strong>Briefanrede</strong> der Adresse und ist in der Sprache des Belegs geschrieben (fehlt dort ein Text: Deutsch). Der Beleg geht als PDF mit. Massgebend ist die Adresse, wie sie im Beleg steht; hat sie keine oder eine ungültige E-Mail, wird der Beleg nicht gesendet («keine E-Mail-Adresse» bzw. «E-Mail-Adresse ungültig»).</p>

<h4>5. Postausgang</h4>
<p>«Senden» reiht die E-Mail im <strong>Postausgang</strong> ein (Service › Postausgang); der Hintergrund-Job versendet sie kurz danach, in der Grundeinstellung 10 pro Minute. Nach einem Versand führt <strong>Postausgang ansehen</strong> genau zu den E-Mails dieses Versands. Dort steht, ob eine E-Mail <strong>gesendet</strong> (vom Mailserver angenommen), noch <strong>wartend</strong> oder <strong>fehlgeschlagen</strong> ist, mit dem Fehler. Eine fehlgeschlagene E-Mail sendest du dort mit <strong>⋮ › Erneut senden</strong>, eine wartende hältst du mit <strong>⋮ › Abbrechen</strong> zurück. Fehlgeschlagene E-Mails meldet auch die Übersicht.</p>

<h4>Wenn eine E-Mail nicht ankommt</h4>
<ul>
    <li>Steht sie im Postausgang unter <strong>Wartend</strong> und bewegt sich nicht: der Hinweis über der Liste nennt den Grund (Mail-Konto fehlt, Versand nicht eingereiht → <strong>Versand starten</strong>, Cron läuft nicht).</li>
    <li>Unter <strong>Fehlgeschlagen</strong>: die Spalte Fehler lesen, die Ursache beheben (meist die Adresse beim Kontakt), dann <strong>Erneut senden</strong>.</li>
    <li>Unter <strong>Gesendet</strong>: der Mailserver hat sie angenommen. Kommt sie trotzdem nicht an, liegt sie oft im Spam-Ordner des Empfängers — oder die Adresse ist zwar gültig, aber falsch.</li>
</ul>

<h4>Noch nicht verfügbar</h4>
<p>Ein <strong>Newsletter</strong> (eine E-Mail an viele Empfänger) ist noch nicht gebaut — folgt. Ein Dokument aus der Dokumentablage lässt sich noch nicht direkt per E-Mail senden.</p>
