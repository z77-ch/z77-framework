<?php

namespace Z77\Module\Receipt\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

/**
 * A RECEIPT TEMPLATE (Belegvorlage, owner decisions E2 / E3 / E10 of 2026-11-12): one tile on the
 * phone («Tanken», «Büromaterial», «Spesen Essen») that knows WHAT was bought — the debit account
 * and the VAT code. HOW it was paid (the credit account) is NOT part of the template (E10): the
 * phone picks a {@see PaymentMethod} («Bezahlt mit»: Kasse, Firmenkarte, privat ausgelegt) next to
 * it. No template fits: the phone sends photo, amount and text, and the owner completes the
 * receipt in the journal.
 *
 * Installation master data, FILE-based (a handful of rows, the pattern of the order status and the
 * payment terms — E3 names them): `data/framework/receipt/receipt_templates.json`, maintained in
 * the backend (Stammdaten), written through {@see \Z77\Module\Receipt\Services\ReceiptTemplateService}.
 * Deliberately NOT seeded: the accounts depend on the installation's chart, and a seeded template
 * naming an account the chart does not have would be refused at the first posting.
 *
 * The reference rule (ADR-043 decision 19): a receipt references its template BY `code`
 * (`receipt.template_code`); a used template is DEACTIVATED, never deleted, and the code is
 * immutable once created.
 *
 *   - `taxCode` — a module-vat code, or '' = WITHOUT VAT (a template is decided in full; «not
 *     decided yet» exists only on a receipt);
 *   - `icon` — the key of the tile's symbol (shape only, `[a-z0-9-]`; the app shell maps it);
 *   - `position` — the order of the tiles (not unique; equal = by id).
 */
#[Entity('file', 'framework/receipt/receipt_templates.json')]
class ReceiptTemplate
{
    use ArrayMappable;

    /**
     * The symbol set a template's tile may show — key → German name (the board «Belegvorlagen»: a
     * fixed choice, no upload, no emoji). The backend offers exactly these; the app shell maps each
     * key to its glyph and falls back to its default symbol for '' or a key it does not know. The
     * validator keeps accepting any key of the right shape (a key written before the set grew).
     */
    public const ICONS = [
        'fuel'     => 'Tanken',
        'office'   => 'Büro',
        'meal'     => 'Essen',
        'parking'  => 'Parkieren',
        'transit'  => 'Bahn / ÖV',
        'mail'     => 'Post',
        'tools'    => 'Werkzeug',
        'receipt'  => 'Beleg',
    ];

    /** Server-controlled — no setter; the collection store assigns it. */
    private ?int $id = null;

    /** Unique key, `[a-z][a-z0-9-]{1,15}`, immutable after creation — what `receipt.template_code` holds. */
    #[Clean('ident')]
    private string $code = '';

    /** German, the tile's caption and the default text of a receipt captured with it. */
    #[Clean('text')]
    private string $label = '';

    /** The symbol key of the tile; '' = the app's default symbol. */
    #[Clean('ident')]
    private string $icon = '';

    /** Account number (digits) the receipt's amount is debited to — usually the expense. */
    #[Clean('text')]
    private string $debitAccount = '';

    /** A module-vat code, or '' = without VAT. */
    #[Clean('text')]
    private string $taxCode = '';

    #[Clean('int')]
    private int $position = 0;

    /** False = not offered as a tile any more; receipts captured with it keep the code. */
    #[Clean('bool')]
    private bool $active = true;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    /** The one normalization every code comparison relies on. */
    public static function normalizeCode(string $code): string
    {
        return mb_strtolower(trim($code));
    }

    /** The tile order: position, then id (an unsaved row last). */
    public static function compare(self $a, self $b): int
    {
        return [$a->position, $a->id ?? PHP_INT_MAX] <=> [$b->position, $b->id ?? PHP_INT_MAX];
    }

    public function getId(): ?int { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getLabel(): string { return $this->label; }
    public function getIcon(): string { return $this->icon; }
    public function getDebitAccount(): string { return $this->debitAccount; }
    public function getTaxCode(): string { return $this->taxCode; }
    public function getPosition(): int { return $this->position; }
    public function isActive(): bool { return $this->active; }

    /** Whether the template posts without VAT (its tax code is empty). */
    public function isWithoutVat(): bool { return $this->taxCode === ''; }

    public function setCode(string $code): void { $this->code = self::normalizeCode($code); }
    public function setLabel(string $label): void { $this->label = trim($label); }
    public function setIcon(string $icon): void { $this->icon = mb_strtolower(trim($icon)); }
    public function setDebitAccount(string $number): void { $this->debitAccount = trim($number); }
    public function setTaxCode(string $code): void { $this->taxCode = mb_strtoupper(trim($code)); }
    public function setPosition(int $position): void { $this->position = $position; }
    public function setActive(bool $active): void { $this->active = $active; }
}
