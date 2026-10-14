<?php
/**
 * Help for the product-group window (ADR-048) — attached by the trait, opened with «Hilfe» in
 * the title bar or F1; a section per field (`data-help-field` = the field's `name`).
 *
 * @var bool $ledger  module-financial is there — the account is checked
 */
?>
<h3>Warengruppe</h3>
<p>Die Warengruppen ordnen die Artikel in <strong>einem</strong> Baum beliebiger Tiefe — eine Ebene, wo nur eine Art Ware verkauft wird, zwei oder drei, wo Geschäftsbereiche getrennt ausgewertet werden (z.B. «Dienstleistungen › Beratung» und «Dienstleistungen › Hosting»). Jeder Artikel hängt in genau einer Gruppe, auf jeder Ebene. Die Artikelnummer hängt nicht an der Gruppe: ein Artikel lässt sich jederzeit umhängen.</p>
<p>Eine Gruppe wird <strong>deaktiviert</strong> (Schalter «Aktiv» im Baum), wenn neue Artikel nicht mehr hinein sollen — Artikel, die darin hängen, bleiben. <strong>Löschen …</strong> geht nur, solange die Gruppe leer ist: keine Untergruppe, kein Artikel.</p>
<p>Die Zahl rechts im Baum zählt die Artikel der Gruppe samt Untergruppen; ein Klick zeigt sie in der Artikelliste.</p>

<section data-help-field="name">
<h3>Name</h3>
<p>Höchstens 80 Zeichen, eindeutig auf seiner Ebene — derselbe Name unter zwei verschiedenen Gruppen ist erlaubt.</p>
</section>

<section data-help-field="parent_id">
<h3>Übergeordnete Gruppe</h3>
<p>Unter welcher Gruppe diese hängt; «keine» macht sie zur obersten Ebene. Eine andere Wahl <strong>verschiebt</strong> die Gruppe mit allen Untergruppen und Artikeln — an den Artikeln selbst ändert sich nichts. Eine Gruppe kann nicht unter sich selbst oder eine ihrer Untergruppen gehängt werden; darum fehlen diese in der Auswahl.</p>
</section>

<section data-help-field="default_tax_code">
<h3>MWST-Code (Vorgabe)</h3>
<p>Der MWST-Code, den ein <strong>neuer</strong> Artikel in dieser Gruppe vorgeschlagen bekommt. Leer gelassen, gilt die Vorgabe der nächsten Gruppe darüber («geerbt»). Eine geänderte Vorgabe ändert keinen bestehenden Artikel — MWST-Code und Konto stehen am Artikel.</p>
</section>

<section data-help-field="default_revenue_account">
<h3>Ertragskonto (Vorgabe)</h3>
<p>Das Ertragskonto, das ein <strong>neuer</strong> Artikel in dieser Gruppe vorgeschlagen bekommt: ein Konto vom Typ «Ertrag», im Kontenplan <strong>auf Belegen wählbar</strong>. Leer gelassen, gilt die Vorgabe der nächsten Gruppe darüber. Bestehende Artikel behalten ihr Konto.<?php if (empty($ledger)): ?> Ohne Buchhaltungsmodul wird die Nummer nicht geprüft.<?php endif; ?></p>
</section>
