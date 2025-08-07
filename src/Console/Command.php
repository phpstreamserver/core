<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

abstract class Command
{
    /**
     * Command name, e.g., "start".
     */
    public const COMMAND = '';

    /**
     * Command description, e.g., "Start server".
     */
    public const DESCRIPTION = '';

    public Options $options;

    /**
     * Configure the command.
     * Can be used to register options, e.g., $this->options->addOptionDefinition('daemon', 'd', 'Run in daemon mode');
     */
    public function configure(): void
    {
    }

    /**
     * Execute the command. MUST return an exit code.
     */
    abstract public function execute(array $args): int;
}
