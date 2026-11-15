<?php
/**
 * Help for «Mahnungen» (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`,
 * opened with «Hilfe» at the right end of the toolbar. A quick help: the due list as of a day,
 * the run, the runs so far with the notice's ⋮; one field section for «Stichtag» (`as_of`), the
 * list's one control. Not built yet and said so: «Alle per E-Mail» for a whole run (debtor.md pending).
 */
?>
<h3>Mahnungen</h3>
<p>Mahnen: welche Rechnungen sind überfällig und reif für eine Zahlungserinnerung oder Mahnung, einen Mahnlauf starten, die Mahnung drucken oder per E-Mail senden.</p>
<h4>Fällige Mahnungen</h4>
<p>Oben die <strong>fälligen Mahnungen</strong> auf einen Stichtag: jede verbuchte Rechnung mit offenem Betrag, für die die nächste Mahnstufe fällig ist — mit dem offenen Betrag, der bisherigen und der nächsten Stufe und deren Gebühr. Debitoren mit Mahnsperre oder inaktive Debitoren erscheinen nicht. Die Stufen, ihre Fristen und Gebühren stehen unter Stammdaten › Auftragswesen › Mahnstufen.</p>
<h4>Mahnlauf starten</h4>
<p><strong>Mahnlauf starten</strong> erstellt für jede angekreuzte Rechnung eine Mahnung auf der nächsten Stufe, datiert auf den Stichtag; alle Fälligen sind vorab angekreuzt. Trägt die Stufe eine Gebühr, wird dazu ein Gebühren-Beleg erstellt und verbucht (ohne MWST); die Mahnung trägt einen QR-Zahlteil über offenen Betrag und Gebühr. Hat sich seit dem Anzeigen etwas geändert, wird der ganze Lauf abgewiesen und nichts erstellt. Eine Mahnung lässt sich nicht zurücknehmen — eine falsche Gebühr wird mit einer Gutschrift auf den Gebühren-Beleg korrigiert.</p>
<h4>Mahnung drucken oder per E-Mail senden</h4>
<p>Unten die <strong>Mahnläufe</strong> bisher, je mit ihren Mahnungen: Rechnung, Stufe, offener Betrag, der Gebühren-Beleg, die E-Mail-Adresse der Rechnung und was per E-Mail ging. Das ⋮ einer Mahnung: <strong>PDF öffnen</strong> — die Mahnung zum Drucken, in einem neuen Tab — und <strong>Per E-Mail senden …</strong> — die Mahnung als PDF an die E-Mail-Adresse der Rechnungsadresse, mit «Senden als»; nochmals senden heisst dort <strong>Erneut senden …</strong>. Fehlt die Adresse, sagt es die Bestätigung. Betreff und Text: Service › E-Mail › Beleg-Mails, «Mahnung».</p>
<p><strong>Noch nicht verfügbar:</strong> alle Mahnungen eines Laufs auf einmal per E-Mail senden — folgt; bis dahin je Mahnung über ihr ⋮.</p>

<section data-help-field="as_of">
<h3>Stichtag</h3>
<p>Der Tag, auf den die Liste rechnet und auf den der Mahnlauf datiert wird; leer heisst heute. <strong>Liste aktualisieren</strong> liest die Liste für den gewählten Tag neu. Der Stichtag darf nicht in der Zukunft liegen und nicht vor dem letzten Mahnlauf.</p>
</section>
