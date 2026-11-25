<?php
/**
 * Help for one VAT return (ADR-048 — a page, its trigger in the top bar; F1 jumps to a section):
 * the three tabs «Formular», «Herkunft», «Abstimmung», the concepts behind the figures (200, 235,
 * the tax per rate, 400 / 405, 500, a negative field, the open points, the PDF), what z77 does NOT
 * settle (review §6.4 / §7.4, in plain words) and what is not available yet; since stage S2 (owner
 * decisions of 2026-11-25) «MWST buchen …», the lock, a posted return, the voucher and «Aufheben …»
 * as the emergency brake; since stage S3 (board S3-2) the block «Korrekturen früherer Perioden», the
 * exact values and what a correction entry does to «MWST buchen» and «Aufheben». Also what the
 * KI-Assistent knows (ADR-055).
 */
?>
<h3>MWST-Abrechnung</h3>
<p>Das Formular der ESTV für eine Periode, gerechnet aus den Buchungen der Periode mit MWST-Code — nichts wird von Hand eingetippt. Drei Reiter: <strong>Formular</strong> — die Zahlen, die du im ePortal eintippst; <strong>Herkunft</strong> — woher jede Zahl kommt; <strong>Abstimmung</strong> — ob die Konten der Buchhaltung dazu passen. Oben steht die Periode, die Methode, die Ausgabe des Formulars und <strong>«Deklaration netto (ohne MWST)»</strong>: im ePortal wählst du bei der Frage nach der Deklaration «netto» — alle Umsätze hier sind ohne MWST.</p>
<p>Die Abrechnung ist eine Hilfe zur Selbstveranlagung. Eingereicht wird im ePortal der ESTV; verantwortlich bleibt die steuerpflichtige Person. z77 gibt keine Steuerberatung.</p>

<section data-help-field="findings">
<h3>Offene Punkte</h3>
<p>Was vor dem Buchen der Abrechnung noch zu tun ist — nur sichtbar, wenn es etwas gibt. <strong>sperrt</strong>: zuerst erledigen — der Link führt hin. Solange ein Punkt sperrt, ist die Aktionszelle leer; der erste Punkt sagt, warum: die Periode ist noch nicht zu Ende, eine frühere Periode ist noch nicht gebucht (die Abrechnungen folgen einander ohne Lücke), kein Geschäftsjahr enthält das Periodenende, das Geschäftsjahr ist abgeschlossen, ein Konto der Abrechnungsbuchung fehlt im Mandanten, ein Journal ist nicht verbucht, eine Buchung ohne MWST-Code steht auf einem MWST-Konto, der Bankabgleich ist nicht fertig, eine Korrekturbuchung korrigiert eine Periode, die nicht (mehr) abgerechnet ist. <strong>Warnung</strong>: prüfen; die Abrechnung geht trotzdem, beim Buchen bestätigst du die Warnungen mit einem Haken. Typische Warnungen: ein Ertragskonto mit Buchungen ohne MWST-Code (Verzugszins und Inkassokosten sind ohne Code richtig, eine Mahngebühr nicht), eine Haben-Buchung ohne Code auf einem Anlagekonto (der Verkauf eines Fahrzeugs oder einer Maschine ist steuerbarer Umsatz), eine negative Ziffer, ausgenommene Umsätze ohne Vorsteuerkorrektur, ein Rest in der Umsatzabstimmung, eine Rundungsdifferenz über einem halben Rappen pro Beleg, Bezugsteuer, die mit vollem Vorsteuerabzug gerechnet ist (wer nicht voll abzugsberechtigt ist, korrigiert den Abzug von Hand mit Ziffer 415), eine verbuchte Kreditorenrechnung aus dem Ausland (fremde Währung oder Adresse im Ausland) mit einer Zeile «ohne MWST» — prüfen, ob Bezugsteuer geschuldet ist; ein MWST-Code einer Korrekturbuchung, der damals zu einer anderen Ziffer gehörte als heute (die Korrektur zählt nach heutiger Zuordnung).</p>
</section>

<section data-help-field="post">
<h3>MWST buchen …</h3>
<p><strong>Zuerst im ePortal einreichen, dann hier buchen — gebucht heisst eingereicht.</strong> «MWST buchen …» steht in der Aktionszelle, sobald die Periode zu Ende ist, die frühere gebucht ist und kein offener Punkt sperrt. Das Fenster zeigt die Periode, die Zahllast (Ziffer 500, bei einem Guthaben Ziffer 510), die Rundung und die Abrechnungsbuchung Zeile für Zeile: die Steuer der Buchungen mit MWST-Code wird von den MWST-Konten (z.B. 2200, 1170, 1171) auf das MWST-Abrechnungskonto (z.B. 2201) übertragen, die Rundungsdifferenz auf ihr Konto. Gibt es Warnungen, bestätigst du sie mit dem Haken «Warnungen gelesen — trotzdem buchen». «MWST jetzt buchen» bucht die Abrechnung, legt den Beleg ab und schreibt das Protokoll.</p>
<p>Hat sich seit dem Öffnen des Fensters etwas geändert — eine Buchung kam dazu, die Warnungen sind andere, etwas sperrt —, wird nichts gebucht: das Fenster bleibt offen und sagt, was los ist. Dann die Zahlen neu ansehen und mit dem ePortal vergleichen.</p>
<p>Gibt es <strong>Korrekturen früherer Perioden</strong>, bucht die Abrechnung deren Steuer als eigene Zeile von 2200 (oder der Vorsteuer) auf 2201 («Korrektur 3. Quartal 2026»), speichert den Block und druckt ihn auf den Beleg. Zuerst im ePortal einreichen und die Korrekturen melden, dann buchen.</p>
<p><strong>Nullabrechnung</strong>: hat die Periode keine Buchung mit MWST-Code, ist die Zahllast 0.00 und es gibt keine Abrechnungsbuchung («Buchung: keine»). Gebucht wird sie trotzdem — mit dem gleichen Knopf; sie sperrt die Periode und hat einen Beleg.</p>
</section>
<section data-help-field="lock">
<h3>Die Sperre</h3>
<p>Nach dem Buchen sind in der Periode <strong>Buchungen mit MWST-Code</strong> gesperrt: keine neue, keine Änderung, kein Löschen. Im Journal tragen sie ein graues Schloss. Buchungen ohne MWST-Code bleiben möglich. Was später dazukommt — eine vergessene Rechnung, eine Gutschrift —, wird in der laufenden Periode gebucht.</p>
</section>
<section data-help-field="posted">
<h3>Eine gebuchte Abrechnung</h3>
<p>Oben steht, wer sie wann gebucht hat und die Nummer der Abrechnungsbuchung. Der Reiter <strong>Formular</strong> zeigt die Zahlen, <strong>wie sie gebucht wurden</strong> — sie werden nicht neu gerechnet. <strong>Herkunft</strong> und <strong>Abstimmung</strong> rechnen dagegen aus dem Journal: Buchungen mit MWST-Code ändern sich nach dem Buchen nicht mehr, aber eine spätere Buchung <strong>ohne</strong> MWST-Code auf einem MWST- oder Ertragskonto der Periode zeigt sich in der Abstimmung als Rest, der im Formular nicht steht. Weicht ein Reiter so vom Gebuchten ab, steht oben eine Zeile: <strong>«Seit dem Buchen geändert — der Beleg zeigt den Stand vom …»</strong>. Der Beleg hält fest, was gebucht wurde.</p>
</section>
<section data-help-field="voucher">
<h3>Beleg</h3>
<p>Das Werkzeug <strong>Beleg</strong> öffnet das PDF, das beim Buchen abgelegt wurde: Formular mit der genauen Steuer pro Satz, Herkunft und Abstimmung der MWST-Konten, die Abrechnungsbuchung, die bestätigten Warnungen, wer und wann. Es ist ein Buchhaltungsbeleg, wird nie verändert — auch nicht beim Aufheben — und gehört mit der Buchhaltung aufbewahrt. Im Journal öffnet das Beleg-Symbol der Abrechnungsbuchung dasselbe PDF.</p>
</section>
<section data-help-field="lift">
<h3>Aufheben … — die Notbremse</h3>
<p>Für eine Abrechnung, die <strong>irrtümlich</strong> gebucht wurde. Nur Admins, nur die <strong>neueste</strong> gebuchte Abrechnung, immer mit einem Grund (höchstens 500 Zeichen). Die Abrechnungsbuchung wird mit einem Storno am gleichen Datum rückgängig gemacht («Aufgehoben: … — Grund»); beide bleiben im Journal sichtbar. Die Periode ist danach wieder offen und wird mit «MWST buchen …» neu gebucht — als nächste Fassung mit eigenem Beleg; die aufgehobene Fassung bleibt mit ihrem Beleg lesbar. Eine frühere Abrechnung hebt man nur auf, indem man von der neuesten her eine nach der anderen aufhebt. Das Geschäftsjahr der Abrechnungsbuchung muss offen sein.</p>
<p>Nicht möglich, solange eine Korrekturbuchung die Periode dieser Abrechnung korrigiert: zuerst diese Buchung ändern oder löschen — das Fenster nennt sie. Wird eine Abrechnung mit Korrekturen früherer Perioden aufgehoben, nimmt der Storno deren Zeilen mit; die Korrekturbuchungen kommen in den Block der neuen Fassung.</p>
<p>Ist die Abrechnung im ePortal schon eingereicht und war darin ein Fehler, wird das der ESTV gemeldet — z77 öffnet eine eingereichte Abrechnung dafür nicht wieder.</p>
</section>
<section data-help-field="corrections">
<h3>Korrekturen früherer Perioden — separat im ePortal melden</h3>
<p>Die Korrekturbuchungen, die in dieser Periode datiert sind (Journal › Sammelbuchung, Feld «Korrigiert Periode»). Sie stehen <strong>nicht</strong> im Formular dieser Periode, sondern im Block unter dem Formular: je korrigierte Periode und Ziffer <strong>ursprünglich</strong> (wie damals gebucht), <strong>Korrektur</strong> und <strong>neu</strong> (ursprünglich + alle Korrekturen bisher). Wurde die Periode schon früher korrigiert, steht dazwischen <strong>frühere Korrekturen</strong>. Gezeigt werden die Ziffern mit einer Korrektur, dazu 399 und 500.</p>
<p>Gemeldet wird der Block separat: mit einer Korrekturabrechnung der Periode im ePortal (Spalte «neu») oder in der Jahresabstimmung (die Summe des Steuerjahres steht in der MWST-Jahresübersicht). In «Herkunft» und «Abstimmung» steht jede Korrekturbuchung als eigener Posten «Korrektur zu …», nicht als Ertrag ohne MWST-Code. Eine gebuchte Abrechnung zeigt den Block, wie er gebucht wurde.</p>
</section>
<section data-help-field="exact">
<h3>Exakte Steuer</h3>
<p>Klein unter jedem Steuerbetrag des Blocks steht der exakte Wert. «neu» wird aus den exakten Werten einmal gerundet und kann darum einen Rappen von ursprünglich + Korrektur abweichen (667.83 + 40.46 ergibt 708.29, «neu» ist 708.30). Im ePortal tippst du die Umsätze; weicht die Steuer der ESTV einen Rappen ab, die Differenz bei der Zahlung von Hand zwischen 2201 und 6945 buchen.</p>
</section>

<h3>Formular</h3>
<p>Die Ziffern in der Reihenfolge des ESTV-Formulars, unterteilt in I Umsatz, II Steuerberechnung, III Vorsteuer und den zu bezahlenden Betrag. Gezeigt werden nur Ziffern mit einem Betrag; die Totale (289, 299, 399, 479) und der zu bezahlende Betrag stehen immer da.</p>
<section data-help-field="200">
<h3>Ziffer 200 — vereinbarte Entgelte</h3>
<p>Alle vereinbarten Entgelte der Periode ohne MWST, <strong>vor</strong> Abzug der Entgeltsminderungen — auch befreite Leistungen (Export, Ziffer 220), Leistungen im Ausland (221) und von der Steuer ausgenommene Leistungen (230). Eine stornierte Rechnung ist weder Umsatz noch Minderung: Rechnung und Storno heben sich in Ziffer 200 auf.</p>
</section>
<section data-help-field="235">
<h3>Ziffer 235 — Entgeltsminderungen</h3>
<p>Gutschriften, Skonti und Verluste auf steuerbaren Leistungen. Was dazu gehört, entscheidet die <strong>Herkunft</strong> der Buchung (eine Gutschrift, ein Skonto, ein Verlust aus den Zahlungseingängen), nicht das Vorzeichen. Eine Preisreduktion als negative Zeile einer gewöhnlichen Rechnung mindert direkt Ziffer 200.</p>
</section>
<section data-help-field="tax">
<h3>Steuer pro Satz (302 / 303, 312 / 313, 342 / 343)</h3>
<p>Der Umsatz zum Satz mal den Satz — so, wie die ESTV rechnet. Der Bildschirm zeigt die Steuer auf den Rappen; das PDF zeigt sie zusätzlich genau, mit allen Stellen. Die Belege haben ihre Steuer je Beleg auf den Rappen gerundet gebucht; darum kann die gebuchte Steuer um ein paar Rappen von der abgerechneten abweichen.</p>
</section>
<section data-help-field="difference">
<h3>gebucht und Differenz</h3>
<p>Nur bei Ziffer 399 (Total geschuldete Steuer) und beim zu bezahlenden Betrag: <strong>gebucht</strong> ist, was die Belege an Steuer gebucht haben, <strong>Differenz</strong> = gebucht minus abgerechnet. Ein paar Rappen sind normal. Ist die Differenz grösser als ein halber Rappen pro Beleg, steht eine Warnung unter «Offene Punkte» — dann die Steuerbeträge der Belege prüfen.</p>
</section>
<section data-help-field="input">
<h3>Vorsteuer — Ziffern 400 und 405</h3>
<p>Die Vorsteuer, wie sie gebucht ist — nicht Umsatz mal Satz. 400: auf Material- und Dienstleistungsaufwand (MWST-Code der Kategorie «Vorsteuer Material / Dienstleistungen»), 405: auf Investitionen und übrigem Betriebsaufwand. Eine Quittung, auf der nur «inkl. MWST» steht, gibt keine Vorsteuer; ausländische MWST auf einer Lieferantenrechnung ist nie Vorsteuer.</p>
</section>
<section data-help-field="500">
<h3>Ziffer 500 — zu bezahlender Betrag</h3>
<p>Geschuldete Steuer (399) minus Vorsteuer (479), <strong>einmal</strong> auf den Rappen gerundet. Ist die Vorsteuer grösser, steht statt 500 die Ziffer 510 «Guthaben» — in der Liste mit Minus.</p>
</section>
<section data-help-field="negative">
<h3>Negative Ziffer</h3>
<p>Eine Ziffer kann negativ werden, z.B. nach einer grossen Gutschrift für eine frühere Periode. Sie wird gezeigt («negativ») und als Warnung gemeldet; die Abrechnung geht trotzdem — auch das ePortal nimmt negative Beträge an.</p>
</section>
<section data-help-field="pdf">
<h3>PDF</h3>
<p>Bei einer <strong>offenen</strong> Periode öffnet das Werkzeug <strong>PDF</strong> die Abrechnung zum Ablegen: Mandant und MWST-Nummer, Periode, Methode, Ausgabe des Formulars, «Deklaration netto (ohne MWST)», die Ziffern mit Umsatz und Steuer, die genaue Steuer pro Satz, gebucht und Differenz, der zu bezahlende Betrag und die offenen Punkte. Es ist <strong>keine Einreichung</strong>; eingereicht wird im ePortal. Das PDF wird bei jedem Öffnen neu gerechnet und nicht abgelegt — abgelegt wird erst der Beleg beim Buchen.</p>
</section>

<section data-help-field="origin">
<h3>Herkunft</h3>
<p>Woher jede Zahl des Formulars kommt: Ziffer › Herkunft › Konto. Jede Buchung trägt ihre Quelle: <strong>Rechnungen</strong>, <strong>Mahngebühren</strong> und <strong>Gutschriften</strong> aus der Faktura, <strong>Skonto und Verluste</strong> aus den Zahlungseingängen, <strong>Belege</strong> aus dem Belegjournal, <strong>Kreditoren</strong> aus dem Kreditorenjournal, <strong>Bank</strong> aus den Bankbewegungen, <strong>Manuelle Buchungen</strong> aus dem Journal. Beim Öffnen ist alles zugeklappt; der Pfeil vor einer Zeile klappt die nächste Ebene auf. Das Symbol vor einem Konto zeigt die einzelnen Buchungen — ihr Total ist die Zahl dieser Zeile.</p>
<p><strong>Buchungen</strong> zählt die Buchungszeilen mit MWST-Code; eine Rechnung mit zwei Sätzen zählt in beiden Ziffern. <strong>Umsatz</strong> ist bei 400 / 405 leer (das Formular kennt dort nur die Steuer), <strong>Steuer gebucht</strong> bei 200 und den Abzügen. Eine Gutschrift steht bei der Satz-Ziffer mit Minus und in 235 positiv — wie im Formular.</p>
</section>

<section data-help-field="reconciliation">
<h3>Abstimmung</h3>
<p>Prüft, ob die Konten der Buchhaltung zur Abrechnung passen. Verglichen werden die <strong>Bewegungen der Periode</strong>, nicht die Salden — ohne die Eröffnungsbuchungen und ohne die Buchungen der MWST-Abrechnung selbst. Die Kontonummern führen zum Kontoblatt der Periode.</p>
</section>
<section data-help-field="revenue">
<h3>Umsatz</h3>
<p>Die Ertragskonten laut Buchhaltung (der Pfeil zeigt sie einzeln) gegen <strong>Ziffer 200 abzüglich Ziffer 235</strong> laut Formular. Gutschriften, Skonti und Verluste stehen auf den Ertragskonten mit Minus, darum wird 235 abgezogen. Der <strong>Unterschied</strong> ist normal, solange er erklärt ist.</p>
</section>
<section data-help-field="explained">
<h3>davon erklärt</h3>
<p>Beträge, die zu Recht nicht in der Abrechnung stehen oder anders hineinkommen: Buchungen <strong>ohne MWST-Code</strong> pro Ertragskonto (Rundungen auf Rechnungen, Verzugszinsen, Zinsen), <strong>Umsatz auf einem anderen Kontotyp</strong> (z.B. der Verkauf einer Anlage mit MWST-Code direkt auf das Anlagekonto), und die <strong>Korrekturbuchungen</strong> der Periode — je Buchung ein Posten «Korrektur zu …» mit allen ihren Ertragszeilen, mit und ohne Code; die Nummer öffnet die Buchung.</p>
</section>
<section data-help-field="rest">
<h3>Nicht erklärt</h3>
<p>Was übrig bleibt. <strong>0.00</strong> heisst: die Abrechnung passt zu den Konten. Bleibt ein Rest, ist er rot: öffne die Kontoblätter der Ertragskonten und suche eine Buchung mit MWST-Code, deren Umsatz nicht ihrem Betrag entspricht, oder einen Vorsteuer-Code auf einem Ertragskonto. Ein Rest beim Umsatz ist eine Warnung.</p>
</section>
<section data-help-field="vat-accounts">
<h3>Vorsteuer und geschuldete MWST</h3>
<p>Jedes MWST-Konto gegen seine Ziffern: die Vorsteuerkonten (z.B. 1170 ↔ 400, 1171 ↔ 405) und das Konto der geschuldeten MWST (z.B. 2200 ↔ 302 / 303, 312 / 313, 342 / 343). <strong>Buchhaltung</strong> ist die Bewegung des Kontos, <strong>Formular</strong> die Steuer, die die Buchungen mit MWST-Code dorthin gebucht haben. Auf den MWST-Konten darf nur stehen, was eine Buchung mit MWST-Code dorthin gebracht hat — ein Rest hier <strong>sperrt</strong> das Buchen der Abrechnung (siehe «Offene Punkte»). Unter dem Konto stehen dazu die <strong>«Buchungen ohne passenden Code»</strong>: jede Buchung der Periode, deren Bewegung auf dem Konto nicht zur Steuer ihrer eigenen Zeilen mit MWST-Code passt, z.B. eine von Hand getippte Steuerzeile — die Nummer öffnet die Buchung; ist sie korrigiert, steht sie nicht mehr da. Welche Konten es sind, steht im Mandanten, Reiter «Konten».</p>
</section>

<h3>Was z77 nicht abrechnet</h3>
<p>Unterstützt ist die effektive Methode mit vereinbartem Entgelt, pro Quartal, Monat oder Jahr. Für diese Fälle ist die Abrechnung von z77 <strong>nicht</strong> geeignet — die Abrechnung wird dort von Hand oder mit der Treuhand gemacht:</p>
<ul>
    <li>Saldosteuersatz- oder Pauschalsteuersatzmethode, vereinnahmtes Entgelt, einzeln bewilligte Abrechnungsperioden (z77 verweigert die Abrechnung).</li>
    <li>Einzelfirma oder Personengesellschaft mit Privatanteilen oder Naturalbezügen (Eigenverbrauch).</li>
    <li>Von der Steuer ausgenommene Umsätze, die eine Vorsteuerkorrektur verlangen, und Subventionen (Zinserträge bis CHF 250 000 im Jahr verlangen keine Korrektur).</li>
    <li>Das Verlagerungsverfahren bei der Einfuhr, Bezugsteuer mit nur teilweisem Vorsteuerabzug (z77 rechnet sie mit vollem Abzug und warnt) — und wer nicht steuerpflichtig ist, aber im Jahr für mehr als CHF 10 000 Dienstleistungen aus dem Ausland bezieht.</li>
    <li>Meldeverfahren (die Übertragung eines Geschäfts oder von Vermögen — kann Pflicht sein), Gruppenbesteuerung, fiktive Vorsteuer, Margenbesteuerung, Plattformen und Reisebüros.</li>
    <li>Beginn oder Ende der Steuerpflicht mit Waren oder Anlagen an Lager, rückwirkende Anmeldung mit Rechnungen, die schon ohne MWST gestellt sind, Vorsteuer auf Gründungskosten vor der Anmeldung.</li>
    <li>Rechnungen in fremder Währung, Anzahlungen oder Bargeld ohne Beleg, der Verkauf von Gutscheinen.</li>
    <li>Eine Rechnung über einen Leistungszeitraum, der über eine Änderung der Steuersätze geht.</li>
    <li>Der Verkauf einer Liegenschaft mit Landwert, Leistungen an eng verbundene Personen unter dem Marktwert, Emissionsrechte und Zertifikate.</li>
</ul>
<p>Für die Software gibt es keine Zulassung oder Zertifizierung durch die ESTV. Die Belege und die Buchhaltung sind nach den Regeln der Geschäftsbücherverordnung aufzubewahren.</p>

<h3>Noch nicht verfügbar</h3>
<ul>
    <li>Die Spalte <strong>«Ins ePortal»</strong> — welche Zahl in welche Ziffer gehört und welche das ePortal selbst rechnet.</li>
</ul>
