<?php
/**
 * Help for «Mahnungen» (ADR-048, HELP-003) — attached by `listAction()` with `toolbar: true`,
 * opened with «Hilfe» at the right end of the toolbar. A quick help: the due list as of a day,
 * the run, the runs so far; one field section for «Stichtag» (`as_of`), the list's one control.
 */
?>
<h3>Mahnungen</h3>
<p>Oben die <strong>fälligen Mahnungen</strong> auf einen Stichtag: jede verbuchte Rechnung mit offenem Betrag, für die die nächste Mahnstufe fällig ist — mit dem offenen Betrag, der bisherigen und der nächsten Stufe und deren Gebühr. Debitoren mit Mahnsperre erscheinen nicht. Die Stufen, ihre Fristen und Gebühren stehen unter Stammdaten › Aufträge › Mahnstufen.</p>
<p><strong>Mahnlauf starten</strong> erstellt für jede angekreuzte Rechnung eine Mahnung auf der nächsten Stufe, datiert auf den Stichtag; alle Fälligen sind vorab angekreuzt. Trägt die Stufe eine Gebühr, wird dazu ein Gebühren-Beleg erstellt und verbucht (ohne MWST). Hat sich seit dem Anzeigen etwas geändert, wird der ganze Lauf abgewiesen und nichts erstellt.</p>
<p>Unten die <strong>Mahnläufe</strong> bisher, je mit ihren Mahnungen: Rechnung, Stufe, offener Betrag, der Gebühren-Beleg und <strong>PDF</strong> — die Mahnung zum Drucken, in einem neuen Tab.</p>

<section data-help-field="as_of">
<h3>Stichtag</h3>
<p>Der Tag, auf den die Liste rechnet und auf den der Mahnlauf datiert wird; leer heisst heute. <strong>Liste aktualisieren</strong> liest die Liste für den gewählten Tag neu. Der Stichtag darf nicht in der Zukunft liegen und nicht vor dem letzten Mahnlauf.</p>
</section>
