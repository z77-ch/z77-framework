<?php
/**
 * The backend entry page (ADR-052): the tiles — one per menu section the user may see, with
 * the section's pages as links, the «+ Neu» buttons of the modules in the head and the pending
 * work as badges — and BELOW them the pending list of every module, urgent first (owner
 * 2026-10-22). Everything here is already filtered by access in the controller
 * (DashboardController, `canReach()` per entry); the template decides nothing.
 *
 * @var \Z77\Shared\Auth\AuthUser $authUser
 * @var string $signedInAs  «admin – Peter Ruepp» (login name, person's name when entered)
 * @var \DateTimeImmutable $today
 * @var list<array{name: string, url: string, links: list<array{name: string, href: string}>, creates: list<array{label: string, href: string, opens: string}>, badges: list<array>}> $tiles
 * @var list<array{severity: string, severityLabel: string, label: string, where: string, figure: string, href: string}> $pending
 */

// A weight wears the shared state pill (components/_badges.scss) — one look per control kind.
$tone = ['urgent' => 'badge--danger', 'todo' => 'badge--warning'];
?>
<div class="be-overview">

    <div class="be-overview__header">
        <h1 class="be-overview__title">Übersicht</h1>
        <div class="be-overview__signed-in">Angemeldet als: <?= e($signedInAs) ?> · <?= e($today->format('d.m.Y')) ?></div>
    </div>

    <div class="be-overview__tiles">
        <?php foreach ($tiles as $tile): ?>
        <section class="be-tile" aria-label="<?= e($tile['name']) ?>">
            <header class="be-tile__head">
                <span class="be-tile__icon" aria-hidden="true"><svg class="be-icon" width="18" height="18"><use href="#<?= e(\Z77\Module\Backend\App\Config\ModuleIcons::forSection($tile['name'])) ?>"/></svg></span>
                <h2 class="be-tile__title">
                    <?php if ($tile['url'] !== ''): ?><a href="<?= e($tile['url']) ?>"><?= e($tile['name']) ?></a><?php else: ?><?= e($tile['name']) ?><?php endif; ?>
                </h2>
                <?php foreach ($tile['creates'] as $create): ?>
                    <?php if ($create['opens'] === \Z77\Shared\Dashboard\DashboardEntry::OPEN_DIALOG): ?>
                    <button type="button" class="be-tile__new" data-fetch-get="<?= e($create['href']) ?>" title="<?= e($create['label']) ?>">
                        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg><span><?= e($create['label']) ?></span>
                    </button>
                    <?php else: ?>
                    <a class="be-tile__new" href="<?= e($create['href']) ?>" title="<?= e($create['label']) ?>"<?php if ($create['opens'] === \Z77\Shared\Dashboard\DashboardEntry::OPEN_WINDOW): ?> data-window-open="<?= e($create['href']) ?>"<?php endif; ?>>
                        <svg class="be-icon" width="14" height="14" aria-hidden="true"><use href="#icon-plus"/></svg><span><?= e($create['label']) ?></span>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </header>
            <?php if ($tile['badges'] !== []): ?>
            <ul class="be-tile__todos">
                <?php foreach ($tile['badges'] as $badge): ?>
                <li><a class="be-tile__todo be-tile__todo--<?= e($badge['severity']) ?>" href="<?= e($badge['href']) ?>" title="<?= e($badge['severityLabel']) ?>">
                    <span class="be-tile__todo-label"><?= e($badge['label']) ?></span>
                    <?php if ($badge['figure'] !== ''): ?><span class="be-tile__todo-figure"><?= e($badge['figure']) ?></span><?php endif; ?>
                </a></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
            <?php if ($tile['links'] !== []): ?>
            <nav class="be-tile__links" aria-label="<?= e($tile['name']) ?>">
                <?php foreach ($tile['links'] as $link): ?>
                <a href="<?= e($link['href']) ?>"><?= e($link['name']) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    </div>

    <section class="be-pending" aria-label="Pendenzen">
        <header class="be-pending__head">
            <h2 class="be-pending__title">Pendenzen</h2>
            <?php if ($pending !== []): ?><span class="be-pending__count"><?= e((string) count($pending)) ?> offen</span><?php endif; ?>
        </header>
        <?php if ($pending === []): ?>
        <p class="be-pending__empty">Nichts offen</p>
        <?php else: ?>
        <ul class="be-pending__list">
            <?php foreach ($pending as $row): ?>
            <li>
                <?php if ($row['href'] !== ''): ?><a class="be-pending__row" href="<?= e($row['href']) ?>"><?php else: ?><span class="be-pending__row"><?php endif; ?>
                    <span class="badge <?= e($tone[$row['severity']] ?? '') ?>"><?= e($row['severityLabel']) ?></span>
                    <span class="be-pending__label"><?= e($row['label']) ?><?php if ($row['where'] !== ''): ?> <span class="be-pending__where">· <?= e($row['where']) ?></span><?php endif; ?></span>
                    <span class="be-pending__figure"><?= e($row['figure']) ?></span>
                <?php if ($row['href'] !== ''): ?></a><?php else: ?></span><?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>

</div>
