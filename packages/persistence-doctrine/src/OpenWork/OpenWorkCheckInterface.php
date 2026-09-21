<?php

namespace Z77\Persistence\Doctrine\OpenWork;

/**
 * A check a module contributes to the open-work registry (plan §2, ADR-039
 * decision 15): asked «anything open?» for one scope, it answers with
 * findings — or with none.
 *
 * Registered by class name under the module config key `openWorkChecks`,
 * keyed by the scope it answers (see `OpenWorkChecks`); instantiated by the
 * registry without arguments, so a check reaches persistence the way every
 * service does (`DI::getUnifiedEntityManager()`).
 *
 * `$parameters` is what the ASKING module defines for that scope — the
 * financial period for `period-close`, the count time for `stocktake` — and
 * the check reads the keys that scope documents. The registry passes it
 * through untouched.
 */
interface OpenWorkCheckInterface
{
    /**
     * @param array<string, mixed> $parameters the scope's context, defined by the asking module
     * @return iterable<Finding>
     */
    public function check(string $scope, array $parameters): iterable;
}
