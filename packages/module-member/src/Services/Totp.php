<?php

namespace Z77\Module\Member\Services;

// Moved to the kernel (login.md BACKEND-2FA-001). This name stays for project code that still uses
// it; new code uses \Z77\Shared\Auth\Totp.
class_alias(\Z77\Shared\Auth\Totp::class, Totp::class);
