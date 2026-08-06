<?php

namespace Z77\Module\Member\Ui\Form;

use Z77\Shared\Forms\FormDefinition;

/**
 * The login request form (B8): one field. The «angemeldet bleiben» option
 * joins with the device-key stage. Anti-oracle: whatever is entered, the
 * answer page is the same — the mail differs (login link, confirmation
 * link, or the no-account hint).
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
        ];
    }
}
