<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — the receivables workflow across the debtor
 * screens: draft → «Fakturieren» → Faktura-Journal → «Journal verbuchen» → payment → dunning,
 * and how an issued invoice is corrected (revision, replacement, deletion, credit note). Not
 * attached to a screen: `HelpKnowledge` indexes every `*.help.tpl.php` under the module's
 * template root. Static text only — no controller variables.
 */
?>
<h3>Ablauf: Rechnung → Faktura-Journal → Verbuchen → Zahlung → Mahnung</h3>
<p>Wie eine Rechnung vom Entwurf bis zur Zahlung oder Mahnung läuft, auf welchem Bildschirm welcher Schritt gemacht wird — und wie eine Rechnung korrigiert, ersetzt, gelöscht oder gutgeschrieben wird, auch wenn der Kunde sie schon hat.</p>

<h4>1. Rechnung erfassen — Entwurf</h4>
<p>Auftragswesen › Rechnungen › <strong>+ Rechnung</strong>: Kunde wählen, Positionen erfassen, <strong>Erstellen</strong>. Die Rechnung ist ein <strong>Entwurf</strong> (Reiter «In Fakturierung»): ohne Nummer, nichts ist verbucht, beliebig oft änderbar, als <strong>Vorschau</strong> (PDF ohne Nummer und ohne Zahlteil) ansehbar. Ein Entwurf, der nicht mehr gebraucht wird: <strong>Entwurf löschen …</strong>. Rechnungen aus einem Auftrag entstehen im Auftrag (Auftragswesen › Aufträge, «Fakturieren …») und überspringen diesen Schritt.</p>

<h4>2. Fakturieren — die Rechnung geht an den Kunden</h4>
<p><strong>Fakturieren …</strong> (in der Liste für die angekreuzten Entwürfe, im Entwurf für diesen) vergibt die Belegnummer (JJMMnnnn, aus dem Monat des Rechnungsdatums), druckt den QR-Zahlteil und legt das PDF unveränderlich ab. Danach zeigt eine Liste die fakturierten Dokumente: jedes PDF, <strong>Alle als ein PDF</strong>, <strong>Alle als ZIP</strong>, <strong>Alle per E-Mail senden …</strong>. Die Rechnung ist jetzt <strong>fakturiert</strong> und steht im Faktura-Journal — in der Buchhaltung ist sie noch nicht.</p>

<h4>3. Faktura-Journal prüfen und verbuchen</h4>
<p>Auftragswesen › Faktura-Journal sammelt alle fakturierten Rechnungen und Gutschriften. <strong>Journal drucken</strong> gibt die Liste zum Prüfen. <strong>Journal verbuchen …</strong> › <strong>Jetzt buchen</strong> bucht immer das <strong>ganze</strong> Journal, jedes Dokument mit seinem Rechnungsdatum. Danach sind die Rechnungen <strong>verbucht</strong>: unveränderlich, offene Posten unter Auftragswesen › Debitoren. Gesperrt ist das Verbuchen, solange eine Rechnung «Neu fakturieren nötig» trägt (ihr Auftrag wurde danach geändert).</p>

<h4>4. Zahlung</h4>
<p>Eine Rechnung nimmt erst nach «Journal verbuchen» Zahlungen an. Zahlungen von der Bank kommen über <strong>Zahlungseingänge</strong> (camt.054 einlesen, zuordnen, <strong>Verbuchen</strong> — siehe «Zahlungseingänge einlesen»). Von Hand: Auftragswesen › Debitoren › <strong>Zahlung</strong> in der Zeile, oder in der Rechnung <strong>Zahlung erfassen …</strong> — dort auch <strong>Skonto</strong>, <strong>Gebühren</strong> (Kommission eines Zahlungsanbieters, ohne MWST-Kürzung) und <strong>Verlust</strong>. Ein Skonto wird nie automatisch abgezogen. Eine falsche Zahlung lässt sich in der Rechnung (Fakten, «ändern») ändern oder löschen, solange das Geschäftsjahr offen ist.</p>

<h4>5. Mahnung</h4>
<p>Auftragswesen › Mahnungen zeigt auf einen <strong>Stichtag</strong>, welche verbuchten Rechnungen die nächste Mahnstufe erreicht haben. <strong>Mahnlauf starten</strong> erstellt die Mahnungen; trägt die Stufe eine Gebühr, entsteht ein Gebühren-Beleg — mit der MWST der gemahnten Rechnung, wenn die Firma MWST-pflichtig ist —, den der Kunde mit der Rechnung bezahlt. Jede Mahnung lässt sich als PDF öffnen oder einzeln per E-Mail senden (⋮ der Mahnung). Eine Mahnung lässt sich nicht zurücknehmen; eine falsche Gebühr wird mit einer Gutschrift auf den Gebühren-Beleg korrigiert. Stufen, Fristen und Gebühren: Stammdaten › Auftragswesen › Mahnstufen; ein Debitor mit <strong>Mahnsperre</strong> wird nie gemahnt.</p>

<h3>Eine Rechnung korrigieren</h3>
<ul>
    <li><strong>Entwurf</strong> (In Fakturierung): einfach ändern und speichern, oder <strong>Entwurf löschen …</strong> — er hatte noch keine Nummer.</li>
    <li><strong>Fakturiert, noch nicht verbucht</strong> (im Faktura-Journal): die Rechnung öffnen, ändern, <strong>Speichern</strong>. Die Maske sagt vorher, was daraus wird: bleiben Betrag, MWST, Kunde und Monat des Rechnungsdatums gleich, eine <strong>neue Version mit gleicher Nummer</strong> (gleiche QR-Referenz, neue ID, die alte Version bleibt archiviert); sonst ein <strong>Ersatz mit neuer Nummer</strong> — die neue Rechnung druckt «ersetzt Rechnung …», die alte steht unter «Ersetzt». Mit <strong>… bestätigen</strong> wird gespeichert. Danach dem Kunden das neue PDF senden (<strong>Per E-Mail …</strong>).</li>
    <li><strong>Löschen, solange nicht verbucht</strong>: <strong>Rechnung löschen …</strong> mit Grund — sie bleibt mit Nummer und Grund unter «Gelöscht», damit die Nummern lückenlos belegt sind.</li>
    <li><strong>Rechnung aus einem Auftrag</strong>: nicht in der Rechnung, sondern im <strong>Auftrag</strong> ändern und dort <strong>Neu fakturieren …</strong> (oder den Auftrag löschen). Bis dahin sperrt «Neu fakturieren nötig» das Faktura-Journal.</li>
    <li><strong>Verbucht</strong>: nichts lässt sich mehr ändern oder löschen — die Korrektur ist eine <strong>Gutschrift</strong>. In der Rechnung <strong>Gutschrift erstellen …</strong>: die Positionen sind vorbelegt, auf das kürzen, was gutgeschrieben wird, dann <strong>Erstellen</strong>. Die Gutschrift ist danach ein Entwurf (Reiter «Gutschriften»): in ihrem Fenster <strong>Fakturieren …</strong>, dann mit dem Faktura-Journal verbuchen — erst danach senkt sie den offenen Betrag. Eine Rechnung, die erst fakturiert ist, wird nicht gutgeschrieben, sondern korrigiert (oben). Muss der Kunde eine neue Rechnung bekommen: danach eine neue Rechnung erstellen.</li>
</ul>

<h3>Hinweise auf der Startseite</h3>
<ul>
    <li><strong>Rechnungsentwürfe nicht fakturiert</strong> — Entwürfe im Reiter «In Fakturierung»: fakturieren oder löschen.</li>
    <li><strong>Faktura-Journal nicht verbucht</strong> — fakturierte Rechnungen warten auf «Journal verbuchen …»; <strong>gesperrt (neu fakturieren)</strong>: zuerst die markierte Rechnung im Auftrag neu fakturieren.</li>
    <li><strong>Rechnungen überfällig</strong> — Auftragswesen › Debitoren, Reiter «Überfällig».</li>
    <li><strong>Mahnvorschlag bereit</strong> — unter Auftragswesen › Mahnungen ist mindestens eine Mahnung fällig.</li>
    <li><strong>Zahlungseingänge nicht verbucht</strong> — eingelesene Zahlungen, die noch zugeordnet oder verbucht werden müssen.</li>
    <li><strong>Kein Zahlungsziel mit IBAN</strong> — unter Stammdaten › Auftragswesen › Zahlungsziele das eigene Bankkonto erfassen; ohne gibt es keinen QR-Zahlteil.</li>
</ul>

<h3>Noch nicht verfügbar</h3>
<ul>
    <li>Alle Mahnungen eines Mahnlaufs auf einmal per E-Mail senden — folgt; bis dahin je Mahnung über ihr ⋮.</li>
    <li><strong>MWST-Abrechnung</strong> für die ESTV aus den verbuchten Rechnungen — folgt. Bis dahin: Umsatz und Steuer pro MWST-Code im Druck des Faktura-Journals und in der Buchhaltung.</li>
    <li><strong>Kreditoren</strong> (Rechnungen von Lieferanten erfassen und bezahlen) — ein eigenes Modul, folgt. Bis dahin werden Einkäufe in der Buchhaltung gebucht (Journal oder Belegjournal).</li>
    <li><strong>Vorauszahlung</strong> (eine Zahlung vor der Rechnung, z.B. eine Anzahlung auf einen Auftrag) — noch nicht verfügbar.</li>
    <li>Rechnungen in einer Fremdwährung — alle Belege sind in der Grundwährung.</li>
</ul>
