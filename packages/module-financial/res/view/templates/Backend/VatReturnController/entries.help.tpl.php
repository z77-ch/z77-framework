<?php
/**
 * Help for «Buchungen einer Ziffer» of the VAT return (ADR-048, HELP-003 — a list: its trigger at
 * the right end of the toolbar): what the list holds, the chips, the row icons, the amounts; field
 * sections for the search fields. Also what the KI-Assistent knows (ADR-055).
 */
?>
<h3>Buchungen einer Ziffer</h3>
<p>Alle Buchungszeilen mit MWST-Code, aus denen eine Ziffer der MWST-Abrechnung besteht. Geöffnet aus dem Reiter «Herkunft»: die dort gewählte <strong>Herkunft</strong> und das <strong>Konto</strong> stehen als Filter neben dem Titel. Das Total unter der Liste ist genau die Zahl aus «Herkunft». Die Reiter bleiben stehen — «Herkunft» führt zurück.</p>
<section data-help-field="filter">
<h3>Filter Herkunft und Konto</h3>
<p>Das <strong>×</strong> neben einem Filter hebt ihn auf: ohne «Herkunft» stehen die Buchungen der Ziffer aus allen Quellen da, ohne «Konto» die von allen Konten. «Zurücksetzen» hebt die Suche in den Spalten und die Sortierung auf, die Filter bleiben.</p>
</section>
<section data-help-field="open">
<h3>Symbole vorn</h3>
<p>Das erste Symbol öffnet die Buchung im Journal. Das zweite öffnet die Quelle — die Rechnung als PDF, das Foto des Belegs, die Kreditorenrechnung; bei einer manuellen Buchung fehlt es.</p>
</section>
<section data-help-field="f_date">
<h3>Datum</h3>
<p>Das Datum der Buchung. Suchen: ein Tag (15.08.2026), ein Monat (08.2026) oder ein Jahr — die Suche gilt immer innerhalb der Periode.</p>
</section>
<section data-help-field="f_nr">
<h3>Nr.</h3>
<p>Die Nummer der Buchung im Journal («2026/17»). Gesucht wird die Nummer ohne das Jahr.</p>
</section>
<section data-help-field="f_text">
<h3>Text</h3>
<p>Der Text der Buchung und, klein dahinter, der Text der Zeile. Die Suche findet beide.</p>
</section>
<section data-help-field="amounts">
<h3>Umsatz und Steuer</h3>
<p><strong>Umsatz</strong> ist der Betrag ohne MWST, wie er gebucht ist (eine Gutschrift mit Minus), <strong>Steuer</strong> die gebuchte MWST der Zeile. <strong>Code</strong> und <strong>Satz</strong> sind der MWST-Code der Zeile und sein Satz am Datum der Buchung. <strong>Herkunft</strong> nennt die Quelle; «Minderung» heisst, die Zeile zählt in Ziffer 235, «Storno-Paar», dass sie mit ihrem Storno in Ziffer 200 aufgeht.</p>
</section>
