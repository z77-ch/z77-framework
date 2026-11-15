<?php
/**
 * Stammdaten › Finanzen › «Kreditoren» (creditor plan §8) — a STANDARD LIST over
 * {@see \Z77\Module\Creditor\Ui\CreditorListing::definition()}: every creditor profile, rows from
 * `_row` (the switch answers with it in place, FETCH-ROW-001), searched by name, UID and IBAN in the
 * database. The whole list is a FETCH REGION (sort / page / search reload only it; a save in a
 * window opened from here reloads it). The action cell is empty — a creditor is made by the role
 * «Kreditor» in the contact editor. No CSS and no JavaScript of its own.
 *
 * @var list<\Z77\Module\Creditor\Entities\CreditorProfile> $profiles
 * @var array<int, list<\Z77\Module\Creditor\Entities\CreditorIban>> $ibans  profile id → its IBANs
 * @var array<string, string> $methods  payment-method code → label
 * @var \Z77\Shared\Listing\ListDefinition $definition
 * @var \Z77\Shared\Listing\ListState $state
 * @var \Z77\Shared\Paging\Paging $paging
 * @var string $actionBase
 */
$actionBase = $actionBase ?? \Z77\Module\Creditor\Ui\CreditorLayout::BASE;
$listUrl    = $actionBase . '/list';
$link       = static fn(array $changes = []): string => $listUrl . '?' . $state->query($changes);
?>
<div class="be-list" data-fetch-region="<?= e($definition->region()) ?>">
    <div class="be-list__section">
        <div class="be-list__section-header">
            <?= $this->partial('partials/listReset', ['state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
            <h2 class="be-list__section-title">Kreditoren</h2>
            <span class="be-list__section-badge" title="Kreditoren"><?= $paging->total ?></span>
        </div>
        <?= $this->partial('partials/listFind', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
        <div class="be-list__frame">
            <div class="be-list__table be-list__table--drop" style="<?= e($definition->style()) ?>" data-entity-list="creditorProfile">
                <?= $this->partial('partials/listHead', ['definition' => $definition, 'state' => $state, 'action' => $listUrl], 'Z77\\Shared') ?>
                <?php foreach ($profiles as $profile): ?>
                <?= $this->partial('Backend/CreditorProfileController/_row', [
                    'profile'    => $profile,
                    'ibans'      => $ibans[(int) $profile->getId()] ?? [],
                    'methods'    => $methods,
                    'definition' => $definition,
                    'actionBase' => $actionBase,
                ], 'Z77\\Module\\Creditor') ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if ($profiles === []): ?>
        <p class="be-list__empty"><?= $state->isActive() ? 'Kein Kreditor gefunden.' : 'Noch kein Kreditor — ein Kontakt wird Kreditor unter Kontakte › Kontakt bearbeiten › Rollen, Schalter «Kreditor».' ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/pager', [
            'paging'      => $paging,
            'pageLink'    => static fn(int $p): string => $link(['page' => $p]),
            'unit'        => 'Kreditoren',
            'regionLinks' => true,
        ], 'Z77\\Shared') ?>
    </div>
</div>
