<?php
/**
 * Base knowledge of the KI-Assistent (ADR-055) — the installation's own company (Mandant) across
 * the screens: where the letterhead, the logos, UID / VAT liability and the ledger accounts are
 * kept, what reads them (PDFs, e-mails, postings), and what lives elsewhere (bank / QR-bill on the
 * payment target). Not attached to a screen: `HelpKnowledge` indexes every `*.help.tpl.php` under
 * the module's template root. Static text only — no controller variables.
 */
?>
<h3>Mandant: Firmendaten, Logo, UID/MWST</h3>
<p>Wo die eigenen Firmendaten stehen und was sie bewirken: Adresse und Logo auf Rechnungen und E-Mails, UID und Mehrwertsteuerpflicht, Konten für Debitoren, MWST und Jahreseröffnung — und warum die Bankverbindung nicht hier steht.</p>

<h4>Ein Mandant, eine Seite</h4>
<p><strong>Stammdaten › Firma › Mandant</strong> ist eine einzige Seite mit vier Reitern; es gibt genau einen Mandanten. Ist noch keiner erfasst, legt der Knopf «Mandant anlegen» ihn an — die Konten sind dann mit dem KMU-Kontenrahmen vorbelegt. Die Startseite meldet «Mandant nicht erfasst» und «Mandant MWST-pflichtig ohne UID».</p>

<h4>Briefkopf und Logos</h4>
<p>Name, Adresszusätze, Adresse, E-Mail, Telefon und Website stehen oben auf Rechnungen, Gutschriften, Mahnungen, Offerten, Auftragsbestätigungen und Lieferscheinen und klein am Fuss jeder E-Mail; der Name auch als Aussteller im Kopf der Auswertungen (Bilanz, Kontoblatt …). Zwei Logos: <strong>«Logo»</strong> für die PDFs, <strong>«Logo (E-Mail)»</strong> ein kleines Bild (PNG, JPG, GIF, etwa 100 px breit) oben in den E-Mails. Beide sind Pfade relativ zum Projekt; fehlt die Datei, erscheint einfach kein Logo.</p>

<h4>UID und MWST</h4>
<p>Die UID (CHE-123.456.789) wird mit Prüfziffer geprüft. <strong>«Mehrwertsteuerpflichtig»</strong> entscheidet, ob Rechnungen, Gutschriften, Offerten und Auftragsbestätigungen MWST rechnen; aus, druckt jedes Dokument den <strong>«Hinweis ohne MWST»</strong> unter dem Total. Eine schon erstellte Rechnung behält, wie sie gerechnet wurde. Journal und Belegjournal folgen dem Schalter noch nicht.</p>

<h4>Konten</h4>
<p>Reiter «Konten»: Debitoren-Sammelkonto (1100), Skonto (3800), Debitorenverlust (3805), Rundungsdifferenz (3809), Mahngebühr (6950), Vorsteuer (1170, 1171), geschuldete MWST (2200), Eröffnungsbilanz (9100) und Jahresgewinn/-verlust (2979) — die Zahlen in Klammern sind die KMU-Vorschläge. Neben jedem Feld steht «ok» oder «prüfen». Ein leeres oder ungültiges Konto wird erst dort abgelehnt, wo gebucht wird (z.B. beim Verbuchen einer Rechnung), mit einem Hinweis auf diese Seite. Ein später geändertes Debitoren-Sammelkonto muss im Kontenplan zusätzlich «Nur durch Module bebuchbar» erhalten.</p>

<h4>Was nicht beim Mandanten steht</h4>
<ul>
    <li><strong>Bankverbindung, IBAN, QR-Einzahlungsschein</strong> — im Zahlungsziel (Stammdaten › Auftragswesen › Zahlungsziele). Der Kontoinhaber darf anders heissen als die Firma; leere Adressfelder des Zahlungsempfängers nimmt der QR-Schein vom Mandanten.</li>
    <li><strong>Währung</strong> — die Basiswährung ist eine Einstellung der Installation, nicht des Mandanten.</li>
    <li><strong>Mehrere Mandanten</strong> — nicht vorgesehen; eine Installation führt genau eine Firma.</li>
</ul>
