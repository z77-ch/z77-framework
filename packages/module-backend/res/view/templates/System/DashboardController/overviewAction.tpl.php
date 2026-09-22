<?php
/** @var \Z77\Shared\Auth\AuthUser $authUser */
/** @var list<array{id:string,code:string,label:string,sub:string,url:string}> $cards  only the cards this user may open (DashboardController) */
?>
<div class="be-overview">

    <div class="be-overview__header">
        <div class="be-overview__greeting">Guten Tag, <?= e($authUser->getUserName()) ?></div>
        <h1 class="be-overview__title">Übersicht</h1>
    </div>

    <div class="be-overview__modules">
        <?php foreach ($cards as $m): ?>
        <a href="<?= e($m['url']) ?>" class="be-module-card">
            <span class="be-module-card__code"><?= e($m['code']) ?></span>
            <span class="be-module-card__label"><?= e($m['label']) ?></span>
            <span class="be-module-card__sub"><?= e($m['sub']) ?></span>
            <svg class="be-icon be-module-card__arrow" width="16" height="16" aria-hidden="true"><use href="#icon-arrow-right"/></svg>
        </a>
        <?php endforeach; ?>
    </div>

</div>
