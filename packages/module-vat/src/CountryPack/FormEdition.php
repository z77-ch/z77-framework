<?php

namespace Z77\Module\Vat\CountryPack;

/**
 * One edition of a VAT return form, read from a country pack's rule file
 * ({@see FormMapping}): its validity, its fields with their kind, the rules
 * «category + rate → field» and the per-code exceptions (ADR-041 addendum
 * 2026-11-24, point 5).
 *
 * The file is checked completely when it is read — every rule must point at
 * a field of the kind its category needs, a rate field's rule at the field's
 * own rate — so a broken file fails at once, not in the middle of a return.
 *
 * Immutable; built only by {@see FormMapping}.
 */
final class FormEdition
{
    /** «every rate» in a rule of the file. */
    private const ANY_RATE = 'any';

    /**
     * @param array<string, array{kind: FieldKind, rate: ?int}>                   $fields     field → kind (+ rate of a rate field), in form order
     * @param array<string, string>                                                $rules      "category|rate" or "category|any" → field
     * @param array<string, array{category: string, field: ?string, input: ?string}> $exceptions code → exception
     */
    private function __construct(
        public readonly string $name,
        public readonly string $validFrom,
        public readonly ?string $validTo,
        private readonly array $fields,
        private readonly array $rules,
        private readonly array $exceptions,
    ) {}

    /**
     * Where the lines of this code, category and rate land on the form.
     *
     * A code exception applies only while the code carries the category the
     * exception names: a code moved to another category follows that
     * category's rule, never a field meant for the old one.
     *
     * @throws UnmappedTaxCodeException the category + rate has no field, or a reverse-charge code has no input field
     */
    public function assign(string $taxCode, string $category, int $rate): FieldAssignment
    {
        $kind = FieldKind::forCategory($category);
        if ($kind === null) {
            throw new UnmappedTaxCodeException($taxCode, $category, $rate, $this->name, 'unknown tax category');
        }

        $exception = $this->exceptions[$taxCode] ?? null;
        if ($exception !== null && $exception['category'] !== $category) {
            $exception = null;
        }

        $field = $exception['field'] ?? $this->rules[$category . '|' . $rate] ?? $this->rules[$category . '|' . self::ANY_RATE] ?? null;
        if ($field === null) {
            throw new UnmappedTaxCodeException($taxCode, $category, $rate, $this->name);
        }

        $input = null;
        if ($kind === FieldKind::Acquisition) {
            $input = $exception['input'] ?? null;
            if ($input === null) {
                throw new UnmappedTaxCodeException($taxCode, $category, $rate, $this->name, 'no input field for this reverse-charge code');
            }
        }

        return new FieldAssignment($taxCode, $category, $rate, $field, $kind, $input);
    }

    /** True when the whole period lies inside this edition's validity (`Y-m-d`, both bounds inclusive). */
    public function covers(string $from, string $to): bool
    {
        return $from >= $this->validFrom && ($this->validTo === null || $to <= $this->validTo);
    }

    /**
     * @return array<string, FieldKind> every field of the form, in form order — PHP turns a
     *         numeric key into an int, so read a key back as `(string)`
     */
    public function fields(): array
    {
        return array_map(static fn(array $field): FieldKind => $field['kind'], $this->fields);
    }

    /** @return list<string> the fields of one kind, in form order */
    public function fieldsOf(FieldKind $kind): array
    {
        return array_map('strval', array_keys(array_filter($this->fields, static fn(array $field): bool => $field['kind'] === $kind)));
    }

    /** The one field of a structural kind ({@see FieldKind::STRUCTURAL}). */
    public function fieldOf(FieldKind $kind): string
    {
        $fields = $this->fieldsOf($kind);
        if (count($fields) !== 1) {
            throw new \LogicException("Kind '{$kind->value}' is not a structural kind of '{$this->name}'");
        }

        return $fields[0];
    }

    /**
     * Read one edition of the decoded rule file.
     *
     * @param array<mixed> $data
     * @throws FormMappingException
     */
    public static function fromArray(array $data, string $source): self
    {
        $refuse = static fn(string $message) => new FormMappingException("{$source}: {$message}");

        $name = $data['edition'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw $refuse('an edition needs a name ("edition")');
        }
        $refuse = static fn(string $message) => new FormMappingException("{$source}, edition '{$name}': {$message}");

        $validFrom = $data['valid_from'] ?? null;
        $validTo   = $data['valid_to'] ?? null;
        if (!self::isDate($validFrom) || ($validTo !== null && (!self::isDate($validTo) || $validTo < $validFrom))) {
            throw $refuse('"valid_from" must be a date YYYY-MM-DD, "valid_to" null or a date not before it');
        }

        $fields = [];
        foreach (self::listOf($data, 'fields', $refuse) as $entry) {
            $field = $entry['field'] ?? null;
            $kind  = is_string($entry['kind'] ?? null) ? FieldKind::tryFrom($entry['kind']) : null;
            if (!is_string($field) || $field === '' || isset($fields[$field])) {
                throw $refuse('every field needs a number of its own ("field")');
            }
            if ($kind === null) {
                throw $refuse("field {$field} has no known kind");
            }
            $rate = $entry['rate'] ?? null;
            if (($kind === FieldKind::Rate) !== (is_int($rate) && $rate >= 0) || ($kind !== FieldKind::Rate && $rate !== null)) {
                throw $refuse("field {$field}: a rate field needs its rate as an integer (hundredths of a percent), any other field none");
            }
            if (!is_string($entry['label'] ?? null)) {
                throw $refuse("field {$field} needs a label");
            }
            $fields[$field] = ['kind' => $kind, 'rate' => $rate];
        }
        foreach (FieldKind::STRUCTURAL as $kind) {
            if (count(array_filter($fields, static fn(array $f): bool => $f['kind'] === $kind)) !== 1) {
                throw $refuse("the form needs exactly one field of kind '{$kind->value}'");
            }
        }

        $fieldFor = static function (mixed $field, string $category, string $what) use ($fields, $refuse): string {
            $needed = FieldKind::forCategory($category);
            if ($needed === null) {
                throw $refuse("{$what}: unknown tax category '{$category}'");
            }
            if (!is_string($field) || !isset($fields[$field])) {
                throw $refuse("{$what}: the field is not a field of this form");
            }
            if ($fields[$field]['kind'] !== $needed) {
                throw $refuse("{$what}: field {$field} is of kind '{$fields[$field]['kind']->value}', category '{$category}' needs '{$needed->value}'");
            }

            return $field;
        };

        $rules = [];
        foreach (self::listOf($data, 'rules', $refuse) as $i => $entry) {
            $category = $entry['category'] ?? null;
            $rate     = $entry['rate'] ?? null;
            if (!is_string($category) || !(($rate === self::ANY_RATE) || (is_int($rate) && $rate >= 0))) {
                throw $refuse("rule " . ($i + 1) . ' needs a "category" and a "rate" (an integer or "any")');
            }
            $what  = "rule {$category} at {$rate}";
            $field = $fieldFor($entry['field'] ?? null, $category, $what);
            if ($fields[$field]['kind'] === FieldKind::Rate && $fields[$field]['rate'] !== $rate) {
                throw $refuse("{$what}: field {$field} is the field of rate {$fields[$field]['rate']}");
            }
            $key = $category . '|' . $rate;
            if (isset($rules[$key])) {
                throw $refuse("{$what} is mapped twice");
            }
            $rules[$key] = $field;
        }
        foreach ($rules as $key => $field) {
            [$category, $rate] = explode('|', $key);
            if ($rate !== self::ANY_RATE && isset($rules[$category . '|' . self::ANY_RATE])) {
                throw $refuse("category '{$category}' has a rule for every rate and one for rate {$rate} — one of them is wrong");
            }
        }

        $exceptions = [];
        foreach (self::listOf($data, 'codes', $refuse) as $entry) {
            $code     = $entry['code'] ?? null;
            $category = $entry['category'] ?? null;
            if (!is_string($code) || $code === '' || isset($exceptions[$code]) || !is_string($category)) {
                throw $refuse('every code exception needs a "code" of its own and a "category"');
            }
            $what  = "code {$code}";
            $field = isset($entry['field']) ? $fieldFor($entry['field'], $category, $what) : null;
            if ($field !== null && $fields[$field]['kind'] === FieldKind::Rate) {
                throw $refuse("{$what}: a rate field is chosen by the rate, not by the code");
            }
            $input = $entry['input_field'] ?? null;
            if ($input !== null && (FieldKind::forCategory($category) !== FieldKind::Acquisition || !is_string($input) || ($fields[$input]['kind'] ?? null) !== FieldKind::Input)) {
                throw $refuse("{$what}: \"input_field\" belongs to a reverse-charge code and names a field of kind 'input'");
            }
            if ($field === null && $input === null) {
                throw $refuse("{$what}: an exception names a \"field\" or an \"input_field\"");
            }
            $exceptions[$code] = ['category' => $category, 'field' => $field, 'input' => $input];
        }

        return new self($name, $validFrom, $validTo, $fields, $rules, $exceptions);
    }

    /**
     * @param array<mixed> $data
     * @return list<array<mixed>>
     */
    private static function listOf(array $data, string $key, \Closure $refuse): array
    {
        $list = $data[$key] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            throw $refuse("\"{$key}\" must be a list");
        }
        foreach ($list as $entry) {
            if (!is_array($entry)) {
                throw $refuse("every entry of \"{$key}\" must be an object");
            }
        }

        return $list;
    }

    private static function isDate(mixed $value): bool
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y);
    }
}
