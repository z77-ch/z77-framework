<?php
namespace Z77\Module\Backend\Ui\Controllers\Contact;

use Z77\Module\Backend\Ui\Controllers\BackendAbstractController,
    Z77\Module\Contact\Ui\AddressTypeControllerTrait;

/**
 * Backend mount of the address-type fragment (plan §4a, ADR-018 pattern):
 * logic and templates live in `module-contact`
 * ({@see AddressTypeControllerTrait}); this host mounts them under the
 * backend route + auth + shell (ADMIN). Layout pinned via
 * `Ui/Config/Contact/addressTypeControllerConfig.inc.php`.
 *
 * URL: /backend/contact/address-type/list.
 */
class AddressTypeController extends BackendAbstractController
{
    use AddressTypeControllerTrait;
}
