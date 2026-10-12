<?php

namespace Z77\Module\Contact\Roles;

use Z77\Module\Contact\Entities\Contact;

/**
 * A ROLE another module gives a contact — «Debitor» from module-debtor, later
 * «Kreditor», «Lieferant» … (owner decision 2026-10-12: a contact is declared a
 * debtor in the CONTACT editor, wherever it was created).
 *
 * module-contact knows no module (plan §2): it renders what the registered roles
 * answer ({@see ContactRoles}, config key `contactRoles` of the CONTRIBUTING
 * module) and calls {@see switchOn()} / {@see switchOff()} — every rule, every
 * write and every German sentence belongs to the role's module. The dependency
 * runs from the contributing module to this one, never back.
 *
 * Instantiated without arguments (the `OpenWorkCheckInterface` model) — a role
 * reaches its services through DI. It runs in the caller's request; a switch is
 * ONE unit of work of the role's module.
 */
interface ContactRoleInterface
{
    /** The role's German name as the switch shows it: «Debitor». */
    public function label(): string;

    /** What the contact editor shows for an EXISTING contact: on/off, one line of state, whether it may be switched now. */
    public function state(Contact $contact): ContactRoleState;

    /**
     * What «Kontakt anlegen» shows before the contact exists: whether the role can be set
     * in the same save (`switchable`), the line saying what it will give (`summary`), or
     * why not (`reason`). `on` is ignored — the user's choice decides.
     */
    public function stateForNew(): ContactRoleState;

    /**
     * Give the contact this role — the role's module writes. The server decides: a refusal
     * is a {@see ContactRoleRefusedException} with a German sentence naming what to set up.
     *
     * @throws ContactRoleRefusedException
     */
    public function switchOn(Contact $contact): void;

    /**
     * Take the role away again — only while nothing of the role's module depends on it;
     * otherwise a {@see ContactRoleRefusedException} says why and what is possible instead.
     *
     * @throws ContactRoleRefusedException
     */
    public function switchOff(Contact $contact): void;
}
