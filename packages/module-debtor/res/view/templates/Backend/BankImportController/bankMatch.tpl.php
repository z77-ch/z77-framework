<?php
/**
 * «Bankabgleich …» (toolbar of Zahlungseingänge) — ADR-061 decision 12, stage C3b — a WINDOW
 * (without the script a page). Per camt.054 entry, or group of old payments without an entry
 * reference, the camt.053 credits that fit, as ONE radio group `take[n]` plus «nicht zuordnen»
 * (empty):
 *
 *   - «Referenz» (the bank's entry reference, sum and count agree) or «Vorschlag» (old payments:
 *     the one credit of the day with the same sum and count) → TICKED IN ADVANCE;
 *   - «zur Auswahl» → nothing ticked, the office picks — never a guess;
 *   - «Abweichung» (the reference agrees, sum or count do not) → no choice: never linked.
 *
 * Below, the confirmed proposals with «Zuordnung lösen» (a `formaction` submit). Nothing is
 * decided here: the POST hands each ticked pair to `BankEntryLinkService`, which judges it again.
 * Labels only — the explanation is the help. Bank text is foreign input: every value through `e()`.
 * The rows are plain data (no module-financial class here).
 *
 * @var list<array<string, mixed>> $rows
 * @var list<array<string, mixed>> $confirmed
 * @var bool $more
 * @var callable $fmt
 * @var string $csrfToken
 * @var bool   $window
 * @var string $origin
 * @var string $actionBase
 */
use Z77\Module\Debtor\Services\BankEntryLinkService;

$actionBase = $actionBase ?? '/backend/finance/bank-import';
$window     = !empty($window);
$confirmed  = $confirmed ?? [];
$takeable   = array_filter($rows, static fn(array $r) => $r['match'] !== BankEntryLinkService::MATCH_PROBLEM);
$title      = 'Bankabgleich · ' . (count($rows) === 1 ? '1 Zahlungseingang' : count($rows) . ' Zahlungseingänge');
$winAttr    = $window ? ' data-window="debtor-bank-match" data-window-entity="bank-import:match" data-window-title="' . e($title) . '" data-window-width="56rem"' : '';
$badges     = [
    BankEntryLinkService::MATCH_IDENTITY   => ['badge--success', 'Referenz'],
    BankEntryLinkService::MATCH_PROPOSAL   => ['badge--info', 'Vorschlag'],
    BankEntryLinkService::MATCH_CANDIDATES => ['badge--warning', 'zur Auswahl'],
    BankEntryLinkService::MATCH_PROBLEM    => ['badge--danger', 'Abweichung'],
];
?>
<div class="be-list"<?= $winAttr ?>>
    <form method="post" action="<?= e($actionBase . '/bank-match') ?>" class="be-list__section" autocomplete="off" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrfToken ?? '') ?>">
        <?php if ($window): ?><input type="hidden" name="_origin" value="<?= e($origin ?? 'page') ?>"><?php endif; ?>
        <?= $this->partial('partials/modalActions', [
            'submit'     => $takeable !== [] ? 'Übernehmen' : '',
            'kind'       => 'confirm',
            'cancelHref' => $window ? '' : $actionBase . '/list',
        ], 'Z77\Shared') ?>
        <?php if (!$window): ?>
        <div class="be-list__section-header"><h2 class="be-list__section-title"><?= e($title) ?></h2></div>
        <?php endif; ?>

        <?php if ($rows === []): ?>
        <p class="be-list__empty" data-bank-match-empty>Auf dem Kontoauszug wartet keine freie Gutschrift auf einen Zahlungseingang.</p>
        <?php endif; ?>
        <?php foreach (array_values($rows) as $n => $row): ?>
        <?php
        [$badgeClass, $badgeText] = $badges[$row['match']] ?? ['badge--muted', ''];
        $problem = $row['match'] === BankEntryLinkService::MATCH_PROBLEM;
        ?>
        <fieldset class="be-form__fieldset" data-bank-match-row="<?= e($row['key']) ?>" data-bank-match-kind="<?= e($row['match']) ?>">
            <legend class="be-form__section">
                <?= e($row['title']) ?> · <?= e($row['count'] === 1 ? '1 Zahlung' : $row['count'] . ' Zahlungen') ?> · <?= $row['amount'] !== null ? e($fmt($row['amount'])) : '—' ?>
                <span class="badge <?= e($badgeClass) ?>"><?= e($badgeText) ?></span>
            </legend>
            <?php if ($row['names'] !== ''): ?><p class="be-form__hint"><?= e($row['names']) ?></p><?php endif; ?>
            <?php if ($problem): ?><p class="be-form__hint" data-bank-match-problem><?= e($row['note']) ?></p><?php endif; ?>
            <div class="be-list__table" style="--be-list-cols: 2rem 6.5rem minmax(12rem, 3fr) 6rem 7rem">
                <?php foreach ($row['movements'] as $movement): ?>
                <?php
                $mid   = (int) $movement['id'];
                $field = 'bank-match-' . $n . '-' . $mid;
                $value = $row['kind'] === 'entry' ? 'e|' . $mid . '|' . $row['ref'] : 'd|' . $mid . '|' . $row['message'] . '|' . $row['day'];
                ?>
                <div class="be-list__item" data-bank-movement="<?= $mid ?>"><div class="be-list__row">
                    <span class="be-list__cell"><?php if (!$problem): ?><label class="be-choice"><input type="radio" class="be-choice__input" id="<?= e($field) ?>" name="take[<?= $n ?>]" value="<?= e($value) ?>"<?= $row['proposed'] === $mid ? ' checked' : '' ?>></label><?php endif; ?></span>
                    <label class="be-list__cell be-list__cell--mono" for="<?= e($field) ?>"><?= e($movement['date']->format('d.m.Y')) ?></label>
                    <label class="be-list__cell" for="<?= e($field) ?>" title="<?= e($movement['text']) ?>"><?= e($movement['text'] !== '' ? $movement['text'] : $movement['ref']) ?></label>
                    <span class="be-list__cell be-list__cell--num be-list__cell--muted"><?= e($movement['count'] === 1 ? '1 Zahlung' : $movement['count'] . ' Zahlungen') ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($movement['amount'])) ?></span>
                </div></div>
                <?php endforeach; ?>
                <?php if (!$problem): ?>
                <div class="be-list__item"><div class="be-list__row">
                    <span class="be-list__cell"><label class="be-choice"><input type="radio" class="be-choice__input" id="bank-match-<?= $n ?>-none" name="take[<?= $n ?>]" value=""<?= $row['proposed'] === null ? ' checked' : '' ?>></label></span>
                    <label class="be-list__cell be-list__cell--muted" for="bank-match-<?= $n ?>-none">nicht zuordnen</label>
                    <span class="be-list__cell"></span><span class="be-list__cell"></span><span class="be-list__cell"></span>
                </div></div>
                <?php endif; ?>
            </div>
        </fieldset>
        <?php endforeach; ?>
        <?php if (!empty($more)): ?>
        <p class="be-form__hint" data-bank-match-more>Weitere Zahlungseingänge folgen nach dem Übernehmen.</p>
        <?php endif; ?>

        <?php if ($confirmed !== []): ?>
        <fieldset class="be-form__fieldset" data-bank-match-confirmed>
            <legend class="be-form__section">Bestätigte Vorschläge</legend>
            <div class="be-list__table" style="--be-list-cols: minmax(12rem, 3fr) 6.5rem 7rem 10rem">
                <?php foreach ($confirmed as $link): ?>
                <div class="be-list__item" data-bank-match-link="<?= e($link['key']) ?>"><div class="be-list__row">
                    <span class="be-list__cell"><?= e($link['title']) ?> <small class="be-list__cell--muted"><?= e($link['label']) ?></small></span>
                    <span class="be-list__cell be-list__cell--mono"><?= e($link['date']->format('d.m.Y')) ?></span>
                    <span class="be-list__cell be-list__cell--num"><?= e($fmt($link['amount'])) ?></span>
                    <span class="be-list__cell"><button type="submit" class="be-btn be-btn--ghost be-btn--sm" formaction="<?= e($actionBase . '/bank-match-release') ?>" name="key" value="<?= e($link['key']) ?>">Zuordnung lösen</button></span>
                </div></div>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php endif; ?>
    </form>
</div>
