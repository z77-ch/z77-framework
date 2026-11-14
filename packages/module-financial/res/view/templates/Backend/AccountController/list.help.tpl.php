<?php
/**
 * Help for the list «Kontenplan» (ADR-048, HELP-003: «Hilfe» at the right end of the toolbar) —
 * what the chart is for, its row anatomy and the rules of the add / edit dialog. The dialog is a
 * MODAL (its field hints stand in the labels, financial.md «The screen»), so this list help also
 * explains its fields. Also the KI-Assistent's knowledge (ADR-055).
 */
?>
<h3>Kontenplan</h3>
<p>Der <strong>Kontenplan</strong> ist die Liste aller Konten der Buchhaltung, nach Nummer geordnet und nach Gruppen eingerückt: hier legst du ein Konto an, benennst es um, ordnest es einer Gruppe zu oder deaktivierst es. Jede Buchung im Journal, jede Rechnung und jeder Beleg bucht auf Konten dieses Plans.</p>
<ul>
    <li><strong>+ Konto</strong> legt ein Konto oder eine Gruppe an; das Zeichen <strong>⋮</strong> am Zeilenanfang öffnet ein Konto zum Bearbeiten.</li>
    <li>Der <strong>Schalter</strong> am Zeilenanfang ist «aktiv»: ein inaktives Konto wird für neue Buchungen nicht mehr angeboten, bestehende Buchungen behalten es. Gelöscht wird ein Konto nie.</li>
    <li><strong>Gruppe</strong> (fett, Badge «Gruppe») sammelt Konten und trägt selbst keine Buchungen, z.B. «10 Umlaufvermögen».</li>
    <li><strong>nur Modul</strong> — ein Sammelkonto, auf das nur ein Modul bucht (z.B. 1100 Debitoren); eine Buchung von Hand wird darauf abgelehnt.</li>
    <li><strong>nicht auf Belegen</strong> — ein Ertragskonto, das in Rechnungen und Gutschriften nicht als Konto einer Position angeboten wird (z.B. Erlösminderungen).</li>
</ul>

<h3>Leerer Kontenplan: KMU-Kontenrahmen übernehmen</h3>
<p>Solange der Kontenplan leer ist, bietet die Liste <strong>«KMU-Kontenrahmen übernehmen …»</strong> an: der Schweizer KMU-Kontenrahmen mit den Klassen 1–9, den Gruppen und den gebräuchlichen Konten als Ausgangslage. Danach ergänzt, benennst und deaktivierst du Konten nach Bedarf. Sobald ein Konto existiert, verschwindet das Angebot.</p>

<h3>Konto anlegen und bearbeiten</h3>
<ul>
    <li><strong>Kontonummer</strong> — nur Ziffern, höchstens 10, z.B. 1020. Nach dem Anlegen fix: Einstellungen, Belege und Buchungen nennen das Konto mit seiner Nummer.</li>
    <li><strong>Bezeichnung</strong> — der Name, z.B. «Bankguthaben». Jederzeit änderbar.</li>
    <li><strong>Kontoart</strong> — Aktiven, Fremdkapital, Eigenkapital (Bilanz) oder Aufwand, Ertrag (Erfolgsrechnung). Sie entscheidet, wo das Konto in Bilanz und Erfolgsrechnung steht. Gesperrt, sobald das Konto eine Buchung in einem abgeschlossenen Geschäftsjahr hat — dann ein neues Konto mit der richtigen Art anlegen und den Saldo umbuchen.</li>
    <li><strong>Bebuchbar</strong> — «Ja — Konto» oder «Nein — Gruppe». Ein Konto mit Buchungen kann keine Gruppe mehr werden.</li>
    <li><strong>Gruppe</strong> — die übergeordnete Gruppe, unter der das Konto eingerückt steht.</li>
    <li><strong>Auf Belegen wählbar</strong> — nur bei Ertragskonten: ob das Konto in Rechnungen und Gutschriften als Konto einer Position angeboten wird.</li>
    <li><strong>Nur durch Module bebuchbar</strong> — für Sammelkonten wie 1100 Debitoren: das Journal lehnt Buchungen von Hand darauf ab, Korrekturen laufen über das Modul. Der Jahresabschluss gleicht das Konto mit dem Modul ab.</li>
</ul>
<p>Wohin Debitoren, MWST und die Eröffnung buchen, steht nicht im Kontenplan, sondern im <strong>Mandanten</strong> (Stammdaten › Firma › Mandant, Reiter «Konten»).</p>
