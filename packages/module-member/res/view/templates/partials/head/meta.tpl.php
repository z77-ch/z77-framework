<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php /* Registration pages are functional, not content — never index them. */ ?>
<meta name="robots" content="noindex, nofollow">
<?php /* core.js reads the token from here once at init and puts it on every
         POST as `X-CSRF-Token` — the header `AccessGuard` validates centrally
         for Fetch requests. Without this tag every fetch in the member area is
         refused with «CSRF token invalid» (FETCH-CSRF-001). */ ?>
<meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
