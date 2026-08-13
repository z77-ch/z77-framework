<?php

namespace Z77\Module\Member\Ui\Form;

use Z77\Shared\Forms\FormDefinition;

/**
 * The redemption form of an invitation (B7 v1.1.0): first and last name, and
 * nothing else.
 *
 * ⚠️ There is deliberately **no e-mail field and no company field**. The
 * address comes from the TOKEN — a pre-filled input would only be a display
 * anyway (any client can change it), and the page shows it as text instead, so
 * there is nothing to tamper with in the first place. The company is missing
 * because the project reference already exists: the invited person joins it,
 * he does not name it.
 *
 * Own form key, so the guard and the session rate limit of the open
 * registration and of the redemption never share a bucket.
 */
class InviteFormDefinition extends FormDefinition
{
    public function formKey(): string
    {
        return 'memberInvite';
    }

    public function fields(): array
    {
        return [
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
