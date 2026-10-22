<?php
/**
 * Job row context menu (⋮, core.js «context menu», owner 2026-10-22): «Zeitplan …» opens the
 * schedule dialog, «Jetzt starten» queues the job from here as well — the same POST the row's ▶
 * sends (a small data-fetch-post form, wired when the menu opens; the menu closes once the
 * submit went out, the answer refreshes the row).
 *
 * @var array{key:string,label:string,schedule:?\Z77\Shared\Entities\JobSchedule} $job
 */
$ic    = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$jobQs = 'job=' . e(rawurlencode($job['key']));
?>
<div class="be-menu" role="menu" aria-label="Aktionen — «<?= e($job['label']) ?>»">
    <button type="button" class="be-menu__item" role="menuitem" data-fetch-get="/backend/service/job/schedule?<?= $jobQs ?>"><?= raw($ic('icon-edit')) ?> <?= $job['schedule'] === null ? 'Zeitplan setzen …' : 'Zeitplan ändern …' ?></button>
    <form class="be-menu__form" role="none" data-fetch-post="/backend/service/job/run">
        <input type="hidden" name="job" value="<?= e($job['key']) ?>">
        <button type="submit" class="be-menu__item" role="menuitem"><?= raw($ic('icon-play')) ?> Jetzt starten</button>
    </form>
</div>
