<?php
/**
 * Help for the list «Steuercodes» (MWST-Codes, ADR-048, HELP-003: «Hilfe» at the right end of the
 * toolbar) — codes, dated rates, the rules for a rate change, the shipped codes and their upgrade, and
 * what the VAT module does NOT do (partial deduction). The dialogs are modals (their hints stand in the form), so this list help
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
<p>Der <strong>Code</strong> (2–8 Zeichen, A–Z und 0–9) ist nach dem Anlegen fix — Rechnungen und Buchungen speichern ihn. Die <strong>Kategorie</strong> sagt, was für eine Steuer es ist: Normalsatz, reduzierter Satz, Sondersatz (Beherbergung), befreit (0 %), ausgenommen, Bezugsteuer, Vorsteuer Material / Dienstleistungen, Vorsteuer Investitionen / übriger Aufwand, Einlageentsteuerung, Vorsteuerkorrektur, Vorsteuerkürzung. Sie entscheidet, auf welches MWST-Konto des Mandanten die Steuer gebucht wird und in welche Ziffer der MWST-Abrechnung sie fällt.</p>
<p>Mitgeliefert für die Schweiz: UN (Normalsatz), UR (reduziert), US (Sondersatz), UE (befreit, Export), UL (Leistungen im Ausland), UA (ausgenommen), VM und VI (Vorsteuer zum Normalsatz), BM und BI (Bezugsteuer zum Normalsatz), BM2 und BI2 (Bezugsteuer zum reduzierten Satz), VE (Einlageentsteuerung, Ziffer 410), VK (Vorsteuerkorrektur: gemischte Verwendung, Eigenverbrauch, Ziffer 415) und VN (Vorsteuerkürzung: Subventionen, öffentliche Beiträge, Ziffer 420). Fehlt einer davon in einer bestehenden Installation, legt z77 ihn beim Öffnen dieser Liste an; einen Code gleichen Namens mit anderer Kategorie ändert z77 nie — er wird gemeldet und von Hand richtiggestellt.</p>
<p>VE, VK und VN rechnen nichts: den Betrag erfasst du von Hand in der Sammelbuchung; Rechnungen, Belege und Kreditorenrechnungen bieten sie nicht an. Eine Vorsteuer zum reduzierten Satz legst du bei Bedarf selbst an (Kategorie Vorsteuer, Satz 2.6).</p>

<h3>Neuer Satz gültig ab</h3>
<p>Ändert der Bund einen Satz, trägst du mit <strong>«Neuer Satz gültig ab …»</strong> den neuen Satz (z.B. 8.1) mit seinem Beginn ein. Der bisherige gilt bis zum Vortag weiter — eine Rechnung mit einem älteren Leistungsdatum rechnet weiterhin mit dem alten Satz. Massgebend ist das <strong>Leistungsdatum</strong> eines Belegs, nicht das Rechnungsdatum.</p>
<ul>
    <li>Der Beginn ist heute oder später. Früher nur für den ersten Satz eines Codes oder vor dem ältesten bestehenden Satz (Historie nachtragen).</li>
    <li>Ein Satz, der schon gilt, wird nie geändert oder entfernt (Badge «fix»). <strong>«Entfernen»</strong> gibt es nur für einen Satz, der noch nicht gilt — oder einen heute für heute erfassten (Tippfehler). Ein falscher Satz, der schon verrechnet wurde, wird mit einem neuen Satz ab heute und Gutschriften korrigiert.</li>
</ul>

<h3>Noch nicht verfügbar</h3>
<p>Eine teilweise abziehbare Vorsteuer: z77 rechnet die Bezugsteuer mit vollem Vorsteuerabzug; eine Kürzung buchst du von Hand mit VK.</p>
