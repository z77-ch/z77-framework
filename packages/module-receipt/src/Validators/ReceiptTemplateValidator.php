<?php

namespace Z77\Module\Receipt\Validators;

use Z77\Module\Receipt\Entities\ReceiptTemplate;
use Z77\Module\Receipt\Repositories\ReceiptTemplateRepository;
use Z77\Module\Receipt\Services\ReceiptFields;
use Z77\Persistence\File\Repository\FileRepository;
use Z77\Persistence\Resolver\UnifiedEntityManager;
use Z77\Persistence\Validation\EntityValidator;

/**
 * Validates a {@see ReceiptTemplate} for the backend and for any other writer.
 *
 *   - code and label ({@see ValidatesMasterDataRow});
 *   - icon: '' or `[a-z0-9-]`, at most 32 (a key, not markup);
 *   - debit REQUIRED (a template is decided in full, E2), an account that takes a new posting
 *     ({@see ReceiptFields::account()}); the credit side is the payment method's (E10) — except a
 *     «nur Steuer» template: no Soll (derived from the code at posting);
 *   - tax code: '' (without VAT) or an active code that splits a gross amount — reverse charge
 *     included (stage S6: a receipt of the template posts it as one entry) —, with «nur Steuer» an
 *     input code (`ReceiptFields::vatChoice()`, the one parser).
 *
 * Without the entity manager only the shape is checked — the format-only mode a screen uses for an
 * empty form. Whether a template together with a payment method can post (e.g. both sides P&L with
 * VAT) is judged per receipt by the posting and its preview, not here.
 */
class ReceiptTemplateValidator extends EntityValidator
{
    use ValidatesMasterDataRow;

    public const ICON_MAX_LENGTH = 32;

    public function __construct(
        ReceiptTemplate $template,
        private readonly ?ReceiptTemplateRepository $repo = null,
        private readonly ?UnifiedEntityManager $em = null,
    ) {
        parent::__construct($template);
    }

    protected function masterDataRows(): ?FileRepository
    {
        return $this->repo;
    }

    public function validateIcon(string $icon): void
    {
        if ($icon !== '' && (mb_strlen($icon) > self::ICON_MAX_LENGTH || !preg_match('/^[a-z0-9-]+$/', $icon))) {
            $this->addFieldError('icon', 'Symbol: Kleinbuchstaben, Ziffern und Bindestrich, höchstens ' . self::ICON_MAX_LENGTH . ' Zeichen.');
        }
    }

    public function validateDebitAccount(string $number): void
    {
        if ($this->template()->isTaxOnly()) {
            return;   // «nur Steuer»: no Soll — the posting derives the input account from the code (the service clears a typed one)
        }
        $this->validate('debit_account', 'Soll', $number)->notEmpty();
        if ($this->hasFieldError('debit_account') || $this->em === null) {
            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->account($number, 'debit_account', $errors);
        foreach ($errors as $field => $message) {
            $this->addFieldError($field, $message);
        }
    }

    /**
     * The VAT decision as the receipt window's select would carry it — judged by the ONE parser
     * (`ReceiptFields::vatChoice()` on `vatToken()`): '' = «ohne MWST»; a code; with «nur Steuer»
     * an input code only. Liability is not applied to a template (the posting judges it per receipt).
     */
    public function validateTaxCode(string $code): void
    {
        $template = $this->template();
        if ($template->isTaxOnly() && trim($code) === '') {
            $this->addFieldError('tax_code', '«Nur Steuer» braucht einen Vorsteuer-Code (VM, VI).');

            return;
        }
        if ($this->em === null || trim($code) === '') {
            return;
        }
        $errors = [];
        (new ReceiptFields($this->em))->vatChoice(ReceiptFields::vatToken($code, false, $template->isTaxOnly()), 'tax_code', $errors, true, reverseCharge: true);
        foreach ($errors as $field => $message) {
            $this->addFieldError($field, $message);
        }
    }

    private function template(): ReceiptTemplate
    {
        /** @var ReceiptTemplate $template */
        $template = $this->entity;

        return $template;
    }
}
