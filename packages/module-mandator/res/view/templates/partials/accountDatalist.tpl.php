<?php
/**
 * The account picker — a shared `<datalist>` of the postable, ACTIVE accounts
 * of the chart (Rule 8): the mandator's account fields and debtor's invoice
 * editor (revenue account per line) type an account BY NUMBER and get the
 * chart as suggestions. The rows come from
 * `LedgerAccountCheck::postableAccounts()` — plain `{number, label}` arrays,
 * so no template of a module that only `suggest`s module-financial touches a
 * financial class. Empty list (financial not registered) → nothing is
 * rendered and the fields stay plain text inputs. No JavaScript.
 *
 * Rendered with `$this->partial('partials/accountDatalist', ['id' => …, 'accounts' => …], 'Z77\\Module\\Mandator')`;
 * the inputs reference it with `list="{id}"`.
 *
 * @var string $id
 * @var list<array{number: string, label: string}> $accounts
 */
if (empty($accounts)) {
    return;
}
?>
<datalist id="<?= e($id) ?>">
    <?php foreach ($accounts as $account): ?>
    <option value="<?= e($account['number']) ?>"><?= e($account['label']) ?></option>
    <?php endforeach; ?>
</datalist>
