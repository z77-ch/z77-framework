<?php
/** @var \Z77\Shared\Entities\MetaData|null $metaData */
$ld = $metaData?->getApplicationLd() ?? [];
?>
<?php if (!empty($ld)): ?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?></script>
<?php endif; ?>
