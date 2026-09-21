<?php

namespace Z77\Persistence\Doctrine\OpenWork;

/**
 * The answer to «anything open?»: every finding the registered checks
 * reported for one scope, and the two questions a caller actually asks —
 * is the action blocked, and is there anything to show at all.
 */
final class OpenWork
{
    /** @var list<Finding> */
    private array $findings;

    /** @param list<Finding> $findings */
    public function __construct(array $findings)
    {
        foreach ($findings as $finding) {
            if (!$finding instanceof Finding) {
                throw new \InvalidArgumentException('OpenWork takes Finding objects only.');
            }
        }
        $this->findings = array_values($findings);
    }

    /** Nothing open — no finding of either severity. */
    public function isEmpty(): bool
    {
        return $this->findings === [];
    }

    /** At least one blocking finding: the action MUST NOT proceed. */
    public function isBlocked(): bool
    {
        return $this->blocking() !== [];
    }

    /** @return list<Finding> */
    public function blocking(): array
    {
        return array_values(array_filter($this->findings, fn(Finding $f) => $f->severity === Severity::Blocking));
    }

    /** @return list<Finding> */
    public function warnings(): array
    {
        return array_values(array_filter($this->findings, fn(Finding $f) => $f->severity === Severity::Warning));
    }
}
