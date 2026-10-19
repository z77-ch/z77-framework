<?php
/**
 * Help for the article window (ADR-048) — attached by the trait, opened with «Hilfe» in the
 * title bar or F1; a section per field (`data-help-field` = the field's `name`). The form
 * itself carries no explanations.
 *
 * @var bool $ledger  module-financial is there — the account is checked
 */
?>
<h3>Artikel</h3>
<p>Ein Artikel ist das, was eine Auftragsposition vorschlägt: Nummer, Bezeichnung, Einheit, Preis, MWST-Code und Ertragskonto. Die Position übernimmt die Werte beim Speichern als Kopie — ändert der Artikel später, bleiben bestehende Aufträge und Rechnungen, wie sie sind. Text und Preis lassen sich auf der Position überschreiben; MWST-Code und Konto kommen immer vom Artikel (ein anderes Konto ist eine freie Position).</p>
<p>Ein Artikel wird <strong>deaktiviert</strong> (Schalter «Aktiv» in der Liste), sobald er nicht mehr angeboten werden soll — bestehende Positionen behalten ihn. <strong>Löschen …</strong> geht nur, solange ihn keine Position verwendet.</p>

<section data-help-field="product_group">
<h3>Warengruppe</h3>
<p>Jeder Artikel hängt in genau <strong>einer</strong> Warengruppe — in einer Hauptgruppe oder in einer Untergruppe, jede Ebene ist erlaubt. Die Warengruppen werden unter Stammdaten › Aufträge › Warengruppen gepflegt; angeboten werden die aktiven, eine inaktiv gestellte bleibt für den Artikel, der schon darin hängt.</p>
<p>Ein <strong>neuer</strong> Artikel übernimmt MWST-Code und Ertragskonto, die die Warengruppe vorgibt (oder die nächste Gruppe darüber, die etwas vorgibt): vorausgefüllt, wenn «+ Artikel» bei gewählter Warengruppe geöffnet wird, und beim Anlegen für ein leer gelassenes Feld. Bestehende Artikel ändern sich nie, wenn eine Vorgabe ändert. Wird eine Warengruppe verschoben, wandern ihre Artikel mit.</p>
</section>

<section data-help-field="number">
<h3>Artikelnummer</h3>
<p>Frei wählbar, ohne Leerzeichen, höchstens 32 Zeichen, eindeutig über alle Artikel. Sie wird auf Offerten, Lieferscheinen und Rechnungen gedruckt — darum nicht aus einer Gruppe abgeleitet. Eine geänderte Nummer gilt für neue Positionen; gedruckte Belege behalten die alte.</p>
</section>

<section data-help-field="name">
<h3>Bezeichnung</h3>
<p>Der Text, den eine neue Position vorschlägt (die erste Zeile, darunter die Beschreibung). Auf der Position lässt er sich ergänzen oder ersetzen (z.B. «Beratung Oktober»).</p>
</section>

<section data-help-field="description">
<h3>Beschreibung</h3>
<p>Freiwillig, mehrzeilig, höchstens 1500 Zeichen — gilt für den Artikel als Ganzes (alle Varianten). Wird der Artikel auf einer Auftragsposition gewählt, steht im Text der Position zuerst die Bezeichnung, ab der zweiten Zeile die Beschreibung. Auf der Position bleibt alles änderbar; geändert wird nur diese Position, nicht der Artikel. Auf Offerte, Auftragsbestätigung, Lieferschein und Rechnung wird die erste Zeile fett gedruckt, die Beschreibung darunter normal.</p>
</section>

<section data-help-field="unit">
<h3>Einheit</h3>
<p>«Stk», «h», «Fl.» … — leer für eine Pauschale ohne Menge. Ein Stunden- und ein Viertelstundentarif sind derselbe Artikel: die Menge darf Dezimalen haben (0.25).</p>
</section>

<section data-help-field="price">
<h3>Preis</h3>
<p>Der Preis pro Einheit, nicht negativ — eine Rücknahme ist eine Position mit negativer Menge. Eine Position schlägt den Preis vor, der am <strong>Auftragsdatum</strong> gilt.</p>
</section>

<section data-help-field="valid_from">
<h3>Gültig ab</h3>
<p>Ab welchem Tag der Preis gilt (vorgeschlagen: heute). Ein geänderter Preis wird als <strong>neuer Eintrag</strong> im Preisverlauf gespeichert, der alte bleibt für Aufträge mit früherem Datum gültig. Am selben Tag nochmals geändert, ersetzt er den Eintrag dieses Tages. Unverändert gespeichert, entsteht kein Eintrag.</p>
</section>

<section data-help-field="price_mode">
<h3>Preis ist netto / brutto</h3>
<p>Ob der Preis die MWST schon enthält. Ein Auftrag in der anderen Art rechnet ihn beim Übernehmen in die Position um.</p>
</section>

<section data-help-field="tax_code">
<h3>MWST-Code</h3>
<p>Angeboten werden die aktiven Codes; ein Code, den der Artikel schon trägt, bleibt wählbar, auch wenn er inzwischen deaktiviert ist. Der Satz richtet sich nach dem Leistungsdatum des Belegs, nicht nach dem Artikel. Ein neuer Artikel, bei dem das Feld leer bleibt, übernimmt den Code, den seine Warengruppe vorgibt.</p>
</section>

<section data-help-field="revenue_account">
<h3>Ertragskonto</h3>
<p>Das Konto, auf das der Ertrag einer Position gebucht wird: nur ein Konto vom Typ «Ertrag», das im Kontenplan <strong>auf Belegen wählbar</strong> ist (angeboten werden genau diese). Ein Konto, das im Kontenplan nicht mehr auf Belegen wählbar ist, muss beim nächsten Speichern ersetzt werden. Ein neuer Artikel, bei dem das Feld leer bleibt, übernimmt das Konto, das seine Warengruppe vorgibt.<?php if (empty($ledger)): ?> Ohne Buchhaltungsmodul wird die Nummer nicht geprüft.<?php endif; ?></p>
</section>

<section data-help-field="stock_relevant">
<h3>Führt Bestand</h3>
<p>Ob für diesen Artikel ein Lagerbestand geführt wird (Ware) oder nicht (Dienstleistung, Gebühr). Wirksam mit der Lagerbuchhaltung: dann reserviert und bucht der Auftrag den Bestand je nach Status. Ein später ausgeschalteter Bestand gibt Reservierungen wieder frei.</p>
</section>
