<?php
/**
 * Who printed this and when — the last line on the paper (owner decision
 * 2026-09-23: the print date and the user belong in the footer, not in the
 * header, FIN-PRINT-001 point 1).
 *
 * Once, at the end of the document, not on every sheet: a repeating footer
 * needs `position: fixed` plus page margins, and then collides with a table
 * that runs long — which the account statement does (15 sheets).
 *
 * The user comes from the shell's own header view-model (`headerUser`, built by
 * `BackendAbstractController::html()`), not from a second `AuthService` read:
 * the page already carries it, and a report rendered outside the backend shell
 * — the harness does exactly that — then prints the date alone instead of
 * failing on an unregistered service.
 *
 * @var string                                        $printedAt
 * @var array{initials:string,name:string,role:string}|null $headerUser
 */
$printedBy = trim((string)($headerUser['name'] ?? ''));
?>
<footer class="be-printfoot be-printonly">
    Gedruckt <?= e($printedAt) ?><?= $printedBy === '' ? '' : ' durch ' . e($printedBy) ?>
</footer>
