<?php

namespace Z77\Module\Debtor\Invoicing;

/**
 * The SUBJECT LABEL of a document — «Betreffname» (owner decision 2026-10-21): a short word
 * printed in front of the subject («Betreff») — «Projekt», «Betrifft», «Referenz», «Ihre
 * Bestellnummer» … — the wdv `subject_title`. Pure rules, the ONE place for them (Rule 2):
 * the invoice (debtor) and the sales order (module-order) store, judge and print the label
 * through this class.
 *
 *   - {@see normalize()} — what is STORED: trimmed, inner whitespace collapsed to one space
 *     (one line), trailing colons stripped (the print adds «: »); '' = none = null;
 *   - {@see LENGTH} — at most 80 characters after normalising; longer is REFUSED by the write
 *     path (never cut — the subject's rule);
 *   - {@see line()} — what is PRINTED where the subject prints: «<label>: <subject>»; without a
 *     label the subject alone, exactly as before; a label WITHOUT a subject prints nothing (a
 *     lone «Projekt:» says nothing — decided at build 2026-10-21);
 *   - {@see resolve()} — the default of a NEW document: the debtor's own label, else the
 *     installation's (the mandator's `default_subject_label`), else none;
 *   - {@see suggestions()} — the labels a screen offers in its `<datalist>`.
 */
final class SubjectLabel
{
    /** The longest label — `invoice.subject_label` / `sales_order.subject_label` VARCHAR(80). */
    public const LENGTH = 80;

    /** How many suggestions a screen offers at most — a short list (owner 2026-10-21). */
    public const SUGGESTIONS = 50;

    /** The stored form of $label: trimmed, one line, without trailing colons; null = none. */
    public static function normalize(?string $label): ?string
    {
        $label = trim((string) preg_replace('/\s+/u', ' ', (string) $label));
        $label = (string) preg_replace('/[\s:]+$/u', '', $label);

        return $label === '' ? null : $label;
    }

    /** Whether a NORMALISED label exceeds {@see LENGTH}. */
    public static function isTooLong(?string $label): bool
    {
        return $label !== null && mb_strlen($label) > self::LENGTH;
    }

    /**
     * The printed subject line: «<label>: <subject>»; the subject alone without a label; null
     * when there is no subject (a label alone prints nothing).
     */
    public static function line(?string $label, ?string $subject): ?string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return null;
        }
        $label = self::normalize($label);

        return $label === null ? $subject : $label . ': ' . $subject;
    }

    /** The default of a new document: the debtor's own label, else the global one, else null. */
    public static function resolve(?string $debtorLabel, ?string $globalLabel): ?string
    {
        return self::normalize($debtorLabel) ?? self::normalize($globalLabel);
    }

    /**
     * The `<datalist>` of a screen: every list merged, normalised, without duplicates (case
     * ignored — the first spelling wins), sorted, at most {@see SUGGESTIONS}.
     *
     * @param iterable<?string> ...$lists
     * @return list<string>
     */
    public static function suggestions(iterable ...$lists): array
    {
        $labels = [];
        foreach ($lists as $list) {
            foreach ($list as $label) {
                $label = self::normalize($label);
                if ($label !== null && !self::isTooLong($label)) {
                    $labels[mb_strtolower($label)] ??= $label;
                }
            }
        }
        $labels = array_values($labels);
        usort($labels, static fn(string $a, string $b): int => strcasecmp($a, $b));

        return array_slice($labels, 0, self::SUGGESTIONS);
    }
}
