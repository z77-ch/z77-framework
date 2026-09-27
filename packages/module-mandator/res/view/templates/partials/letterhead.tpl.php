<?php
/**
 * The mandator as a printed address block — the letterhead of every document
 * this installation puts on paper. Owner decision 2026-09-24 (mandator.md
 * «letterhead consumers» had deferred the line order to P3 part 3; the
 * financial report header needed it first, so it is decided here).
 *
 * The lines, in the order a Swiss letterhead reads them: name, the two
 * address suffixes, street + house number, zip + city, and the UID only when
 * one is stored — with the ` MWST` suffix appended only when the mandator is
 * liable to VAT (the suffix is never STORED, mandator.md «the UID»).
 *
 * Deliberately NOT here: phone, e-mail, website and the logo. A report is an
 * internal document and needs none of them; the invoice (P3 part 3) places
 * them by its own rules and renders them itself. A block that carried
 * everything would have to be undone by every caller.
 *
 * No record saved → nothing at all. An invented company on a printed balance
 * sheet is worse than none.
 *
 * @var \Z77\Module\Mandator\Entities\Mandator|null $mandator
 */
$m = $mandator ?? null;
if ($m === null) {
    return;
}

$lines = array_values(array_filter(
    [
        $m->getName(),
        $m->getAddressSuffixOne(),
        $m->getAddressSuffixTwo(),
        trim($m->getStreet() . ' ' . $m->getHouseNo()),
        trim($m->getZip() . ' ' . $m->getCity()),
    ],
    static fn(string $line): bool => trim($line) !== ''
));
$uid = trim($m->getUid());
if ($uid !== '') {
    $lines[] = $uid . ($m->isLiableToVat() ? ' MWST' : '');
}
if ($lines === []) {
    return;
}
?>
<address class="be-letterhead">
    <?php foreach ($lines as $i => $line): ?>
    <span class="be-letterhead__line<?= $i === 0 ? ' be-letterhead__line--name' : '' ?>"><?= e($line) ?></span>
    <?php endforeach; ?>
</address>
