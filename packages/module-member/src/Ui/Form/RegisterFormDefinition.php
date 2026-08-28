<?php

namespace Z77\Module\Member\Ui\Form;

use Z77\Shared\Forms\FormDefinition;

/**
 * The registration form (B7 spec, decision 7 — minimal): e-mail,
 * Firma/Verwaltung, first and last name. Nothing else at this hurdle;
 * address, phone and billing come with activation or the profile.
 *
 * The whitelist is the override point per project (spec): a project that
 * needs another field overrides THIS class in its override tree — the
 * declaration drives validation, partial, blur check and nothing else has
 * to change.
 *
 * formKey(): the register submit never sends the generic form mail (the
 * flow's callback creates the account and mails the confirmation link), so
 * the key only names the guard scope.
 */
class RegisterFormDefinition extends FormDefinition
{
    public function formKey(): string
    {
        return 'memberRegister';
    }

    /**
     * The form log records the typed address (geo-guard opt-in): a flood of
     * registration attempts is only readable evidence when the addresses can
     * be compared — one address hammered vs. a fresh one per try.
     */
    public function identityField(): ?string
    {
        return 'email';
    }

    public function fields(): array
    {
        return [
            'email' => [
                'label'        => 'E-Mail',
                'type'         => self::TYPE_EMAIL,
                'autocomplete' => 'email',
                'rules'        => ['required' => true, 'email' => true, 'max' => 254],
            ],
            'company' => [
                'label'        => 'Firma / Verwaltung',
                'autocomplete' => 'organization',
                'rules'        => ['required' => true, 'min' => 2, 'max' => 120],
            ],
            'first_name' => [
                'label'        => 'Vorname',
                'autocomplete' => 'given-name',
                'rules'        => ['required' => true, 'min' => 2, 'max' => 80],
            ],
            'last_name' => [
                'label'        => 'Nachname',
                'autocomplete' => 'family-name',
                'rules'        => ['required' => true, 'min' => 2, 'max' => 80],
            ],
        ];
    }
}
