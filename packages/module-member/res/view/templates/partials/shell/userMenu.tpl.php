<?php
/**
 * Header, right cell — the appearance switch, the account panel, and the mark.
 *
 * ── The order is the argument ──
 * Everything clickable sits left, the MARK sits outermost. It is not a control
 * but the signature of the surface; at the edge it is in nobody's way who is
 * aiming for the two buttons beside it.
 *
 * ── Why the areas are NOT in here (v1.4.1) ──
 * v1.4.0 put areas and account into this one panel. Revised at the prototype:
 * whoever wants to switch areas does not look under their own initials. The
 * areas live at the four-square switcher (partials/shell/headLeft); this panel
 * carries identity, the shortcut into devices, and the way out.
 *
 * ── Why the switch renders no state ──
 * The account stores three situations — light, dark, and «never decided», and
 * in the third the SYSTEM decides, which the server cannot know. So both icons
 * and both labels sit in the markup and the stylesheet shows the pair that
 * matches the mode actually being painted. A visitor who never touched the
 * switch still reads a truthful label without a line of JavaScript having run —
 * and that is also why this is a plain button and not `aria-pressed`.
 *
 * ── Why the tenant name sits HERE and at the left edge (2026-08-14) ──
 * The header said who is signed in but never WHOSE data is on screen. With one
 * account per tenant that was implicit; with invited accounts and a demo tenant
 * that can be switched to a real source, it is not. It sits at the left edge of
 * this cell — the reading eye starts there, and the cluster on the right stays
 * what it is: controls.
 *
 * ── When it becomes a switcher (ADR-037) ──
 * With ONE granted tenant it is text, as before: something clickable would
 * promise a choice that does not exist. From two on it is a `<details>` whose
 * summary is that same label, and whose panel holds one FORM per tenant — the
 * choice is a POST (checked server-side against the granted set, then written
 * to the session), never a link with a parameter, and it needs no script:
 * `<details>` opens itself. The tenant still never comes from the request of a
 * WORKING page; only this one write does, and it is the write that is checked.
 *
 * @var array{name:string,email:string,initials:string}|null $memberUser
 * @var string $memberTheme   display only — the switch reads the DOM
 * @var string $memberTenant  readable name of the loaded tenant, '' when none
 * @var list<array{ref:string,label:string,active:bool}> $memberTenants  the
 *      granted tenants — EMPTY unless there are at least two
 * @var string $memberTenantBack  where the choice returns to (this page)
 * @var string $csrfToken
 */
$name          = trim($memberUser['name'] ?? '');
$memberTenants = $memberTenants ?? [];
?>
<div class="me-shell__head-r">
    <?php if ($memberTenants !== []): ?>
    <details class="me-tenant">
        <summary class="me-shell__tenant me-tenant__summary" title="Verwaltung wechseln">
            <span class="me-tenant__label"><?= e($memberTenant) ?></span>
            <span class="me-tenant__caret" aria-hidden="true">▾</span>
        </summary>
        <div class="me-tenant__panel" aria-label="Verwaltung wählen">
            <?php foreach ($memberTenants as $tenant): ?>
            <form method="post" action="/member/main/profile/mandant" class="me-tenant__form">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
                <input type="hidden" name="mandant" value="<?= e($tenant['ref']) ?>">
                <input type="hidden" name="back" value="<?= e($memberTenantBack ?? '') ?>">
                <button type="submit"
                        class="me-tenant__row<?= $tenant['active'] ? ' me-tenant__row--active' : '' ?>"
                        <?= $tenant['active'] ? 'aria-current="true"' : '' ?>><?= e($tenant['label']) ?></button>
            </form>
            <?php endforeach; ?>
        </div>
    </details>
    <?php elseif (trim($memberTenant ?? '') !== ''): ?>
    <span class="me-shell__tenant" title="Angezeigter Bestand"><?= e($memberTenant) ?></span>
    <?php endif; ?>

    <button type="button" class="me-theme" data-member-theme
            data-theme-url="/member/main/profile/theme">
        <span class="me-theme__icon me-theme__icon--dark" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z"/>
            </svg>
        </span>
        <span class="me-theme__icon me-theme__icon--light" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
                 stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="4.2"/>
                <path d="M12 2.6v2.2M12 19.2v2.2M4.2 12H2M22 12h-2.2M6.3 6.3 4.8 4.8M19.2 19.2l-1.5-1.5M17.7 6.3l1.5-1.5M4.8 19.2l1.5-1.5"/>
            </svg>
        </span>
        <span class="me-theme__label me-theme__label--dark">Dunkle Ansicht einschalten</span>
        <span class="me-theme__label me-theme__label--light">Helle Ansicht einschalten</span>
    </button>

    <?php if (!empty($memberUser)): ?>
    <div class="me-account__wrap" data-member-menu>
        <button type="button" class="me-account__avatar" data-member-menu-trigger
                aria-haspopup="true" aria-expanded="false" aria-controls="me-account-panel"
                title="<?= e($name !== '' ? $name : $memberUser['email']) ?>">
            <span aria-hidden="true"><?= e($memberUser['initials'] ?? '') ?></span>
            <span class="me-account__sr">Konto-Menü öffnen</span>
        </button>

        <div class="me-account__panel" id="me-account-panel" hidden data-member-menu-panel aria-label="Konto">
            <div class="me-account__identity">
                <span class="me-account__avatar me-account__avatar--static" aria-hidden="true"><?= e($memberUser['initials'] ?? '') ?></span>
                <span class="me-account__who">
                    <?php if ($name !== ''): ?>
                    <span class="me-account__name"><?= e($name) ?></span>
                    <?php endif; ?>
                    <span class="me-account__mail"><?= e($memberUser['email']) ?></span>
                </span>
            </div>

            <div class="me-account__divider"></div>

            <?php /* Profil itself is an AREA and sits in the switcher — here
                     only the shortcut into the part one comes for. */ ?>
            <a class="me-account__row" href="/member/main/profile?bereich=geraete">Geräte &amp; 2FA</a>

            <div class="me-account__divider"></div>

            <a class="me-account__row me-account__row--out" href="/member/main/logout">Abmelden</a>
        </div>
    </div>
    <?php endif; ?>

    <?= $this->partial('partials/brandMark', ['class' => 'me-brand'], 'Z77\\Shared') ?>
</div>
