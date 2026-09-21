<?php

namespace Z77\Persistence\Doctrine\Console;

use Doctrine\Migrations\Tools\Console\Command\DoctrineCommand,
    Symfony\Component\Console\Command\Command,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Output\OutputInterface
;

/**
 * One of Doctrine's migration commands under z77's short name (`migrate`,
 * `status`, `diff`, `generate`), with a hook before and after it.
 *
 * Doctrine's commands are final, so they are wrapped, not extended: the
 * wrapper carries the inner command's input definition and hands the bound
 * input straight through. The hooks are what z77 adds around the stock
 * behaviour — the metadata table in our collation and the cache deletion
 * around `migrate`, the namespace guard in front of `diff` and `generate`.
 */
final class WrappedCommand extends Command
{
    /**
     * @param null|callable(InputInterface, OutputInterface): ?int      $before returns an exit code to stop, null to go on
     * @param null|callable(InputInterface, OutputInterface, int): void $after  runs after the inner command, with its exit code
     */
    public function __construct(
        string $name,
        private readonly DoctrineCommand $inner,
        private $before = null,
        private $after = null
    ) {
        parent::__construct($name);
        $this->setDescription((string)$inner->getDescription());
        $this->setHelp((string)$inner->getHelp());
        $this->setDefinition($inner->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->before !== null) {
            $stop = ($this->before)($input, $output);
            if ($stop !== null) {
                return $stop;
            }
        }

        $this->inner->setApplication($this->getApplication());
        $code = $this->inner->run($input, $output);

        if ($this->after !== null) {
            ($this->after)($input, $output, $code);
        }

        return $code;
    }
}
