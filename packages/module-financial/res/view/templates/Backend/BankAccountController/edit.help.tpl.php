<?php
/**
 * Help for the bank account window (ADR-048) — a general part and a section per field
 * (`data-help-field` = the name of the field, or its `data-help-key`). Everything the screen
 * does not say stands here (backend-screen.md §5). Also what the KI-Assistent knows (ADR-055).
 */
?>
<h3>Bankkonto</h3>
<p>Ein Bankkonto sagt, <strong>welche IBAN welches Konto der Buchhaltung ist</strong>. Das steht nur hier. Zahlungsarten, die über eine Bank zahlen, verweisen auf das Bankkonto und nehmen ihr Haben-Konto von ihm.</p>
<p>Ein Bankkonto wird nie gelöscht, nur <strong>deaktiviert</strong> (Schalter «Aktiv» in der Liste).</p>
<p>Steht im Fenster ein gelber Hinweis «Zahlungsziel … führt diese IBAN mit Konto …», dann nennt ein Zahlungsziel der Debitoren für dieselbe IBAN ein anderes Konto. Eines von beiden richtigstellen.</p>
<section data-help-field="label">
<h3>Bezeichnung</h3>
<p>Der Name in Listen und Auswahlen, z.B. «Geschäftskonto».</p>
</section>
<section data-help-field="code">
<h3>Kürzel</h3>
<p>Der Schlüssel, den Zahlungsarten speichern: 2–16 Zeichen, Kleinbuchstaben, Ziffern und Bindestrich, mit einem Buchstaben beginnend. Nach dem Anlegen fix.</p>
</section>
<section data-help-field="iban">
<h3>IBAN</h3>
<p>Die IBAN des Kontos bei der Bank. An ihr erkennt z77 beim Einlesen, zu welchem Bankkonto ein Kontoauszug gehört. Sobald ein Kontoauszug eingelesen ist, lässt sie sich nicht mehr ändern — eine neue IBAN ist ein neues Bankkonto.</p>
</section>
<section data-help-field="ledger_account">
<h3>Konto</h3>
<p>Das Konto der Buchhaltung, auf dem diese Bank geführt wird, z.B. 1020. Nur bebuchbare, aktive Konten.</p>
</section>
<section data-help-field="currency">
<h3>Währung</h3>
<p>Immer die Währung der Buchhaltung. Ein Konto in einer anderen Währung kann noch nicht geführt werden; die Zahlungsart dafür behält ihr eigenes Haben-Konto.</p>
</section>
<section data-help-field="statement_import">
<h3>Kontoauszug einlesen</h3>
<p><strong>Ein:</strong> Kontoauszüge dieses Kontos (Datei camt.053 aus dem E-Banking) können unter Finanzen › Bankkonto eingelesen werden. Die Bewegungen dienen als Auskunft für die Kreditoren: wann wurde eine Rechnung bezahlt, wie viele Franken hat die Bank belastet. Verbucht wird dabei nichts.</p>
<p><strong>Wichtig:</strong> gespeichert wird <strong>jede</strong> Bewegung des Kontos — auch private. Sie liegt auf dem Server, die Datei 10 Jahre im Archiv.</p>
<p><strong>Aus:</strong> das Bankkonto ist nur Stamm. Kein Upload, keine Bewegungen.</p>
</section>
