<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055): the flow of an order across its screens — quote,
 * order confirmation, delivery note, invoicing, posting — the list tabs, phases and statuses, and
 * what the stock does (nothing yet: P7b, `PendingStockLedger`). Not attached to a screen: the help
 * index finds every `*.help.tpl.php` under the module's templates. Static text, no context.
 */
?>
<h3>Ablauf eines Auftrags</h3>
<p>Wie ein Auftrag läuft: Offerte erstellen, Auftrag bestätigen, Lieferschein drucken, fakturieren (Rechnung aus dem Auftrag), Journal verbuchen; Reiter, Phasen und Status; Auftrag stornieren oder löschen; Lager.</p>
<p>Offerte und Auftrag sind <strong>derselbe Datensatz</strong> mit derselben Nummer. Was er gerade ist, sagt sein <strong>Status</strong>; jeder Status gehört zu einer festen <strong>Phase</strong>, und die Phase bestimmt, was mit dem Auftrag geht. Die Aufträge stehen unter Auftragswesen › Aufträge.</p>

<h4>Der Weg in fünf Schritten</h4>
<ol>
    <li><strong>Offerte</strong> — <strong>+ Neuer Auftrag</strong>: Kunde wählen (er muss Debitor sein, sonst fragt «Kunde wählen …» zuerst <strong>Debitor anlegen</strong>), Betreff, Positionen (jede Position mit Preis ist ein Artikel). Ein neuer Auftrag beginnt im Startstatus, meist «Offerte» (Reiter <strong>Eröffnet</strong>). <strong>Drucken ▾ › Offerte</strong> gibt das PDF, <strong>Senden ▾ › Offerte …</strong> schickt es per E-Mail.</li>
    <li><strong>Termin</strong> — <strong>Drucken ▾ › Auftragsbestätigung …</strong> druckt die Auftragsbestätigung und setzt den Auftrag auf «Terminauftrag» (Reiter <strong>Termin</strong>). Ein Auftrag, der gleich bestätigt ist, kann beim Erfassen direkt mit diesem Status beginnen.</li>
    <li><strong>Geliefert</strong> — <strong>Drucken ▾ › Lieferschein …</strong> druckt den Lieferschein an die Lieferadresse und setzt den Auftrag auf «Geliefert» (Reiter <strong>Geliefert</strong>). Ist «Leistung von» leer, setzt der Druck den Drucktag ein. Ein Nachdruck ändert den Status nicht.</li>
    <li><strong>Fakturieren</strong> — in <strong>Termin</strong> oder <strong>Geliefert</strong>: <strong>Fakturieren …</strong> im Auftrag, oder in der Liste mehrere Aufträge ankreuzen und <strong>Fakturieren …</strong>. Das erstellt je Auftrag die Rechnung mit Nummer und legt ihr PDF unveränderlich ab; die Rechnung steht danach im <strong>Faktura-Journal</strong>, der Auftrag auf «Fakturiert» (Reiter <strong>Fakturiert</strong>). Solange die Rechnung dort steht, lässt sich der Auftrag noch ändern; danach <strong>Neu fakturieren …</strong>.</li>
    <li><strong>Gebucht</strong> — Auftragswesen › Faktura-Journal › <strong>Journal verbuchen</strong> übernimmt die Rechnungen in die Buchhaltung. Der Auftrag geht auf «Gebucht» (Reiter <strong>Gebucht</strong>) und ist gesperrt; eine Korrektur ist dann eine <strong>Gutschrift</strong> auf der Rechnung (Auftragswesen › Rechnungen).</li>
</ol>
<p>Ob die Rechnung <strong>bezahlt</strong> ist, ist kein Status des Auftrags: die Spalte <strong>Zahlung</strong> in «Fakturiert» und «Gebucht» und der Abschnitt «Belege» im Auftrag zeigen es aus der Debitorenbuchhaltung. Zahlungen und Mahnungen laufen über die Rechnung (Auftragswesen › Rechnungen, Zahlungseingänge, Mahnungen).</p>

<h4>Ohne Rechnung abschliessen: Erledigt, Abgelegt, Gelöscht</h4>
<ul>
    <li><strong>Erledigt</strong> — von Hand über den Status: die Ware ist weg, aber es gibt keine Rechnung (z.B. Garantie, Total 0). Ein Auftrag mit Total 0 lässt sich nicht fakturieren — er wird auf «Erledigt» gesetzt.</li>
    <li><strong>Abgelegt</strong> — von Hand über den Status, z.B. «Storniert»: die Offerte wurde abgelehnt, der Auftrag zurückgezogen. Der Auftrag bleibt aufbewahrt, ohne Warenbewegung.</li>
    <li><strong>Gelöscht</strong> — <strong>Löschen …</strong> im Auftrag, mit Grund, für Erfassungsfehler: der Auftrag bleibt mit seiner Nummer im Reiter «Gelöscht». Möglich, solange keine verbuchte Rechnung besteht; eine fakturierte, noch nicht verbuchte Rechnung wird mitgelöscht.</li>
</ul>

<h4>Reiter, Phasen und Status</h4>
<p>Die <strong>Phasen</strong> sind fest; die <strong>Status</strong> sind frei wählbare Bezeichnungen (Stammdaten › Auftragswesen › Auftragsstatus). Jeder Auftrag steht im Reiter der Phase seines Status:</p>
<ul>
    <li><strong>Eröffnet</strong> (Phase Eröffnet) — Offerten und erfasste Aufträge; das PDF heisst «Offerte»; kein Fakturieren.</li>
    <li><strong>Termin</strong> (Phase Termin) — bestätigte Aufträge, z.B. «Terminauftrag»; Fakturieren möglich. Die Liste öffnet auf diesem Reiter.</li>
    <li><strong>Geliefert</strong> (Phase Geliefert) — z.B. «Geliefert», «Zu fakturieren»; Fakturieren möglich.</li>
    <li><strong>Fakturiert</strong>, <strong>Gebucht</strong>, <strong>Erledigt</strong> (Phase Abgeschlossen, in drei Reitern) — «Fakturiert» setzt nur das Fakturieren, «Gebucht» nur «Journal verbuchen», jeder andere abgeschlossene Status steht unter «Erledigt».</li>
    <li><strong>Abgelegt</strong> (Phase Abgelegt) — beendet ohne Warenbewegung, z.B. «Storniert».</li>
    <li><strong>Alle</strong> zeigt jeden Auftrag ausser den gelöschten, <strong>Gelöscht</strong> die gelöschten.</li>
</ul>
<p>Welcher Status bei welcher Aktion gesetzt wird, steht beim Status («Startstatus für neue Aufträge», «Wird gesetzt beim Druck der Auftragsbestätigung», «… des Lieferscheins», «… beim Fakturieren», «… beim Verbuchen»).</p>

<h4>Status von Hand ändern</h4>
<p>Solange ein Auftrag <strong>keine Rechnung</strong> hat, lässt sich sein Status frei ändern — vorwärts, zurück oder seitwärts: im Auftrag das Statusfeld wählen und <strong>Speichern</strong>, bei einem gesperrten Auftrag (Erledigt, Abgelegt) über das Statusfeld <strong>Status ändern …</strong>. «Fakturiert» und «Gebucht» sind von Hand nie wählbar. Hat der Auftrag eine Rechnung, bleibt der Status, wie er ist. Jeder Wechsel steht im Verlauf des Auftrags (wann, wer, von → nach, wodurch).</p>

<h4>Lager: noch nicht verfügbar — folgt</h4>
<p>Die Lagerbuchhaltung ist <strong>noch nicht verfügbar</strong>. Die Phasen sind dafür vorbereitet: mit der Lagerbuchhaltung wird in <strong>Termin</strong> der Bestand eines Artikels mit «Führt Bestand» reserviert, ab <strong>Geliefert</strong> abgebucht, in <strong>Abgelegt</strong> und beim Löschen wieder freigegeben. Heute bucht ein Auftrag <strong>keinen Bestand</strong>: es gibt keinen Lagerbestand, keine Reservierung und keine Bestandsliste, und ein Statuswechsel fragt nicht nach einem Grund für eine Rückbuchung.</p>

<h4>Was es noch nicht gibt</h4>
<ul>
    <li><strong>Lagerbuchhaltung</strong> (Bestand, Reservierung, Inventur) — folgt.</li>
    <li><strong>Offerte aufbewahren</strong>: die Offerte wird nicht archiviert. Ihr PDF zeigt immer den Auftrag, wie er jetzt ist — wird aus der Offerte ein Auftrag und ändert er sich, ist das Offerierte nicht mehr zu sehen. Wer es festhalten will, erfasst die Offerte ein zweites Mal als eigenen Auftrag und setzt diesen in einen Status der Phase «Abgelegt» (z.B. «Offerte angenommen»). Eine Kopierfunktion gibt es nicht.</li>
    <li><strong>Sammelrechnung</strong> (mehrere Aufträge in einer Rechnung) und Varianten eines Artikels (Jahrgang, Grösse) — noch nicht verfügbar.</li>
</ul>
