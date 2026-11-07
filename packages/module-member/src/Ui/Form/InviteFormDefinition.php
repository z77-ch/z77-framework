<?php

namespace Z77\Module\Member\Ui\Form;

use Z77\Module\Member\Services\MemberLanguage;
use Z77\Shared\Forms\FormDefinition;

/**
 * The redemption form of an invitation (B7 v1.1.0): first and last name — and,
 * where the module renders accounts in their own language (ADR-056), the
 * language of the account about to be born.
 *
 * ⚠️ There is deliberately **no e-mail field and no company field**. The
 * address comes from the TOKEN — a pre-filled input would only be a display
 * anyway (any client can change it), and the page shows it as text instead, so
 * there is nothing to tamper with in the first place. The company is missing
 * because the project reference already exists: the invited person joins it,
 * he does not name it.
 *
 * The language is SHOWN and CONFIRMED, not silently taken from the token
 * (owner decision 2026-11-07, z77-rudaz.ch): the inviter chose it, the person
 * who reads every page in it confirms it. Only where there is a choice —
 * {@see MemberLanguage::offered()} lists two or more languages; otherwise the
 * form is the name form it always was.
 *
 * Own form key, so the guard and the session rate limit of the open
 * registration and of the redemption never share a bucket.
 */
class InviteFormDefinition extends FormDefinition
{
    /**
     * @param ?string $preselect the language checked on the first render — the token's,
     *        else the page's; anything not offered falls back to the default language
     */
    public function __construct(private ?string $preselect = null)
    {
    }

    public function formKey(): string
    {
        return 'memberInvite';
    }

    public function fields(): array
    {
        $fields = [
            'first_name' => [
                'label'        => t('member.form.firstName'),
                'autocomplete' => 'given-name',
                'rules'        => ['required' => true, 'min' => 2, 'max' => 80],
            ],
            'last_name' => [
                'label'        => t('member.form.lastName'),
                'autocomplete' => 'family-name',
                'rules'        => ['required' => true, 'min' => 2, 'max' => 80],
            ],
        ];

        $offered = MemberLanguage::offered();
        if ($offered === []) {
            return $fields;
        }

        // Each language in its own name: «Français» is what a French reader looks for.
        $options = [];
        foreach ($offered as $code) {
            $options[$code] = t('lang.' . $code, [], $code);
        }
        $preselect = MemberLanguage::resolve($this->preselect);

        $fields['language'] = [
            'label'   => t('member.invite.language'),
            'type'    => self::TYPE_RADIO,
            'options' => $options,
            'default' => isset($options[$preselect]) ? $preselect : (string)array_key_first($options),
            // The options map is the whitelist; required, because a radio group
            // always posts one of them — an empty value is a hand-made request.
            'rules'   => ['required' => true],
        ];

        return $fields;
    }
}
