<?php
/**
 * Profile (B8) as an AREA of the shell (B10 v1.4.1): three sections, one shown.
 *
 * The card is gone — the rail chooses, this is the detail. A confirmed account
 * still sees «wartet auf Freischaltung» (B7 decision 4: sign-in works, access is
 * this page only); an active one is a full member.
 *
 * «Zugänge» is NOT here any more (2026-09-12): it was the fourth section
 * until the header could show a reference other than the home (ADR-037) —
 * the list belonged to the reference, the page to the person. It is an area
 * now (`Main/ZugaengeController`).
 *
 * @var string $pageTitle
 * @var string $section  'konto' | 'zweifa' | 'geraete'
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var array<int,array<string,mixed>> $devices device keys, newest use first
 * @var list<array{ref:string,label:string,usable:bool,state:string,note:string}> $memberships
 *      what the project's membershipHook reports — closed ones are named,
 *      `state` (active|paused|waiting) words the person's status
 * @var string $dialogId  id of the account dialog — the action cell opens it
 * @var string $csrfToken
 */
$day  = static fn(string $iso): string => $iso === '' ? '' : date('d.m.Y', (int)strtotime($iso));
$title = [
    'konto'    => 'Konto',
    'zweifa'   => 'Zwei-Faktor-Schutz',
    'geraete'  => 'Angemeldete Geräte',
][$section];
?>
<div class="me-detail">
    <button type="button" class="me-back" data-z77-split-close>‹ Liste</button>

    <div class="me-detail__head">
        <h1 class="me-detail__title"><?= e($title) ?></h1>
    </div>

    <?php if ($section === 'konto'): ?>
    <p class="me-detail__sub">Ihre Angaben aus der Registrierung</p>

    <?php if ($account->isConfirmed()): ?>
    <div class="me-band me-band--info">
        <span class="me-band__dot" aria-hidden="true"></span>
        <span class="me-band__text">
            Ihre Registrierung ist bestätigt und wartet auf die Freischaltung.
            Sie erhalten eine E-Mail, sobald Ihr Zugang aktiv ist.
        </span>
    </div>
    <?php endif; ?>

    <?php /* The memberships that are NOT open — paused by the owner, or a join
             still waiting for the operator. The project wrote the sentence
             (`note`: what and whom to ask); the module only prints it. An
             active account whose every access is closed lands HERE with no
             areas, so this band is the one thing telling it why. */ ?>
    <?php $closed = array_values(array_filter($memberships ?? [], static fn(array $m): bool => empty($m['usable']))); ?>
    <?php foreach ($closed as $m): ?>
    <div class="me-band me-band--info">
        <span class="me-band__dot" aria-hidden="true"></span>
        <span class="me-band__text">
            Ihr Zugang zu «<?= e((string)$m['label']) ?>» ist zurzeit nicht offen<?= trim((string)($m['note'] ?? '')) !== '' ? ': ' . e(trim((string)$m['note'])) . '.' : '.' ?>
        </span>
    </div>
    <?php endforeach; ?>

    <?= $this->partial('Main/ProfileController/_kontoFields', ['account' => $account, 'memberships' => $memberships ?? []], 'Z77\\Module\\Member') ?>

    <?php /* Why the address is not a field: it IS the access — a typo locks the
             account out, so changing it needs the confirmation path of B7, not
             a text box. The company IS editable since 2026-08-12; it renames
             the tenant with it, so the two cannot drift apart. */ ?>
    <p class="me-quiet">
        Die E-Mail-Adresse ändern wir gemeinsam: sie ist Ihr Zugang, und sie zu
        verlegen braucht eine Bestätigung über die neue Adresse. Schreiben Sie
        uns.
    </p>

    <?php /* The dialog for «Bearbeiten» in the action cell. Server-rendered and
             closed — the two fields are already on this page, so a fragment
             request would only fetch what is here.
             ⚠️ «Speichern» sits INSIDE: a modal dialog makes the rest of the
             document inert, so a button in the action cell would be dead while
             the dialog is open.
             The actions stand in ONE row directly under the title (ADR-049
             revision 2026-10-10, MEMBER-FORM-ACTIONS-001): `.z77-form-actions`
             with the member buttons, «Speichern» first in document order so
             Enter presses it. Posts by fetch: the answer replaces the values
             above (`[data-konto-fields]`) and closes the dialog; without script
             the same form posts as a page (method/action kept). */ ?>
    <dialog class="me-dialog" id="<?= e($dialogId) ?>" aria-labelledby="<?= e($dialogId) ?>-title">
        <form method="post" action="/member/main/profile/konto" class="me-dialog__form"
              data-fetch-post="/member/main/profile/konto">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <h2 class="me-dialog__title" id="<?= e($dialogId) ?>-title">Konto bearbeiten</h2>

            <div class="z77-form-actions">
                <button type="submit" class="me-btn">Speichern</button>
                <button type="button" class="me-btn me-btn--quiet" data-dialog-close>Abbrechen</button>
            </div>

            <div class="fe-form__row">
                <label for="konto-first">Vorname</label>
                <input id="konto-first" type="text" name="first_name" maxlength="120"
                       value="<?= e($account->getFirstName() ?? '') ?>" autocomplete="given-name">
            </div>
            <div class="fe-form__row">
                <label for="konto-last">Nachname</label>
                <input id="konto-last" type="text" name="last_name" maxlength="120"
                       value="<?= e($account->getLastName() ?? '') ?>" autocomplete="family-name">
            </div>
            <div class="fe-form__row">
                <label for="konto-company">Firma / Verwaltung</label>
                <input id="konto-company" type="text" name="company" maxlength="120"
                       value="<?= e($account->getCompany() ?? '') ?>" autocomplete="organization">
                <?php /* Which one it renames: the HOME. Since ADR-037 the header
                         may name another reference, so «Ihrem Mandanten» alone
                         pointed the reader at the wrong one (Peter, 2026-09-12). */ ?>
                <small class="me-quiet">
                    Wo Sie arbeiten. Der Name Ihrer Verwaltung wird davon nicht berührt —
                    ihn ändert, wer sie besitzt, im Bereich der Verwaltung.
                </small>
            </div>
        </form>
    </dialog>

    <?php /* «Konto löschen» (2026-09-14): the person's own handgrip, Art. 32
             revDSG. Two confirmations inside the dialog — the address typed
             again and a checkbox — because there is no password to ask for.
             What it means at the project's tenants is the PROJECT's sentence
             (`deletionNotices`), printed here and in the dialog. */ ?>
    <?php $deleteDialogId = $deleteDialogId ?? 'me-konto-loeschen'; $deletionNotices = $deletionNotices ?? []; ?>
    <h2 class="me-detail__sub" style="margin-top:2.5rem">Konto löschen</h2>
    <p class="me-quiet">
        Sie können Ihr Konto selbst löschen — sofort und endgültig. Anmeldung,
        angemeldete Geräte und Zwei-Faktor-Schutz enden damit.
        <?php foreach ($deletionNotices as $notice): ?>
        <?= e($notice) ?>
        <?php endforeach; ?>
    </p>
    <?php /* `.me-actions`, not `.me-btn`: the button class is full-width by
             design (the action cell), an inline action sits left and takes
             its own width (Peter, 2026-09-14). */ ?>
    <div class="me-actions">
        <button type="button" data-dialog-open="<?= e($deleteDialogId) ?>">Konto löschen …</button>
    </div>

    <dialog class="me-dialog" id="<?= e($deleteDialogId) ?>" aria-labelledby="<?= e($deleteDialogId) ?>-title">
        <form method="post" action="/member/main/profile/loeschen" class="me-dialog__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <h2 class="me-dialog__title" id="<?= e($deleteDialogId) ?>-title">Konto endgültig löschen</h2>

            <?php /* Two fields (address + checkbox), so this is a form, not a bare
                     confirm: the row stands at the top (ADR-049 revision
                     2026-10-10 — «no field» is the only test for the bottom). */ ?>
            <div class="z77-form-actions">
                <button type="submit" class="me-btn">Konto endgültig löschen</button>
                <button type="button" class="me-btn me-btn--quiet" data-dialog-close>Abbrechen</button>
            </div>

            <p>
                Gelöscht werden Ihr Konto, Ihre angemeldeten Geräte und Ihr
                Zwei-Faktor-Schutz. Das lässt sich nicht rückgängig machen.
            </p>
            <?php foreach ($deletionNotices as $notice): ?>
            <p><?= e($notice) ?></p>
            <?php endforeach; ?>

            <div class="fe-form__row">
                <?php /* Not `name="email"`: that name is the address field the
                         profile deliberately does not have — this one only
                         proves the person knows whose account she is deleting. */ ?>
                <label for="loeschen-email">Zur Bestätigung Ihre E-Mail-Adresse</label>
                <input id="loeschen-email" type="text" name="loeschen_bestaetigung" required autocomplete="off"
                       inputmode="email" placeholder="<?= e($account->getEmail()) ?>">
            </div>
            <div class="fe-form__row">
                <label>
                    <input type="checkbox" name="bestaetigt" value="1" required>
                    Ich weiss, dass das nicht rückgängig zu machen ist.
                </label>
            </div>
        </form>
    </dialog>

    <?php elseif ($section === 'zweifa'): ?>
    <p class="me-detail__sub"><?= $account->hasTotp() ? 'Aktiv' : 'Nicht aktiv' ?></p>

    <?php if ($account->hasTotp()): ?>
    <p>
        Aktiv seit <?= e(substr((string)$account->getTotpActivatedAt(), 0, 10)) ?> —
        die Anmeldung fragt zusätzlich nach dem App-Code.
    </p>

    <?php /* Removal asks for a live code on purpose: whoever holds a stolen
             session must not be able to strip the second factor with one
             click. That is why this is a form and not an action in the cell.
             Posts by fetch (MEMBER-FORM-ACTIONS-001): a wrong code answers in
             place — the page stays, the typed code too; a removal changes the
             section, the rail and the action cell, so it answers with the page
             again. Without script the same form posts as a page. */ ?>
    <form method="post" action="/member/main/profile/totp-remove" class="fe-form" novalidate
          data-fetch-post="/member/main/profile/totp-remove">
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
        <div class="fe-form__row">
            <label for="totp-remove-code">Zum Entfernen: Code aus der App</label>
            <input id="totp-remove-code" type="text" name="code" inputmode="numeric"
                   autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required>
        </div>
        <button class="fe-form__submit" type="submit">2FA entfernen</button>
    </form>
    <?php else: ?>
    <div class="me-band me-band--info">
        <span class="me-band__dot" aria-hidden="true"></span>
        <span class="me-band__text">
            Ohne zweiten Faktor hängt Ihr Zugang allein an Ihrem Postfach.
        </span>
    </div>
    <p>
        Mit einer Authenticator-App fragt die Anmeldung zusätzlich zum Link einen
        6-stelligen Code ab. Sie brauchen dafür Ihr Telefon — und es einmal
        einzurichten dauert eine Minute.
    </p>
    <?php endif; ?>

    <?php else: ?>
    <p class="me-detail__sub" data-device-count>
        <?= $devices === [] ? 'Kein Gerät bleibt angemeldet' : e(count($devices) . ' Gerät' . (count($devices) === 1 ? '' : 'e') . ' bleiben angemeldet') ?>
    </p>

    <?php if ($devices === []): ?>
    <p>
        Setzen Sie beim Anmelden das Häkchen «Auf diesem Gerät angemeldet
        bleiben», wenn Sie nicht jedes Mal einen neuen Link anfordern möchten.
    </p>
    <?php else: ?>
    <div class="me-units">
        <?php foreach ($devices as $device): ?>
        <?php /* One row per device, addressed as `device:<id>` (the row vocabulary
                 of the ADR-047 addendum): «Abmelden» posts by fetch and the
                 answer removes exactly this row (MEMBER-FORM-ACTIONS-001). The
                 form keeps method/action — without script it posts as a page. */ ?>
        <div class="me-unit" data-entity="device:<?= e((string)$device['id']) ?>">
            <span class="me-unit__name">
                <?= e((string)$device['label']) ?>
                <?php if ($device['current']): ?><span class="me-quiet">— dieses Gerät</span><?php endif; ?>
            </span>
            <span class="me-unit__status">seit <?= e($day((string)$device['created_at'])) ?></span>
            <form method="post" action="/member/main/profile/device-remove" class="me-actions" style="margin:0"
                  data-fetch-post="/member/main/profile/device-remove">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="device" value="<?= e((string)$device['id']) ?>">
                <button type="submit">Abmelden</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="me-hint">
        Gezählt wird pro Browser, nicht pro Computer: Wer seine Cookies löscht,
        ein privates Fenster nutzt oder einen zweiten Browser verwendet, erscheint
        hier als weiteres Gerät — auch mit gleicher Bezeichnung. Ein abgemeldetes
        Gerät verlangt beim nächsten Besuch wieder einen Anmelde-Link; höchstens
        fünf bleiben angemeldet, das am längsten ungenutzte weicht. Sonst laufen
        die Einträge nach 90 Tagen von selbst ab.
    </p>
    <?php endif; ?>
    <?php endif; ?>
</div>
