<?php
/**
 * Help for the template window (ADR-048) — attached by the trait, opened with «Hilfe» in the title
 * bar or F1; a section per field (`data-help-field` = the field's `name`). The form itself carries
 * no explanations. Also the KI-Assistent's knowledge (ADR-055).
 */
?>
<h3>Belegvorlage</h3>
<p>Eine Belegvorlage ist eine <strong>Kachel auf dem Handy</strong> — «Tanken», «Büromaterial», «Spesen Essen». Sie sagt, <strong>was</strong> gekauft wurde: das Soll-Konto und den MWST-Code. <strong>Wie</strong> bezahlt wurde (Kasse, Firmenkarte, privat ausgelegt — das Haben-Konto) wählt das Handy daneben unter «Bezahlt mit»; das sind die Zahlungsarten unter Stammdaten › Finanzen.</p>
<p>Jedes gekoppelte Handy zeigt alle <strong>aktiven</strong> Vorlagen in ihrer Reihenfolge, dazu immer «Ohne Vorlage». Eine Änderung gilt für neue Belege; ein erfasster Beleg hat Soll und MWST beim Erfassen übernommen und behält sie. Eine Vorlage wird nie gelöscht, nur <strong>deaktiviert</strong> (Schalter «Aktiv» in der Liste) — die Belege, die sie tragen, behalten sie.</p>
<section data-help-field="label">
<h3>Name</h3>
<p>So heisst die Kachel auf dem Handy, und so lautet der Text eines Belegs, wenn auf dem Handy keiner getippt wird. Kurz halten: «Tanken», «Parkieren».</p>
</section>
<section data-help-field="code">
<h3>Code</h3>
<p>Der Schlüssel, den jeder Beleg speichert: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend. Nach dem Anlegen fix — für eine andere Bedeutung eine neue Vorlage anlegen.</p>
</section>
<section data-help-field="icon">
<h3>Symbol</h3>
<p>Das Bild auf der Kachel, aus einer festen Auswahl (kein Hochladen). «Standard» zeigt das allgemeine Belegsymbol.</p>
</section>
<section data-help-field="position">
<h3>Reihenfolge</h3>
<p>In welcher Folge die Kacheln auf dem Handy und in dieser Liste stehen — kleinere Zahl zuerst. Mehrere Vorlagen dürfen dieselbe Zahl haben. Tipp: in Zehnerschritten nummerieren, dann lässt sich später eine dazwischen schieben.</p>
</section>
<section data-help-field="debit_account">
<h3>Soll</h3>
<p>Das Konto, dem der Betrag belastet wird — der Aufwand, z.B. 6210 Treibstoff, 6500 Büromaterial, 6640 Reisespesen. Nur bebuchbare, aktive Konten. Im Belegjournal lässt es sich pro Beleg noch ändern.</p>
</section>
<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Der MWST-Code der Belege dieser Vorlage, z.B. VI (Vorsteuer Investitionen und übriger Betriebsaufwand). <strong>«ohne MWST»</strong> ist eine bewusste Wahl (Porti, Bahn international) — ein Beleg mit dieser Vorlage ist damit entschieden. Hat ein Zettel zwei MWST-Sätze, wird der Beleg im Belegjournal aufgeteilt.</p>
</section>
