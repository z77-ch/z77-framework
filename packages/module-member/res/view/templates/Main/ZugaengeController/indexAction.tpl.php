<?php
/**
 * «Zugänge» as an AREA of the shell: two sections in the rail, one shown.
 *
 * Until 2026-09-12 this was the fourth section of the profile; it moved
 * because the accesses belong to the REFERENCE, not to the person — see the
 * controller's docblock. The markup is the section's, unchanged in substance:
 * the rows, the switch, the three dialogs, and the sentences that decide.
 *
 * The heading names the reference on purpose. With two references in the
 * header's switcher, «Ihre Verwaltung» alone said nothing — and the list is
 * the HOME's, whatever the switcher shows (the area only exists while the
 * two coincide, but the name makes it readable without knowing that rule).
 *
 * @var string $pageTitle
 * @var string $section     'konten' | 'einladungen'
 * @var string $tenantName  the readable name of the home reference ('' without a label hook)
 * @var \Z77\Module\Member\Entities\MemberAccount $account
 * @var array{accounts: list<array<string,mixed>>, invites: list<array<string,mixed>>} $zugaenge
 * @var string $inviteDialog id of the invitation dialog — the action cell opens it
 * @var string $csrfToken
 */
$day   = static fn(string $iso): string => $iso === '' ? '' : date('d.m.Y', (int)strtotime($iso));
$rows  = $zugaenge['accounts'] ?? [];
$offen = $zugaenge['invites'] ?? [];
$wer   = trim((string)($tenantName ?? ''));
$title = [
    'konten'      => 'Konten',
    'einladungen' => 'Offene Einladungen',
][$section] ?? 'Konten';
?>
<div class="me-detail">
    <button type="button" class="me-back" data-z77-split-close>‹ Liste</button>

    <div class="me-detail__head">
        <h1 class="me-detail__title"><?= e($title) ?></h1>
    </div>

    <?php if ($section === 'einladungen'): ?>
    <p class="me-detail__sub">
        <?= $wer !== '' ? 'Wen Sie zu «' . e($wer) . '» eingeladen haben' : 'Wen Sie eingeladen haben' ?>
    </p>

    <?php if ($offen === []): ?>
    <p>
        Keine Einladung offen. «Einladen» oben schickt einer Adresse einen Link;
        die Person richtet ihren Zugang selbst ein, und wir schalten ihn frei.
    </p>
    <?php else: ?>
    <div class="me-units">
        <?php foreach ($offen as $einladung): ?>
        <div class="me-unit">
            <span class="me-unit__name"><?= e($einladung['email']) ?></span>
            <span class="me-unit__status">gültig bis <?= e($day((string)$einladung['until'])) ?></span>
            <form method="post" action="/member/main/zugaenge/einladung-widerrufen" class="me-actions" style="margin:0">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="einladung" value="<?= e((string)$einladung['id']) ?>">
                <button type="submit">Zurückziehen</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <p class="me-hint">
        Zurückziehen macht den Link in der Mail wirkungslos. Wer ihn danach
        anklickt, sieht dieselbe Seite wie bei einer abgelaufenen Einladung.
    </p>
    <?php endif; ?>

    <?php else: ?>
    <p class="me-detail__sub">
        <?= $wer !== '' ? 'Wer für «' . e($wer) . '» arbeiten darf' : 'Wer für Ihre Verwaltung arbeiten darf' ?>
    </p>

    <div class="me-units">
        <?php foreach ($rows as $row): ?>
        <?php $isGrant = (bool)($row['grant'] ?? false); ?>
        <div class="me-unit">
            <span class="me-unit__name">
                <?= e($row['email']) ?>
                <?php if ($row['name'] !== ''): ?><span class="me-quiet">— <?= e($row['name']) ?></span><?php endif; ?>
                <?php /* A grant (ADR-037): the person's account lives at another
                         reference; here only the permission is listed. Said in
                         the row, because «Entfernen» means something else for it. */ ?>
                <?php if ($isGrant): ?><span class="me-quiet">· Konto bei anderer Verwaltung</span><?php endif; ?>
            </span>
            <span class="me-unit__status">
                <?php if ($row['master']): ?>Sie<?php
                      elseif ($row['waiting']): ?>wartet auf Freischaltung<?php
                      elseif ($row['suspended']): ?>pausiert<?php
                      else: ?>aktiv<?php endif; ?>
            </span>

            <?php /* The master stands in the list WITHOUT both handgrips (spec):
                     a reference whose only account is gone would be reachable
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
                        data-label="<?= e($row['email']) ?>"
                        data-dialog="<?= $isGrant ? 'me-grant-dialog' : 'me-zugang-dialog' ?>">Entfernen</button>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <p class="me-hint">
        Pausieren ist der leise Weg: Der Zugang ruht, das Konto behält seinen
        Zwei-Faktor-Schutz und seine Geräte, und Sie können ihn jederzeit wieder
        öffnen. Entfernen ist endgültig. Sie selbst stehen ohne beides in der
        Liste — sonst stünde Ihre Verwaltung ohne Zugang da. Bei einer Person mit
        Konto bei einer anderen Verwaltung betrifft beides nur den Zugang zu
        Ihrer — ihr Konto bleibt, wo es ist.
    </p>

    <?php /* Removal asks back — it deletes a person, not a state. One dialog for
             the whole list; the button hands in which row it belongs to. */ ?>
    <dialog class="me-dialog" id="me-zugang-dialog" aria-labelledby="me-zugang-dialog-title">
        <form method="post" action="/member/main/zugaenge/zugang-entfernen" class="me-dialog__form">
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

    <?php /* The same question for a GRANT (ADR-037), with the other answer: the
             permission goes, the person's account does not — it lives at
             another reference. A second dialog rather than a sentence swapped by
             script: the wording is the decision, and it must be right without
             a line of JavaScript having run. */ ?>
    <dialog class="me-dialog" id="me-grant-dialog" aria-labelledby="me-grant-dialog-title">
        <form method="post" action="/member/main/zugaenge/zugang-entfernen" class="me-dialog__form">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
            <input type="hidden" name="konto" value="" data-zugang-konto>
            <h2 class="me-dialog__title" id="me-grant-dialog-title">Zugang entfernen</h2>
            <p>
                <strong data-zugang-label></strong> darf danach nicht mehr für
                Ihre Verwaltung arbeiten. Das Konto dieser Person bleibt bei
                ihrer eigenen Verwaltung bestehen — mit Zwei-Faktor-Schutz und
                Geräten. Ihr Bestand und die übrigen Zugänge bleiben unberührt.
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
    <?php endif; ?>

    <?php /* «Einladen» — the area's action, so the dialog is on BOTH sections:
             one address, a dialog on this page rather than a route of its own
             (same reasoning as the account dialog). */ ?>
    <dialog class="me-dialog" id="<?= e($inviteDialog) ?>" aria-labelledby="<?= e($inviteDialog) ?>-title">
        <form method="post" action="/member/main/zugaenge/einladen" class="me-dialog__form">
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
</div>
