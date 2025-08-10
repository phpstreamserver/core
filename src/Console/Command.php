<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Process;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;

abstract class Command
{
    /**
     * @readonly
     * @internal
     * @var \WeakMap<Driver, array{plugins: array<Plugin>, workers: array<Process>, options: Options}>
     */
    public \WeakMap $map;

    /**
     * @return array<Plugin>
     */
    final protected function getPlugins(): array
    {
        return $this->map[EventLoop::getDriver()]['plugins'];
    }

    /**
     * @return array<Process>
     */
    final protected function getWorkers(): array
    {
        return $this->map[EventLoop::getDriver()]['workers'];
    }

    final protected function addOptionDefinition(string $name, string|null $shortcut = null, string $description = '', string|null $default = null): void
    {
        $this->map[EventLoop::getDriver()]['options']->addOptionDefinition($name, $shortcut, $description, $default);
    }

    final protected function hasOption(string $name): bool
    {
        return $this->map[EventLoop::getDriver()]['options']->hasOption($name);
    }

    final protected function getOption(string $name): string|true|null
    {
        return $this->map[EventLoop::getDriver()]['options']->getOption($name);
    }

    /**
     * Command name, e.g., "start".
     */
    abstract public static function getName(): string;

    /**
     * Command description, e.g., "Start server".
     */
    abstract public static function getDescription(): string;

    /**
     * Configure the command.
     */
    public function configure(): void
    {
    }

    /**
     * Execute the command. MUST return an exit code.
     */
    abstract public function execute(string $pidFile, string $socketFile): int;
}
