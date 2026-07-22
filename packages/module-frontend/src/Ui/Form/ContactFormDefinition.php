<?php

namespace Z77\Module\Frontend\Ui\Form;

use Z77\Shared\Forms\FormDefinition;

/**
 * The framework's reference contact form — the standard a project copies into
 * its override and adapts (see docs/03-development/public-form-bauplan.md).
 *
 * This IS the whole form: no DTO, no validator class, no per-field controller
 * code. The declaration drives validation, the form partial, the blur check and
 * the e-mail body alike. Labels are translation keys (a literal works too — an
 * unknown key comes back verbatim).
 *
 * Recipient, subject and body template belong to the form KEY and live in
 * emailConfig (backend-editable per docs/topics/mail.md), not here.
 */
final class ContactFormDefinition extends FormDefinition
{
    public function formKey(): string
    {
        return 'contactForm';
    }

    public function fields(): array
    {
        return [
            'name' => [
                'label'        => 'form.field.name',
                'autocomplete' => 'name',
                'rules'        => ['required' => true, 'min' => 2, 'max' => 80],
            ],
            'email' => [
                'label'        => 'form.field.email',
                'type'         => self::TYPE_EMAIL,
                'autocomplete' => 'email',
                'rules'        => ['required' => true, 'email' => true],
            ],
            'phone' => [
                'label'        => 'form.field.phone',
                'type'         => self::TYPE_TEL,
                'autocomplete' => 'tel',
                'rules'        => ['max' => 30],   // optional — no 'required'
            ],
            'message' => [
                'label' => 'form.field.message',
                'type'  => self::TYPE_TEXTAREA,
                'rules' => ['required' => true, 'min' => 10, 'max' => 4000],
            ],
            'privacy' => [
                'label' => 'form.field.privacy',
                'type'  => self::TYPE_CHECKBOX,
                'rules' => ['accepted' => true],
            ],
        ];
    }
}
