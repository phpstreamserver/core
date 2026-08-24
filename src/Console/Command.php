<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

abstract class Command
{
    /**
     * Command name, e.g., "start".
     */
    abstract public static function getName(): string;

    /**
     * Command description, e.g., "Start server".
     */
    abstract public static function getDescription(): string;

    /**
     * Returns the CLI options supported by this command.
     *
     * @return array<OptionDefinition>
     */
    public function getOptionDefinitions(): array
    {
        return [];
    }

    /**
     * Execute the command. MUST return an exit code.
     */
    abstract public function execute(CommandContext $context, Options $options): int;
}
