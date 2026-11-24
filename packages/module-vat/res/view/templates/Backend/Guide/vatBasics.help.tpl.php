<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — VAT across the screens: tax codes and dated
 * rates (Stammdaten › Finanzen › MWST-Codes), where a code is chosen (invoice, journal, receipt),
 * how the tax is computed and posted, the mandator's VAT liability, where the VAT return is read
 * (financial, stage S1b) and what is NOT built yet (posting it). Not attached to a screen: `HelpKnowledge` indexes every `*.help.tpl.php`
 * under the module's template root. Static text only — no controller variables.
 */
?>
<h3>MWST: Codes, Sätze, was die Abrechnung (noch) kann</h3>
<p>Wie die Mehrwertsteuer in z77 funktioniert: welche MWST-Codes es gibt, wie ein neuer Steuersatz eingetragen wird, wo der Code gewählt wird, wie die Steuer gerechnet und gebucht wird — und wo die MWST-Abrechnung für die ESTV steht.</p>

<h4>Codes und Sätze</h4>
<p>Ein <strong>MWST-Code</strong> sagt, welche Steuer gilt: UN Normalsatz, UR reduzierter Satz, US Sondersatz (Beherbergung), UE befreit (0 %), UA ausgenommen, VM und VI Vorsteuer. Der <strong>Satz</strong> hängt am Datum: ab 1.1.2024 rechnet UN mit 8.1 %, davor mit 7.7 %. Massgebend ist das <strong>Leistungsdatum</strong>. Gepflegt unter <strong>Stammdaten › Finanzen › MWST-Codes</strong>; eine Satzänderung ist ein neuer Satz «gültig ab», der alte bleibt für ältere Leistungen stehen. Codes werden nie gelöscht, nur deaktiviert.</p>

<h4>Wo der Code gewählt wird</h4>
<ul>
    <li><strong>Rechnung, Gutschrift, Offerte, Auftrag</strong> — pro Position. Die Steuer wird pro Code auf der Summe gerechnet und einmal gerundet; das Rechnungstotal wird separat auf 5 Rappen gerundet.</li>
    <li><strong>Journal</strong> — bei der Einzelbuchung mit dem Schalter MwSt: der Betrag ist brutto, das System rechnet die Steuer heraus und bucht sie auf das MWST-Konto des Mandanten. In der Sammelbuchung steht der Code auf der Nettozeile, die Steuerzeile erfasst du selbst.</li>
    <li><strong>Belege vom Handy</strong> — über die Belegvorlage oder im Belegjournal; «ohne MWST» ist eine bewusste Wahl. Ein Beleg mit zwei Sätzen wird aufgeteilt.</li>
</ul>

<h4>Auf welche Konten</h4>
<p>Die Steuer geht auf die MWST-Konten des <strong>Mandanten</strong> (Stammdaten › Firma › Mandant, Reiter «Konten»): Vorsteuer Material (KMU 1170) für VM, Vorsteuer Investitionen (1171) für VI, geschuldete MWST (2200) für UN, UR, US. Befreite und ausgenommene Umsätze tragen den Code ohne Steuerzeile.</p>

<h4>Nicht mehrwertsteuerpflichtig</h4>
<p>Ist im Mandanten «Mehrwertsteuerpflichtig» aus, werden Rechnungen, Gutschriften, Offerten und Auftragsbestätigungen ohne MWST gerechnet und gedruckt, mit dem «Hinweis ohne MWST» unter dem Total. Journal und Belegjournal folgen dem Schalter noch nicht: dort wählst du selbst «ohne MWST» bzw. keinen Code.</p>

<h4>Die MWST-Abrechnung</h4>
<p>Die <strong>MWST-Abrechnung</strong> für die ESTV steht unter Finanzen › MWST-Abrechnungen: pro Periode (Quartal, Monat oder Jahr, wie im Mandanten eingestellt) das Formular mit Umsatz und Steuer pro Ziffer, gerechnet aus den Buchungen mit MWST-Code, woher jede Zahl kommt, die Abstimmung mit den MWST-Konten und ein PDF. Sie wird gelesen, gedruckt und im ePortal der ESTV eingereicht. Unterstützt ist die effektive Methode mit vereinbartem Entgelt.</p>
<h4>Noch nicht verfügbar</h4>
<p>Das <strong>Buchen</strong> der MWST-Abrechnung (die Abrechnungsbuchung auf das Abrechnungskonto, die Sperre der abgerechneten Periode), Korrekturen einer eingereichten Periode und die Bezugsteuer folgen. Ebenso folgt die Übernahme neuer mitgelieferter Codes in eine bestehende Installation — bis dahin einen neuen Code von Hand anlegen.</p>
