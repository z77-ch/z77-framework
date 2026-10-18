<?php

namespace Z77\Module\Order\Services;

/**
 * An order draft refused by its rules — nothing was written. Carries the German message per
 * field (`contact_id`, `order_date`, `service_from`, `service_to`, `price_mode`,
 * `payment_target_code`, `payment_terms_code`, `ship`, `subject`, `notes`, `status_code`, and per line
 * `lines.{path}.{field}` — the path is the index, a child `{parent}.{child}`, the field one of
 * `type`, `variant`, `text`, `quantity`, `unit`, `unit_price`, `discount`, `tax_code`,
 * `revenue_account`, `children`) and the draft as typed, so the window re-renders both.
 */
final class InvalidOrderException extends OrderException
{
    /** @param array<string, string> $errors */
    public function __construct(
        public readonly array $errors,
        public readonly OrderDraft $draft,
    ) {
        parent::__construct('Order refused by its rules: ' . implode(', ', array_keys($errors)));
    }
}
