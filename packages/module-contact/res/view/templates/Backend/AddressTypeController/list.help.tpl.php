<?php
/**
 * Help for Stammdaten › Kontakte › Adresstypen (ADR-048, HELP-003) — attached by `listAction()`
 * with `toolbar: true`. The type dialog is a modal popup without help of its own, so its two
 * fields are explained here. Static text.
 */
?>
<h3>Adresstypen</h3>
<p>Welche Arten von Adressen ein Kontakt haben kann — Hauptadresse, Rechnungsadresse, Lieferadresse, Newsletter …: Adresstyp anlegen, umbenennen oder deaktivieren.</p>
<p>Ein Beleg sucht sich die Adresse über ihren Typ: die Rechnung nimmt die <strong>Rechnungsadresse</strong> (Code <code>invoice</code>), sonst die <strong>Hauptadresse</strong> (Code <code>main</code>). Die Zahl in einer Zeile sagt, wie viele Adressen den Typ verwenden.</p>
<ul>
    <li><strong>+ Adresstyp</strong> legt einen neuen Typ an, <strong>⋮</strong> öffnet einen bestehenden.</li>
    <li><strong>Code</strong> — 2–16 Zeichen a–z, 0–9 und Bindestrich, nach dem Anlegen fix: die Adressen speichern den Code. Eine andere Bedeutung ist ein neuer Typ. Die Codes <code>main</code>, <code>invoice</code> und <code>newsletter</code> haben eine feste Bedeutung — nicht für etwas anderes verwenden.</li>
    <li><strong>Bezeichnung</strong> — was die Auswahl und die Kontaktliste zeigen; jederzeit änderbar.</li>
    <li><strong>Aktiv</strong> — ein deaktivierter Typ wird für neue Adressen nicht mehr angeboten; bestehende Adressen behalten ihn. Gelöscht wird ein Typ nie.</li>
</ul>
<p>Ein neuer Typ aus einer späteren Version kommt nicht von selbst in eine bestehende Installation: er wird hier angelegt (z.B. Code <code>newsletter</code>, Bezeichnung «Newsletter»). <strong>Noch nicht verfügbar:</strong> der Newsletter-Versand — folgt.</p>
