<?php

namespace Z77\Module\Member\Services;

// Moved to the kernel (login.md BACKEND-2FA-001). This name stays for project code that still uses
// it; new code uses \Z77\Shared\Auth\TotpGuard. The one thing it no longer has is create(): the
// kernel names no module's path, use MemberTotp::guard() (no caller of create() exists in any
// project under the workspace, checked 2026-11-01).
class_alias(\Z77\Shared\Auth\TotpGuard::class, TotpGuard::class);
