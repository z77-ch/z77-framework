<?php

namespace Z77\Module\Member\Ui\Form;

use Z77\Shared\Forms\FormDefinition;

/**
 * The resend form (B7 spec): one field. Whoever enters an address gets the
 * same neutral answer whether it has an account or not — the flow decides
 * silently which mail (if any) goes out.
 */
class ResendFormDefinition extends FormDefinition
{
    public function formKey(): string
    {
        return 'memberResend';
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
