# pdf

2026-10-13

## entry

1. `packages/kernel/shared/src/Pdf/PdfDocument.php` — the facade every PDF is drawn through: pages, text, shapes, images, the vector QR code, the partials, the page hooks, the output
2. `packages/kernel/shared/res/view/templates/pdf/table.tpl.php` — the shared flowing table: the model of a PDF partial (draws through `$pdf`, echoes nothing, leaves the cursor below)
3. `packages/module-debtor/res/view/templates/pdf/invoice.tpl.php` — the first document layout: letterhead, address window, facts, lines, totals, the QR-bill payment part

## file map

SOURCE=/packages/kernel/shared/src/Pdf/PdfDocument.php
SOURCE=/packages/kernel/shared/src/Pdf/PdfWriter.php
SOURCE=/packages/kernel/shared/src/Qr/QrCode.php
SOURCE=/packages/kernel/vendored/fpdf/src/fpdf.php
SOURCE=/packages/kernel/vendored/fpdf/LICENSE
SOURCE=/packages/kernel/vendored/README.md
SOURCE=/packages/kernel/composer.json
SOURCE=/packages/kernel/core/src/Http/Response/BytesResponse.php
SOURCE=/packages/kernel/core/src/Controller/AbstractBaseController.php
SOURCE=/packages/kernel/core/src/Services/TemplateRenderer.php
SOURCE=/packages/kernel/shared/res/view/templates/pdf/table.tpl.php
SOURCE=/packages/kernel/shared/res/view/templates/pdf/addressWindow.tpl.php
SOURCE=/packages/kernel/shared/res/view/templates/pdf/report.tpl.php
SOURCE=/packages/module-mandator/res/view/templates/pdf/letterhead.tpl.php
SOURCE=/packages/module-debtor/src/Pdf/InvoicePdf.php
SOURCE=/packages/module-debtor/res/view/templates/pdf/invoice.tpl.php
SOURCE=/packages/module-debtor/res/view/templates/pdf/qrBill.tpl.php
SOURCE=/packages/module-debtor/src/Ui/InvoiceControllerTrait.php
SOURCE=/tests/pdf.php
SOURCE=/tests/module-debtor.php

## mental model

The framework writes PDFs through ONE facade, `Z77\Shared\Pdf\PdfDocument`, over the vendored FPDF 1.9 (`kernel/vendored/fpdf`, MIT — a source snapshot like bacon-qr-code, no Composer dependency; owner decision 2026-10-06 after the options in `debtor.md`). The facade is the writer; the LAYOUTS are templates: a PDF partial is an ordinary `.tpl.php` under a module's `res/view/templates/pdf/`, resolved by the FileFinder like an HTML partial — so a project overrides a layout under `override/` (CE-first), and a module ships its document as a layout plus the shared blocks it is built from. Units are millimetres, A4 portrait by default, Helvetica (the core fonts, cp1252 — UTF-8 at the API, converted once in the facade). A document is served inline through the new `BytesResponse` (`$this->bytes()`), rendered on request, never stored by the framework.

- **A PDF partial draws, it does not print.** It receives `$pdf` (the facade) plus its context, calls the drawing API, and echoes NOTHING — stray output is refused with a `LogicException` naming the partial (`PdfDocument::partial()`). A partial that draws a flow block (a table, an address, a paragraph) leaves the cursor BELOW what it drew (`$pdf->setY()`), so the next block continues from `$pdf->y()`.
- **The shared blocks** (kernel, namespace `Z77\Shared`): `pdf/report` (a whole LAYOUT for list-shaped documents, FIN-PDF-001 2026-10-10 — a running head per page: issuer left, title and range right, a rule; a foot with «Gedruckt …» and «Seite x von n»; an optional red `notice`; then `blocks`, each an optional bold title over a `pdf/table`; a block never starts in the last 25 mm; margins left 12 / right 10 mm (`marginLeft` / `marginRight`, owner 2026-10-10); the same `columns` in every block keep the amounts in one line — `tests/pdf.php` G), `pdf/table` (fixed column widths in mm, ONE column wraps — `wrap`, default the first, `null` = none; every one-line cell that is too long ends in «…» through the facade's `fit()`, a header row repeated after a page break, a row never splits, `bold` / `muted` / `indent` / `rule` / `lead` per row — `lead` (2026-10-18) prints the FIRST line of the wrapping cell bold and the rest in the row's style: a document line's title above its description; the row height counts both fonts — `tests/pdf.php` F4) and `pdf/addressWindow` (the recipient in the C5 left window: 20 mm from the left, 50 mm from the top). **module-mandator** ships `pdf/letterhead` (the company block, the contact line, the logo top-right from `logo_path` relative to `ABS_BASE_PATH` — the one place that resolves it). **module-debtor** ships `pdf/invoice` (the layout) and `pdf/qrBill` (the Swiss QR-bill payment part, see below).
- **Encoding** (`PdfDocument::enc()`, the one place): the Unicode minus of `AmountFormat` becomes a hyphen; the en / em dash and the typographic quotes keep their cp1252 bytes; what cp1252 lacks is transliterated (`Ș` → `S`), and a character without any counterpart becomes `?` — never an empty string, never a fatal (`tests/pdf.php` B).
- **Page breaks:** `autoPageBreak($bottomMargin)` is where a FLOWED block (`paragraph()`, the table partial) breaks; a PLACED line (`text()`, `lines()`) never breaks the page — it switches the writer's automatic break off for the call (the payment part at y 192–297 would otherwise jump to a new page). `ensureSpace($mm)` breaks AHEAD of a block that must not split. `breakAt()` is the usable bottom edge (page height when the break is off).
- **Rotated text — a stamp across the page** (owner 2026-10-13, decision (b) in `debtor.md`): `rotatedText($cx, $cy, $text, $degrees)` draws one line CENTRED on ($cx, $cy), turned counter-clockwise about it (45 = bottom-left to top-right), in the current font and text colour; it never moves the cursor or breaks the page. The writer (`PdfWriter::rotatedText()`) wraps FPDF's `Text()` in a saved graphics state with a rotation matrix — `q c s -s c x y cm 1 0 0 1 -x -y cm … Q` — so nothing drawn after it is turned. There is no transparency: the writer draws in order, so a watermark lies UNDER the content when it is the first thing on the page — drawn in the `onPageStart()` hook, which runs before any content, on every page including continuation pages. The invoice preview draws «VORSCHAU» this way (110 pt bold, grey 228, page centre, 45°); «KOPIE» / «ENTWURF» stamps would use the same call. Verified: `tests/pdf.php` J1–J5.
- **Hooks and the bag:** `onPageStart()` runs after every `addPage()` (the running header), `onPageEnd()` when a page is left and at `output()` (the footer); both receive the facade. `set()` / `get()` is the layout's key-value bag its hooks read (the invoice sets `paymentPart` so the footer stays off the page that carries the QR-bill). `{nb}` in a text becomes the page count at output.
- **Sections — several documents in one file** (2026-10-10, the booking print of a selection of invoices — one file, `debtor.md` «The booking print»): `startSection()` before each layout finishes the open page with the footer hook it was DRAWN with (and flags it, so the writer's `AddPage()` does not draw the next document's footer on it — the next layout sets its hooks before its `addPage()`), clears both hooks and the bag, and restarts the numbering: `sectionPageNo()` counts within the document, `sectionPageCount()` is a placeholder (`{nb:K}`) the writer replaces with that document's page count at output (`PdfWriter::_putpages()`). A single-document file is section 0 and needs no call — `sectionPageNo()` = `pageNo()`, `sectionPageCount()` = the total. The invoice layout numbers its pages with these two; `Pdf\InvoicePdf::combined()` appends one section per document. Verified: `tests/pdf.php` I1–I3.
- **The QR code is vector:** `qrCode($payload, $x, $y, $size, $ecc, $swissCross)` draws the modules of `QrCode::matrix()` (the facade over bacon, no raster) as rectangles — runs of dark modules become one rectangle — and overlays the 7 mm Swiss cross on request (black field, white cross in the flag's 32-unit proportions, a white rim). Level M, as the QR-bill mandates.
- **Data a layout offers but does not draw — the line code** (owner decision 2026-10-22): a document line's short code (`InvoiceLine::getCode()`, `SalesOrderLine::getCode()`) is stored and handed to every line row of `pdf/invoice`, `pdf/order` and `pdf/deliveryNote` as the key `code`; `pdf/table` ignores keys it does not know and `DocumentColumns` keeps them. The framework prints NO code — «ob der Code auf der Rechnung erscheint, entscheidet der Gestalter im Override». An override of the layout uses `$line->getCode()`; an override of the shared table (`override/…/pdf/table.tpl.php`) reads the row, e.g. `$text = ($row['code'] ?? null) !== null ? $row['code'] . '  ' . $cells[0] : $cells[0];` before it draws the first cell. Verified: `tests/module-debtor.php` LC5, `tests/module-order.php` P6.
- **The invoice** (`module-debtor`, `Pdf/InvoicePdf`): `of($document, $em)` gathers the context — the document with its lines (the SNAPSHOT, nothing resolved again), its `QrBill`, the mandator through `Creditor::mandator()`, the customer number from the profile — and `render()` draws `pdf/invoice`. The payment part is drawn only when the bill is printable (`QrBill::isPrintable()`); otherwise the document prints without it and, when it was issued without a target, says «Zahlbar mit Vermerk «Rechnung n»». A credit note never has one. The action `pdf` (`InvoiceControllerTrait::pdfAction()`) answers `application/pdf` inline, named `rechnung-12.pdf` (`Naming::toSlug()`); the detail view links it («PDF», a new tab). Deterministic: the same document yields the same bytes up to the creation timestamp FPDF writes into the metadata (`tests/module-debtor.php` P3D4).
- **The QR-bill payment part** (`pdf/qrBill`, SIX style guide v2.x): the 105 mm zone at the foot — the receipt (62 mm) and the payment part (148 mm), the perforation as dashed lines with the hint «Vor der Einzahlung abzutrennen» above (plain paper), titles 11 pt bold, receipt headings 6 pt bold / values 8 pt, payment-part headings 8 pt bold / values 10 pt, the QR code 46 × 46 mm at 5 mm / 17 mm inside the part, the information section at x 118, the amount grouped with a space («1 234.50»), the payer's box with corner marks when the debtor block was left out (DEBTOR-QR-DEBTOR-001). Not yet validated against the SIX validation portal (`## pending`).
- **`BytesResponse`** (`$this->bytes($content, $filename, $mimeType, $inline = true)`): bytes that exist in memory only, with `Content-Type`, `Content-Disposition` and `Content-Length`. The counterpart of `FileResponse` (a file on disk, ranges, ETag); a generated document has no path and no reason for a temp file. ADR-003's helper list grew by it.
- **Why FPDF, why vendored** (owner 2026-10-06): ~1 900 lines, no dependency, the 14 core fonts need no embedding, the classic for QR-bill slips; a permissive licence (MIT on Packagist, «use, copy, modify, distribute, sublicense, and/or sell» in `LICENSE`). Vendored like bacon: byte-identical to the upstream tag, an update is a deliberate re-copy (`vendored/README.md`). The autoload is a `classmap` entry of the kernel's `composer.json` — after changing it a path package needs `composer update z77/kernel` (a `dump-autoload` alone reads the stale `installed.json`).

## rules

- When a module needs a PDF → MUST draw it through `Z77\Shared\Pdf\PdfDocument` and a layout under its `res/view/templates/pdf/` (a partial drawn with `$pdf->partial('pdf/…', $context, $ns)`); MUST NOT instantiate `\FPDF` / `PdfWriter` or require `vendored/fpdf` directly, and MUST NOT add a second PDF library.
- When writing a PDF partial → MUST draw through `$pdf` only and echo nothing (a stray byte is refused), MUST take everything it prints from its context, and — for a flow block — MUST leave the cursor below what it drew (`$pdf->setY()`); MUST NOT read the request, the session or a repository inside the template.
- When a block exists in two documents (a table, an address block, a letterhead) → MUST become a shared partial (kernel `pdf/…` for the generic ones, the owning module's `pdf/…` for a domain block, Rule 8); MUST NOT be copied into a second layout.
- When placing text in a fixed zone (a footer, the payment part) → MUST use `text()` / `lines()` (never breaks the page); MUST NOT use `paragraph()` there while the automatic break is on, and MUST NOT let a running footer print into the 105 mm QR-bill zone (the invoice sets `paymentPart` in the bag for that).
- When several documents go into ONE file → MUST call `startSection()` before each layout and number pages with `sectionPageNo()` / `sectionPageCount()` in the layout; MUST NOT use `pageNo()` / `{nb}` for a per-document «Seite x von n» and MUST NOT concatenate the bytes of separate PDFs.
- When a block must not split across pages (totals, a signature block, the payment part) → MUST call `ensureSpace($height)` before drawing it; MUST NOT rely on the automatic break.
- When text enters the PDF → MUST hand it over as UTF-8 and let `enc()` convert; MUST NOT pre-convert to cp1252 in a layout (a double conversion garbles umlauts).
- When a QR code goes on a page → MUST use `$pdf->qrCode()` (vector, from the kernel facade's matrix); MUST NOT embed a PNG from `QrCode::png()` — a raster QR-bill scans worse and prints soft.
- When an action serves a generated document → MUST return `$this->bytes($content, $filename, $mimeType)` (inline) or `inline: false` for a download, the file name kebab-case lower (`Naming::toSlug()`); MUST NOT write a temp file to serve it through `file()` and MUST NOT `echo` the bytes.
- When a project wants the line code on its documents → MUST print it in an override (`pdf/invoice` / `pdf/order` with `$line->getCode()`, or `pdf/table` with `$row['code']`); MUST NOT add it to a framework layout or a switch for it.
- When the invoice layout is wrong for a PROJECT → MUST override `pdf/invoice.tpl.php` (or one shared block) under `override/`; MUST NOT edit the framework's layout or add a project switch to it (Rule 1).
- When FPDF is updated → MUST re-copy the released files byte-identically into `vendored/fpdf/src` with the `LICENSE`, bump the row in `vendored/README.md`, and run `composer update z77/kernel`; MUST NOT patch `fpdf.php` (the facade is the place for behaviour).

## known issues

- **PDF-FONT-001** — don't assume Unicode: the core fonts are cp1252. A name in Greek, Cyrillic or CJK prints as `?`; an embedded TrueType font (FPDF's `AddFont` with a generated metric) is the way out when a project needs it — not built, no caller.
- **PDF-PRINT-001** — don't assume the payment part was verified on paper or against the SIX validation portal: the geometry follows the style guide as known here (62 / 148 mm, 46 mm QR at 5 / 17 mm, the information section at x 118, the point sizes); the first live bill must be checked with the portal and a test scan (`## pending`). The scissors symbol is replaced by the text hint, which the guidelines allow for plain paper.
- **PDF-LANG-001** — don't assume the invoice PDF follows the document's language: the labels («Rechnung», «Kundennummer», «Zahlbar bis», the payment-part headings) are German. The QR-bill headings may be printed in de / fr / it / en per the guidelines; a per-language label set arrives with the document texts (debtor.md DEBTOR-QR-LANG-001).
- **PDF-LOGO-001** — don't assume a missing logo is reported: `pdf/letterhead` prints the logo only when `logo_path` resolves to a file under `ABS_BASE_PATH`; otherwise it prints nothing and says nothing. FPDF reads PNG / JPEG / GIF; an SVG logo is not supported by the writer.
- **PDF-TABLE-001** — don't assume a cell other than the first wraps: the table partial wraps the first column only, the others are one line and are cut by the writer when too wide (a number column never is). A second wrapping column is a change to the partial, not to a layout.
- Don't assume `composer dump-autoload` picks up a classmap change in a PATH package: the root's `installed.json` caches the package's autoload section — `composer update z77/kernel` refreshes it (found 2026-10-06 when FPDF did not autoload).

## pending

- **Validate the first printed QR-bill** against the SIX validation portal (`validation.iso-payments.ch`) and with a bank's scanner app; adjust the geometry of `pdf/qrBill` if the portal objects. Owner's installation (z77.ch) is the place — `debtor.md` «install module-debtor in z77.ch».
- **UI field for the line code — not built, owner decision 2026-10-22: storage only** (debtor.md «The line code»); the PDFs offer `code`, the framework prints it nowhere.
- **Labels per language** for the invoice PDF and the payment part (de / fr / it / en) — with the document texts per language (debtor.md).
- **Storage** of the invoice PDF in the Drive — agreed and deferred (debtor.md «Storage of the PDF»); the facade is unaffected, a DMS consumer would take `output()` bytes.
- **Further documents** the owner named (2026-10-06): an article data sheet (module-article) — expected to reuse `pdf/table` and `pdf/letterhead` and to add nothing to the facade. The first consumers outside debtor came with P7 slim step 8 (2026-10-14): module-order's `pdf/order` (Offerte / Auftragsbestätigung, amounts from debtor's preview) and `pdf/deliveryNote` (no prices) — letterhead, address window and table reused, nothing added to the facade (`order.md`).

## see also

- [`debtor.md`](debtor.md) — the invoice the PDF prints: the snapshot, `QrBill`, the reference layout, the decisions of 2026-10-06
- [`mandator.md`](mandator.md) — the letterhead record and `logo_path`; `pdf/letterhead` is the PDF consumer
- [`view-layer.md`](view-layer.md) — `TemplateRenderer::partial()` and the FileFinder override tier the PDF partials ride on
- [`money.md`](money.md) — `AmountFormat` (the Unicode minus the facade maps) and `Money`
