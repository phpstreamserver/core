<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

abstract class Command
{
    /**
     * Describe command name. e.g. "start"
     */
    public const COMMAND = '';

    /**
     * Describe command name. e.g. "Start server"
     */
    public const DESCRIPTION = '';

    public Options $options;

    /**
     * Configure command.
     * Could be used to register options for command e.g. $this->options->addOptionDefinition('daemon', 'd', 'Run in daemon mode');
     */
    public function configure(): void
    {
    }

    /**
     * Execute command.
     * MUST return exit code
     */
    abstract public function execute(array $args): int;
}
