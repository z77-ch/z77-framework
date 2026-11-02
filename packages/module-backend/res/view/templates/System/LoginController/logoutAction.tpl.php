<?php
/**
 * The way out when a click cannot sign out by itself (SEC-GET-001): a stale tab's token, a
 * project's old link, a bookmark. The fresh token comes with this page; only its button acts.
 *
 * @var bool $stale a POST whose token did not fit
 */
?>
<div class="login">
    <div class="login__box">
        <?= $this->partial('partials/brandMark', ['class' => 'login__logo'], 'Z77\\Shared') ?>
        <h1 class="login__title">Abmelden</h1>
        <p class="login__sub"><?= !empty($stale) ? 'Sitzung abgelaufen: bitte Abmelden bestätigen.' : 'Bitte Abmelden bestätigen.' ?></p>
        <form method="post" action="/backend/system/login/logout">
            <?= $this->partial('partials/csrfField', [], 'Z77\Shared') ?>
            <button class="be-btn be-btn--primary be-btn--full" type="submit" autofocus>Abmelden</button>
        </form>
        <p class="login__after"><a href="/backend/system/dashboard/overview">Abbrechen</a></p>
    </div>
</div>
