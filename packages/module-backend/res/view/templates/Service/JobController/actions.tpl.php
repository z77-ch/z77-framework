<?php
/**
 * Job row action hub (⋮): «Zeitplan …» opens the schedule dialog (a click replaces this hub,
 * core-wired on popup show), «Jetzt starten» queues the job from here as well — the same
 * POST the row's ▶ sends. Mirrors the other backend hubs (LIST-ACTIONS-HUB-001).
 *
 * @var array{key:string,label:string,schedule:?\Z77\Shared\Entities\JobSchedule} $job
 */
$ic    = fn(string $name) => '<svg class="be-icon" width="15" height="15" aria-hidden="true"><use href="#' . $name . '"/></svg>';
$jobQs = 'job=' . e(rawurlencode($job['key']));
?>
<div class="be-actions">
    <div class="be-modal__header"><h2 class="be-modal__title">Aktionen — «<?= e($job['label']) ?>»</h2></div>
    <?= $this->partial('partials/modalActions', ['submit' => '', 'cancel' => 'Schliessen'], 'Z77\\Shared') ?>
    <div class="be-modal__body">
        <div class="be-actions__list">
            <button type="button" class="be-btn be-btn--ghost be-actions__item" data-fetch-get="/backend/service/job/schedule?<?= $jobQs ?>"><?= raw($ic('icon-edit')) ?> <?= $job['schedule'] === null ? 'Zeitplan setzen …' : 'Zeitplan ändern …' ?></button>
            <form data-fetch-post="/backend/service/job/run">
                <input type="hidden" name="job" value="<?= e($job['key']) ?>">
                <button type="submit" class="be-btn be-btn--ghost be-actions__item"><?= raw($ic('icon-play')) ?> Jetzt starten</button>
            </form>
        </div>
    </div>
</div>
