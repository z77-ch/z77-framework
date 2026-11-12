<?php
/**
 * Help for the receipt window (ADR-048) — attached by the trait, opened with «Hilfe» in the title
 * bar or F1; a general part, then a section per field (`data-help-field` = the field's `name`, or
 * its `data-help-key`: `template`, `payment_method`, `split`, `delete_photo`). The form itself
 * carries no explanations. Also the KI-Assistent's knowledge (ADR-055).
 */
?>
<h3>Beleg im Belegjournal</h3>
<p>Links das <strong>Foto</strong> des Belegs, rechts die Felder. Was das Handy geschickt hat, lässt sich hier korrigieren und ergänzen, bis das Journal verbucht ist. <strong>Speichern</strong> geht auch, wenn noch etwas fehlt — erst «Journal verbuchen» verlangt den Beleg vollständig: Soll, Haben, die MWST-Entscheidung und ein Text. Was fehlt, ist am Feld mit «— fehlt» markiert.</p>
<p><strong>Aufteilen …</strong> teilt einen Beleg mit zwei MWST-Sätzen in zwei Belege (z.B. Lebensmittel 2.6 % und Haushaltsartikel 8.1 % auf einem Kassenzettel). <strong>Löschen …</strong> nimmt den Beleg aus dem Journal; das Foto bleibt in den Dokumenten, ausser du schaltest «Foto auch löschen» ein. «Original öffnen» zeigt das Foto in voller Grösse in einem neuen Tab.</p>
<section data-help-field="date">
<h3>Datum</h3>
<p>Das Belegdatum — an diesem Datum wird gebucht, und es entscheidet, mit welchem Stichtag der Beleg verbucht wird. Nicht in der Zukunft. Es muss in einem eröffneten Geschäftsjahr und in einer offenen Periode liegen.</p>
</section>
<section data-help-field="amount">
<h3>Betrag (inkl. MWST)</h3>
<p>Der Betrag, wie er auf dem Zettel steht — mit MWST. Die MWST wird beim Verbuchen aus dem Betrag herausgerechnet: der Nettobetrag geht ins Soll, die Vorsteuer auf das MWST-Konto des Mandanten.</p>
</section>
<section data-help-field="text">
<h3>Text</h3>
<p>Der Buchungstext, wie er im Journal stehen wird, z.B. «Tankstelle Oberland Hinwil, Diesel». Ohne Text ist der Beleg unvollständig — die Buchhaltung nimmt keine Buchung ohne Text.</p>
</section>
<section data-help-field="template">
<h3>Vorlage</h3>
<p>Die Vorlage, die auf dem Handy gewählt wurde (die Kachel). Eine andere Vorlage wählen <strong>füllt Soll und MWST-Code</strong> mit ihren Werten — danach frei änderbar. Gespeichert wird, was in Soll und MWST steht; der Beleg merkt sich weiterhin die Vorlage vom Handy. Die Vorlagen werden unter Stammdaten › Finanzen › Belegvorlagen gepflegt.</p>
</section>
<section data-help-field="payment_method">
<h3>Bezahlt mit</h3>
<p>Wie der Beleg bezahlt wurde — Kasse, Firmenkarte, privat ausgelegt; auf dem Handy gewählt. Eine andere Zahlungsart wählen <strong>füllt Haben</strong> mit ihrem Konto. Gespeichert wird, was in Haben steht. Die Zahlungsarten werden unter Stammdaten › Finanzen › Zahlungsarten gepflegt.</p>
</section>
<section data-help-field="debit">
<h3>Soll</h3>
<p>Das Konto, dem der Betrag belastet wird — meist ein Aufwand (z.B. 6210 Treibstoff). Nummer eintippen oder aus der Liste wählen; nur bebuchbare, aktive Konten.</p>
</section>
<section data-help-field="credit">
<h3>Haben</h3>
<p>Woraus bezahlt wurde: die Kasse (1000), das Abrechnungskonto der Firmenkarte, oder bei «privat ausgelegt» das Konto, über das du es dir zurückzahlst. Soll und Haben sind nie dasselbe Konto.</p>
</section>
<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Der MWST-Code des Belegs, z.B. VI (Vorsteuer Investitionen und übriger Betriebsaufwand) oder VM (Material und Dienstleistungen). <strong>«ohne MWST»</strong> ist eine bewusste Wahl (Porti, Bahn international, ein Beleg ohne MWST-Ausweis). «— wählen —» heisst: noch nicht entschieden — der Beleg ist dann unvollständig. Ein Beleg trägt <strong>einen</strong> Code; für zwei Sätze den Beleg aufteilen.</p>
</section>
<section data-help-field="split">
<h3>Betrag des neuen Teils</h3>
<p>So viel wird als eigener Beleg abgeteilt; der Beleg behält den Rest — zusammen ergeben sie wieder den Betrag des Zettels. Der neue Teil hat dasselbe Foto, Datum, Text und «Bezahlt mit»; Soll und MWST-Code setzt du danach in seinem Fenster.</p>
</section>
<section data-help-field="delete_photo">
<h3>Foto auch löschen</h3>
<p>Nur angeboten, wenn kein anderer Beleg (ein aufgeteilter Teil) das Foto noch braucht. Eingeschaltet geht das Foto in den Papierkorb der Dokumente und lässt sich dort wiederherstellen; ausgeschaltet bleibt es unter «Belege».</p>
</section>
