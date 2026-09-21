<?php
namespace Z77\Module\Backend\Ui\Controllers\Contact;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Contact\Ui\ContactControllerTrait;

/**
 * Backend mount of the contact fragment (plan §4a — dms Drive / member
 * accounts / tax-code pattern, ADR-018): all logic and templates live in
 * `module-contact` ({@see ContactControllerTrait}); this host only mounts it
 * under the backend route + auth + shell (module default role: ADMIN). The
 * layout is pinned to `module-contact` via
 * `Ui/Config/Contact/contactControllerConfig.inc.php`.
 *
 * Reachable only in projects that install z77/module-contact (like the
 * Drive without module-dms — the route then has no classes to load).
 *
 * URL: /backend/contact/contact/list.
 */
class ContactController extends BackendAbstractController
{
    use ContactControllerTrait;
}
