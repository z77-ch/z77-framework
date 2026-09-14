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
$name = trim(($account->getFirstName() ?? '') . ' ' . ($account->getLastName() ?? ''));
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
    <?php $open   = array_filter($memberships ?? [], static fn(array $m): bool => !empty($m['usable'])); ?>
    <?php foreach ($closed as $m): ?>
    <div class="me-band me-band--info">
        <span class="me-band__dot" aria-hidden="true"></span>
        <span class="me-band__text">
            Ihr Zugang zu «<?= e((string)$m['label']) ?>» ist zurzeit nicht offen<?= trim((string)($m['note'] ?? '')) !== '' ? ': ' . e(trim((string)$m['note'])) . '.' : '.' ?>
        </span>
    </div>
    <?php endforeach; ?>

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
        <?php /* The status the PERSON experiences, not the record's field: an
                 active account whose every access is paused reads «pausiert»
                 — «aktiv» there was true of the login and false of everything
                 the person came for (Peter, 2026-09-14). The band above says
                 which access and whom to ask. */ ?>
        <?php
        $paused = array_filter($closed, static fn(array $m): bool => ($m['state'] ?? '') === 'paused');
        if (!$account->isActive()) {
            $statusText = 'wartet auf Freischaltung';
            $statusNote = '';
        } elseif ($closed !== [] && $open === [] && $paused !== []) {
            $statusText = 'pausiert';
            $statusNote = 'Konto und Anmeldung bleiben bestehen';
        } elseif ($closed !== [] && $open === []) {
            $statusText = 'wartet auf Freischaltung';
            $statusNote = '';
        } else {
            $statusText = 'aktiv';
            $statusNote = '';
        }
        ?>
        <dd>
            <?= e($statusText) ?>
            <?php if ($statusNote !== ''): ?>
            <span class="me-quiet">— <?= e($statusNote) ?></span>
            <?php endif; ?>
        </dd>
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
                <?php /* Which one it renames: the HOME. Since ADR-037 the header
                         may name another reference, so «Ihrem Mandanten» alone
                         pointed the reader at the wrong one (Peter, 2026-09-12). */ ?>
                <small class="me-quiet">
                    Wo Sie arbeiten. Der Name Ihrer Verwaltung wird davon nicht berührt —
                    ihn ändert, wer sie besitzt, im Bereich der Verwaltung.
                </small>
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
