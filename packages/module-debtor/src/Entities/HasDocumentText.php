<?php

namespace Z77\Module\Debtor\Entities;

/**
 * The per-language text a master-data row prints on a CUSTOMER DOCUMENT —
 * the payment terms' sentence on an invoice, the dunning level's on a
 * notice. Two entities carry it and the storage rule is the same, so it
 * lives once (Rule 8); the validation rule lives once as well, in
 * {@see \Z77\Module\Debtor\Validators\DocumentTextRule}, and the form side
 * in {@see \Z77\Module\Debtor\Ui\DocumentTextForm}.
 *
 * NOT the `label`: that is the German name the BACKEND list shows, single
 * language, exactly as `TaxCode::$label` and `AddressType::$label` are.
 */
trait HasDocumentText
{
    /**
     * `['de' => '…', 'fr' => '…']`. Built by the form per language, never
     * mapped raw from a request body — hence no `#[Clean]`.
     *
     * @var array<string, string>
     */
    private array $documentText = [];

    /** @return array<string, string> language code → text */
    public function getDocumentText(): array
    {
        return $this->documentText;
    }

    /**
     * Lower-cases the language codes, trims the texts and drops empty ones,
     * so «the key exists» always means «there is a text». Whether a code is
     * a language of this installation is the validator's question.
     *
     * @param array<string, mixed> $texts
     */
    public function setDocumentText(array $texts): void
    {
        $clean = [];
        foreach ($texts as $language => $text) {
            $language = mb_strtolower(trim((string) $language));
            $text     = trim((string) $text);
            if ($language === '' || $text === '') {
                continue;
            }
            $clean[$language] = $text;
        }
        ksort($clean);

        $this->documentText = $clean;
    }
}
