<?php
/**
 * Help for the receipt window (ADR-048) — attached by the trait, opened with «Hilfe» in the title
 * bar or F1; a general part, then a section per field (`data-help-field` = the field's `name`, or
 * its `data-help-key`: `template`, `payment_method`, `split`, `bank`). The form itself
 * carries no explanations. Also the KI-Assistent's knowledge (ADR-055).
 */
?>
<h3>Beleg im Belegjournal</h3>
<p>Einen Beleg vom Handy prüfen und ergänzen: links das Foto, rechts Datum, Betrag, Text und die Konten (Soll, Haben, MWST-Code). Ein Beleg mit zwei MWST-Sätzen wird hier aufgeteilt, ein Fehlgriff gelöscht.</p>
<p>Was das Handy geschickt hat, lässt sich korrigieren, bis das Journal verbucht ist; ein verbuchter Beleg wird nur noch angezeigt. <strong>Speichern</strong> geht auch, wenn noch etwas fehlt — erst «Journal verbuchen» verlangt den Beleg vollständig: Soll, Haben, die MWST-Entscheidung und ein Text. Was fehlt, ist am Feld mit «— fehlt» markiert.</p>
<h4>Beleg aufteilen oder löschen</h4>
<p><strong>Aufteilen …</strong> teilt einen Beleg mit zwei MWST-Sätzen in zwei Belege (z.B. Lebensmittel 2.6 % und Haushaltsartikel 8.1 % auf einem Kassenzettel). <strong>Löschen …</strong> nimmt den Beleg aus dem Journal; das Foto kommt in den Papierkorb der Dokumente und lässt sich dort wiederherstellen — es sei denn, ein aufgeteilter Teil braucht es noch, dann bleibt es. «Original öffnen» zeigt das Foto in voller Grösse in einem neuen Tab.</p>
<section data-help-field="date">
<h3>Datum</h3>
<p>Das Belegdatum — an diesem Datum wird gebucht, und es entscheidet, mit welchem Stichtag der Beleg verbucht wird. Nicht in der Zukunft. Zum Verbuchen muss es in einem eröffneten Geschäftsjahr und in einer offenen Periode liegen (gespeichert wird es auch so).</p>
</section>
<section data-help-field="amount">
<h3>Betrag</h3>
<p>Der bezahlte Betrag, wie er auf dem Zettel steht. Was er ist, hängt vom MWST-Code ab:</p>
<ul>
    <li><strong>mit einem Vorsteuer-Code</strong> (VM, VI): der Betrag <strong>inklusive MWST</strong>. Die MWST wird beim Verbuchen herausgerechnet: der Nettobetrag geht ins Soll, die Vorsteuer auf das MWST-Konto des Mandanten.</li>
    <li><strong>«nur Steuer»</strong> (z.B. «VM — nur Steuer»): der Betrag <strong>ist die Steuer selbst</strong>, z.B. die Einfuhrsteuer laut Veranlagungsverfügung des Zolls.</li>
    <li><strong>mit einem Bezugsteuer-Code</strong> (BM, BI, BM2, BI2): der bezahlte Betrag <strong>ohne Schweizer MWST</strong> — er ist die Basis; z77 rechnet die Bezugsteuer obendrauf.</li>
    <li><strong>«ohne MWST»</strong>: der Betrag, wie er ist — nichts wird herausgerechnet.</li>
</ul>
<p>Steht darunter <strong>«Bank · Datum · Betrag»</strong>, hat der Beleg seine Zahlung auf dem Kontoauszug gefunden. Betrag und Haben lassen sich dann nicht mehr ändern — sie stimmen mit der Bank überein.</p>
</section>
<section data-help-field="text">
<h3>Text</h3>
<p>Der Buchungstext, wie er im Journal stehen wird, z.B. «Tankstelle Muster, Diesel». Ohne Text ist der Beleg unvollständig — die Buchhaltung nimmt keine Buchung ohne Text.</p>
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
<p>Das Konto, dem der Betrag belastet wird — meist ein Aufwand (z.B. 6210 Treibstoff). Nummer eintippen oder aus der Liste wählen; nur bebuchbare, aktive Konten. Die MWST-Konten des Mandanten (1170, 1171, 2200) stehen ab «Abrechnung ab» nicht zur Wahl: die Steuer schreibt z77 aus dem MWST-Code.</p>
<p>Bei <strong>«nur Steuer»</strong> setzt z77 das Soll selbst — das Vorsteuerkonto des Codes (VM → 1170, VI → 1171); das Feld ist gesperrt und zeigt «automatisch».</p>
</section>
<section data-help-field="credit">
<h3>Haben</h3>
<p>Woraus bezahlt wurde: die Kasse (1000), das Abrechnungskonto der Firmenkarte, oder bei «privat ausgelegt» das Konto, über das du es dir zurückzahlst. Soll und Haben sind nie dasselbe Konto.</p>
</section>
<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Der MWST-Code des Belegs, z.B. VI (Vorsteuer Investitionen und übriger Betriebsaufwand) oder VM (Material und Dienstleistungen). <strong>«ohne MWST»</strong> ist eine bewusste Wahl (Porti, Bahn international, ein Beleg ohne MWST-Ausweis). «— wählen —» heisst: noch nicht entschieden — der Beleg ist dann unvollständig. Ein Beleg trägt <strong>einen</strong> Code; für zwei Sätze den Beleg aufteilen. Ist die Firma nicht MWST-pflichtig, geht nur «ohne MWST» — wer dann im Jahr für mehr als CHF 10 000 Leistungen aus dem Ausland bezieht, meldet die Bezugsteuer ausserhalb von z77.</p>
<h4>Nur Steuer</h4>
<p>Für eine Quittung, die <strong>nur Steuer</strong> enthält: die Einfuhrsteuer der Veranlagungsverfügung des Zolls, oder eine Vorsteuer, die nachgeholt wird. z77 bucht den ganzen Betrag als Vorsteuer auf das Konto des Codes (Basis 0.00, Satz des Codes). Eine Quittung mit Zoll UND Einfuhrsteuer zuerst <strong>«Aufteilen …»</strong>: der Zoll «ohne MWST», die Einfuhrsteuer «VM — nur Steuer». Das Verlagerungsverfahren wird nicht unterstützt.</p>
<p>Fehler, der oft passiert: die Einfuhrsteuer als normale Zeile «VM» erfassen — dann zieht z77 nur 8.1/108.1 des Betrags als Vorsteuer ab statt des ganzen Betrags.</p>
<h4>Beleg in einer abgerechneten MWST-Periode</h4>
<p>Das Belegdatum bleibt, was auf dem Zettel steht. Liegt es in einer MWST-Periode, die schon abgerechnet ist, holt z77 die <strong>Vorsteuer</strong> nach — im selben Kalenderjahr: der Aufwand wird am Belegdatum ohne MWST gebucht, die Vorsteuer vorerst auf «Vorsteuer, noch nicht abgerechnet» (1179) und am ersten Tag der laufenden Periode auf das Vorsteuerkonto umgebucht. Das Fenster «Journal verbuchen» zeigt es als graue Zeile; der Beleg trägt danach zwei Buchungen.</p>
<p>Liegt das Belegdatum in einem <strong>früheren Kalenderjahr</strong> als die laufende Periode, wird nichts nachgeholt: «Journal verbuchen» lehnt den Beleg ab. Dann den Beleg «ohne MWST» verbuchen und die Vorsteuer von Hand als Korrekturbuchung zu der genannten Periode erfassen. Ein Beleg vor «Abrechnung ab» wird «ohne MWST» verbucht; auch hier wird nichts nachgeholt.</p>
<h4>Bezugsteuer</h4>
<p>Für eine Leistung einer Firma im <strong>Ausland ohne Schweizer MWST-Nummer</strong>, z.B. ein Software-Abo aus den USA: BI (Investitionen, übriger Betriebsaufwand) oder BM (Material, Dienstleistungen), BI2 / BM2 zum reduzierten Satz. z77 bucht in <strong>einer</strong> Buchung am Belegdatum den Aufwand, die Bezugsteuer als Schuld (2200) und dieselbe Steuer als Vorsteuer (1170 / 1171); unter dem Feld stehen Basis und Steuer. Ausländische MWST auf der Quittung ist nie Vorsteuer und gehört nicht in die Basis.</p>
<p>Beispiele: Software aus den USA ohne MWST → BI. Dieselbe Software mit Schweizer MWST und CHE-Nummer auf der Rechnung → VI. Ware aus dem Ausland → keine Bezugsteuer, sondern Einfuhrsteuer («nur Steuer»).</p>
<p>Die Bezugsteuer wird <strong>nie nachgeholt</strong>: liegt das Belegdatum in einer abgerechneten MWST-Periode — auch im selben Kalenderjahr —, lehnt «Journal verbuchen» den Beleg ab. Dann den Beleg «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung zu der genannten Periode erfassen. Nach «Abrechnung bis» bucht z77 keine Bezugsteuer; «Journal verbuchen» weist darauf hin — allenfalls ausserhalb von z77 melden.</p>
</section>
<section data-help-field="bank">
<h3>Zahlung suchen … / Zuordnung lösen</h3>
<p>Für Belege, die mit Bank oder Karte bezahlt sind (Haben ist das Konto eines Bankkontos) — sobald du den Kontoauszug einliest (Finanzen › Bankkonto). Der Beleg findet dort seine Zahlung; die Bewegung gilt dann als geschäftlich.</p>
<ul>
    <li><strong>Zahlung suchen …</strong> zeigt die freien Bewegungen dieses Bankkontos mit dem gleichen Betrag in den Tagen um das Belegdatum (Kartenzahlungen werden ein paar Tage später gebucht). Passt genau eine, ist sie schon angekreuzt; bei mehreren wählst du selbst. Steht ein Wort aus dem Text des Belegs (z.B. der Händler) im Banktext, steht diese Bewegung zuoberst.</li>
    <li><strong>Fremdwährung:</strong> Hast du den Betrag vom Zettel in Euro oder Dollar getippt, findet die Suche die Bewegung mit diesem Originalbetrag (z.B. 50.00 EUR). Sie ist nie angekreuzt. Wählst du sie, wird der Betrag des Belegs zum Betrag in Franken, den die Bank belastet hat.</li>
    <li><strong>Zuordnung lösen:</strong> War es die falsche Bewegung, löst du sie hier — die Bewegung ist wieder frei. Bei einem verbuchten Beleg geht das nicht.</li>
</ul>
<p>Aufteilen geht bei einem zugeordneten Beleg nicht; zuerst die Zuordnung lösen. Löschst du den Beleg, ist seine Bewegung wieder frei.</p>
</section>
<section data-help-field="split">
<h3>Betrag des neuen Teils</h3>
<p>So viel wird als eigener Beleg abgeteilt; der Beleg behält den Rest — zusammen ergeben sie wieder den Betrag des Zettels. Der neue Teil hat dasselbe Foto, Datum, Text und «Bezahlt mit»; Soll und MWST-Code setzt du danach in seinem Fenster.</p>
</section>
