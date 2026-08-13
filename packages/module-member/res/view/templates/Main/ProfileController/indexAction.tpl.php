<?php
/**
 * Profile (B8) as an AREA of the shell (B10 v1.4.1): three sections, one shown.
 *
 * The card is gone — the rail chooses, this is the detail. A confirmed account
 * still sees «wartet auf Freischaltung» (B7 decision 4: sign-in works, access is
 * this page only); an active one is a full member.
 *
 * The fourth section «Zugänge» (B10 v1.6.0) exists only for the master — and
 * only as a section that is THERE, never as one that is greyed out: an invited
 * account gets no rail entry and its routes answer 404.
 *
 * @var string $pageTitle
 * @var string $section  'konto' | 'zweifa' | 'geraete' | 'zugaenge'
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var array<int,array<string,mixed>> $devices device keys, newest use first
 * @var string $dialogId  id of the account dialog — the action cell opens it
 * @var string $inviteDialog id of the invitation dialog
 * @var ?array{accounts: list<array<string,mixed>>, invites: list<array<string,mixed>>} $zugaenge
 * @var string $csrfToken
 */
$day  = static fn(string $iso): string => $iso === '' ? '' : date('d.m.Y', (int)strtotime($iso));
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
$title = [
    'konto'    => 'Konto',
    'zweifa'   => 'Zwei-Faktor-Schutz',
    'geraete'  => 'Angemeldete Geräte',
    'zugaenge' => 'Zugänge',
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

    <dl class="me-field">
        <?php if ($name !== ''): ?>
        <dt>Name</dt>
        <dd><?= e($name) ?></dd>
        <?php endif; ?>
        <dt>E-Mail</dt>
        <dd><?= e($account->getEmail()) ?> <span class="me-quiet">— Ihr Zugang</span></dd>
        <?php if ($account->getCompany() !== null): ?>
        <dt>Firma / Verwaltung</dt>
        <dd><?= e($account->getCompany()) ?></dd>
        <?php endif; ?>
        <dt>Status</dt>
        <dd><?= e($account->isActive() ? 'aktiv' : 'wartet auf Freischaltung') ?></dd>
    </dl>

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
             the dialog is open. */ ?>
    <dialog class="me-dialog" id="<?= e($dialogId) ?>" aria-labelledby="<?= e($dialogId) ?>-title">
        <form method="post" action="/member/main/profile/konto" class="me-dialog__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <h2 class="me-dialog__title" id="<?= e($dialogId) ?>-title">Konto bearbeiten</h2>

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
                <small class="me-quiet">Dieser Name steht auch an Ihrem Mandanten — er wird mit geändert.</small>
            </div>

            <div class="me-dialog__actions">
                <button type="button" class="me-btn me-btn--quiet" data-dialog-close>Abbrechen</button>
                <button type="submit" class="me-btn">Speichern</button>
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
             click. That is why this is a form and not an action in the cell. */ ?>
    <form method="post" action="/member/main/profile/totp-remove" class="fe-form" novalidate>
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

    <?php elseif ($section === 'zugaenge'): ?>
    <?php $rows = $zugaenge['accounts'] ?? []; $offen = $zugaenge['invites'] ?? []; ?>
    <p class="me-detail__sub">Wer für Ihre Verwaltung arbeiten darf</p>

    <div class="me-units">
        <?php foreach ($rows as $row): ?>
        <div class="me-unit">
            <span class="me-unit__name">
                <?= e($row['email']) ?>
                <?php if ($row['name'] !== ''): ?><span class="me-quiet">— <?= e($row['name']) ?></span><?php endif; ?>
            </span>
            <span class="me-unit__status">
                <?php if ($row['master']): ?>Sie<?php
                      elseif ($row['waiting']): ?>wartet auf Freischaltung<?php
                      elseif ($row['suspended']): ?>pausiert<?php
                      else: ?>aktiv<?php endif; ?>
            </span>

            <?php /* The master stands in the list WITHOUT both handgrips (spec):
                     a tenant whose only account is gone would be reachable
                     through us alone. */ ?>
            <?php if ($row['master']): ?>
            <span class="me-quiet">—</span>
            <?php else: ?>
            <span class="me-unit__vis">
                <?php /* ⚠️ The visible part of a switch is the track; a bare
                         checkbox shows its state to nobody. Checked = access
                         open, so switching it OFF is what pauses. */ ?>
                <label class="me-switch" title="Zugang offen">
                    <input type="checkbox" data-zugang-toggle data-id="<?= e($row['id']) ?>"
                           <?= $row['suspended'] ? '' : 'checked' ?>>
                    <span class="me-switch__track"></span>
                </label>
                <button type="button" class="me-btn me-btn--quiet"
                        data-zugang-entfernen
                        data-konto="<?= e($row['id']) ?>"
                        data-label="<?= e($row['email']) ?>">Entfernen</button>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="me-hint">
        Pausieren ist der leise Weg: Der Zugang ruht, das Konto behält seinen
        Zwei-Faktor-Schutz und seine Geräte, und Sie können ihn jederzeit wieder
        öffnen. Entfernen ist endgültig. Sie selbst stehen ohne beides in der
        Liste — sonst stünde Ihre Verwaltung ohne Zugang da.
    </p>

    <?php if ($offen !== []): ?>
    <h2 class="me-detail__title" style="font-size:1rem">Offene Einladungen</h2>
    <div class="me-units">
        <?php foreach ($offen as $einladung): ?>
        <div class="me-unit">
            <span class="me-unit__name"><?= e($einladung['email']) ?></span>
            <span class="me-unit__status">gültig bis <?= e($day((string)$einladung['until'])) ?></span>
            <form method="post" action="/member/main/profile/einladung-widerrufen" class="me-actions" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="einladung" value="<?= e((string)$einladung['id']) ?>">
                <button type="submit">Zurückziehen</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php /* «Einladen» — one address, so a dialog on this page rather than a
             route of its own (same reasoning as the account dialog). */ ?>
    <dialog class="me-dialog" id="<?= e($inviteDialog) ?>" aria-labelledby="<?= e($inviteDialog) ?>-title">
        <form method="post" action="/member/main/profile/einladen" class="me-dialog__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <h2 class="me-dialog__title" id="<?= e($inviteDialog) ?>-title">Jemanden einladen</h2>
            <div class="fe-form__row">
                <label for="einladen-email">E-Mail-Adresse</label>
                <input id="einladen-email" type="email" name="email" maxlength="254" required
                       autocomplete="email" placeholder="name@firma.ch">
                <small class="me-quiet">
                    Die eingeladene Person richtet ihren Zugang selbst ein; wir
                    schalten ihn frei. Sie kann danach dasselbe wie Sie —
                    einladen, pausieren und entfernen aber nur Sie.
                </small>
            </div>
            <div class="me-dialog__actions">
                <button type="button" class="me-btn me-btn--quiet" data-dialog-close>Abbrechen</button>
                <button type="submit" class="me-btn">Einladung senden</button>
            </div>
        </form>
    </dialog>

    <?php /* Removal asks back — it deletes a person, not a state. One dialog for
             the whole list; the button hands in which row it belongs to. */ ?>
    <dialog class="me-dialog" id="me-zugang-dialog" aria-labelledby="me-zugang-dialog-title">
        <form method="post" action="/member/main/profile/zugang-entfernen" class="me-dialog__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="konto" value="" data-zugang-konto>
            <h2 class="me-dialog__title" id="me-zugang-dialog-title">Zugang entfernen</h2>
            <p>
                Das Konto <strong data-zugang-label></strong> wird gelöscht —
                mit seinem Zwei-Faktor-Schutz und seinen Geräten. Das lässt sich
                nicht rückgängig machen. Ihr Bestand und die übrigen Zugänge
                bleiben unberührt.
            </p>
            <p class="me-quiet">
                Soll der Zugang nur ruhen, schliessen Sie hier und stellen den
                Schalter der Zeile aus.
            </p>
            <div class="me-dialog__actions">
                <button type="button" class="me-btn me-btn--quiet" data-dialog-close>Abbrechen</button>
                <button type="submit" class="me-btn">Entfernen</button>
            </div>
        </form>
    </dialog>

    <?php else: ?>
    <p class="me-detail__sub">
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
        <div class="me-unit">
            <span class="me-unit__name">
                <?= e((string)$device['label']) ?>
                <?php if ($device['current']): ?><span class="me-quiet">— dieses Gerät</span><?php endif; ?>
            </span>
            <span class="me-unit__status">seit <?= e($day((string)$device['created_at'])) ?></span>
            <form method="post" action="/member/main/profile/device-remove" class="me-actions" style="margin:0">
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
