<?php
/**
 * Help for the list «Steuercodes» (MWST-Codes, ADR-048, HELP-003: «Hilfe» at the right end of the
 * toolbar) — codes, dated rates, the rules for a rate change, and what the VAT module does NOT do
 * yet (the VAT return). The dialogs are modals (their hints stand in the form), so this list help
 * also explains their fields. Also the KI-Assistent's knowledge (ADR-055): «MWST-Satz ändern»,
 * «neuer Steuersatz», «MWST-Code».
 */
?>
<h3>MWST-Codes</h3>
<p>Die <strong>MWST-Codes</strong> sagen, welche Mehrwertsteuer auf einer Rechnung, Buchung oder einem Beleg gilt, z.B. UN beim Verkauf, VM beim Einkauf. Hier legst du Codes an, trägst einen neuen Steuersatz ab einem Datum ein oder deaktivierst einen Code.</p>
<p>Ist die Firma nicht mehrwertsteuerpflichtig (Stammdaten › Firma › Mandant, Reiter «UID und MWST»), rechnen Rechnungen, Gutschriften, Offerten und Auftragsbestätigungen ohne MWST und ohne Code.</p>
<ul>
    <li><strong>+ Steuercode</strong> legt einen neuen Code an; das <strong>⋮</strong> einer Zeile öffnet «Bearbeiten», «Neuer Satz gültig ab …» und die Liste der Sätze.</li>
    <li>Pro Code stehen alle <strong>Sätze</strong> mit ihrem Beginn; der heute gültige ist fett und rechts als Badge. «noch nicht in Kraft» = der erste Satz beginnt später; «kein Satz» = mit dem Code kann noch nichts gerechnet werden.</li>
    <li>Der <strong>Schalter</strong> am Zeilenanfang ist «aktiv»: ein inaktiver Code wird für neue Rechnungen, Buchungen und Vorlagen nicht mehr angeboten; was ihn schon trägt, behält ihn. Gelöscht wird ein Code nie.</li>
</ul>

<h3>Code und Kategorie</h3>
<p>Der <strong>Code</strong> (2–8 Zeichen, A–Z und 0–9) ist nach dem Anlegen fix — Rechnungen und Buchungen speichern ihn. Die <strong>Kategorie</strong> sagt, was für eine Steuer es ist: Normalsatz, reduzierter Satz, Sondersatz (Beherbergung), befreit (0 %), ausgenommen, Bezugsteuer, Vorsteuer Material / Dienstleistungen, Vorsteuer Investitionen / übriger Aufwand. Sie entscheidet, auf welches MWST-Konto des Mandanten die Steuer gebucht wird.</p>
<p>Mitgeliefert für die Schweiz: UN (Normalsatz), UR (reduziert), US (Sondersatz), UE (befreit), UA (ausgenommen), VM und VI (Vorsteuer zum Normalsatz). Einen Code für Bezugsteuer oder Vorsteuer zum reduzierten Satz legst du bei Bedarf selbst an.</p>

<h3>Neuer Satz gültig ab</h3>
<p>Ändert der Bund einen Satz, trägst du mit <strong>«Neuer Satz gültig ab …»</strong> den neuen Satz (z.B. 8.1) mit seinem Beginn ein. Der bisherige gilt bis zum Vortag weiter — eine Rechnung mit einem älteren Leistungsdatum rechnet weiterhin mit dem alten Satz. Massgebend ist das <strong>Leistungsdatum</strong> eines Belegs, nicht das Rechnungsdatum.</p>
<ul>
    <li>Der Beginn ist heute oder später. Früher nur für den ersten Satz eines Codes oder vor dem ältesten bestehenden Satz (Historie nachtragen).</li>
    <li>Ein Satz, der schon gilt, wird nie geändert oder entfernt (Badge «fix»). <strong>«Entfernen»</strong> gibt es nur für einen Satz, der noch nicht gilt — oder einen heute für heute erfassten (Tippfehler). Ein falscher Satz, der schon verrechnet wurde, wird mit einem neuen Satz ab heute und Gutschriften korrigiert.</li>
</ul>

<h3>Noch nicht verfügbar</h3>
<p>Die <strong>MWST-Abrechnung</strong> — das Formular für die ESTV pro Quartal oder Semester mit Umsatz und Steuer pro Code und der Abrechnungsbuchung — folgt. Ebenso fehlt die Übernahme neuer mitgelieferter Codes in eine bestehende Installation: ein neuer Code wird hier von Hand angelegt.</p>
