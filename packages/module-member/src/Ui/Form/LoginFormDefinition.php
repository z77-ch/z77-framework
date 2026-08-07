<?php

namespace Z77\Module\Member\Ui\Form;

use Z77\Shared\Forms\FormDefinition;

/**
 * The login request form (B8): the address, and the «angemeldet bleiben»
 * option — the wish travels with the link, so the device that redeems gets
 * the key (spec: the mail may be opened elsewhere). Anti-oracle: whatever is
 * entered, the answer page is the same — the mail differs (login link,
 * confirmation link, or the no-account hint).
 */
class LoginFormDefinition extends FormDefinition
{
    public function formKey(): string
    {
        return 'memberLogin';
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
            'remember' => [
                'label' => 'Auf diesem Gerät angemeldet bleiben',
                'type'  => self::TYPE_CHECKBOX,
                'rules' => [],
            ],
        ];
    }
}
