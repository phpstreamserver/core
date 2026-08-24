<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Console\CommandContext;
use PHPStreamServer\Core\Console\OptionDefinition;
use PHPStreamServer\Core\Console\Options;
use PHPStreamServer\Core\Console\StdoutHandler;
use PHPStreamServer\Core\Console\Table;
use PHPStreamServer\Core\Exception\ServerIsNotRunning;
use PHPStreamServer\Core\Exception\ServerIsRunning;
use PHPStreamServer\Core\MessageBus\MessageBusException;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Server;

use function PHPStreamServer\Core\getStartFile;

/**
 * @internal
 */
final readonly class ConsoleApplication
{
    /**
     * @var list<string>
     */
    private array $argv;

    /**
     * @param list<string> $argv
     */
    public function run(CommandContext $context, array $argv): int
    {
        $this->argv = $argv;
        $currentCommand = $this->getCurrentCommand();
        $allRegisteredCommands = self::getAllRegisteredCommands($context->getPlugins());
        $command = $currentCommand !== null ? ($allRegisteredCommands[$currentCommand] ?? null) : null;

        try {
            $options = $this->getOptions($command);
            $colors = !$options->hasOption('no-color');
            $quiet = $options->hasOption('quiet');
        } catch (\InvalidArgumentException $e) {
            StdoutHandler::register('php://stdout', 'php://stderr');
            echo \sprintf("<color;fg=red;options=bold>✗</> %s\n", $e->getMessage());
            return 1;
        }

        StdoutHandler::register('php://stdout', 'php://stderr', $colors, $quiet);

        if ($options->hasOption('version')) {
            echo \sprintf("%s\n", Server::getVersion());
            return 0;
        }

        if ($command !== null) {
            if ($options->hasOption('help')) {
                $this->showCommandHelp($command, $options->getOptionDefinitions());
                return 0;
            }

            // Free memory
            unset($currentCommand, $allRegisteredCommands);

            try {
                return $command->execute($context, $options);
            } catch (ServerIsNotRunning) {
                echo \sprintf("<color;fg=red;options=bold>✗</> %s is not running\n", Server::NAME);
                return 1;
            } catch (ServerIsRunning) {
                echo \sprintf("<color;fg=red;options=bold>✗</> %s is already running\n", Server::NAME);
                return 1;
            } catch (MessageBusException $e) {
                echo \sprintf("<color;fg=red;options=bold>✗</> Error: %s\n", $e->getMessage());
                return 1;
            }
        }

        if ($currentCommand !== null) {
            echo \sprintf("<color;fg=red;options=bold>✗</> Unknown command \"%s\"\n", $currentCommand);
            return 1;
        }

        $this->showApplicationHelp($allRegisteredCommands, $options->getOptionDefinitions());
        return 0;
    }

    private function getCurrentCommand(): string|null
    {
        for ($i = 1; $i < \count($this->argv); $i++) {
            if (!\str_starts_with($this->argv[$i], '-')) {
                return $this->argv[$i];
            }
        }

        return null;
    }

    private function getOptions(Command|null $command): Options
    {
        return new Options(
            argv: $this->argv,
            optionDefinitions: [
                ...($command?->getOptionDefinitions() ?? []),
                new OptionDefinition('help', 'h', 'Show command help'),
                new OptionDefinition('quiet', 'q', 'Suppress all output'),
                new OptionDefinition('no-color', null, 'Disable ANSI colors'),
                new OptionDefinition('version', null, 'Print the version'),
            ],
        );
    }

    /**
     * @param array<Plugin> $plugins
     * @return array<Command>
     */
    private static function getAllRegisteredCommands(array $plugins): array
    {
        $commands = [];
        foreach ($plugins as $plugin) {
            foreach ($plugin->registerCommands() as $command) {
                $commands[$command::getName()] = $command;
            }
        }

        return $commands;
    }

    /**
     * @param iterable<Command> $commands
     * @param array<OptionDefinition> $options
     */
    private function showApplicationHelp(iterable $commands, array $options): void
    {
        echo \sprintf("<color;fg=brand;options=bold>🌸 %s</>  <color;options=dim>%s</>\n", Server::NAME, Server::getVersion());
        echo "  PHP application server and process manager\n";
        echo "<color;fg=brand;options=bold>Usage</>\n";
        echo \sprintf("  <color;fg=token>%s</> <command> [options]\n", $this->getInvocation());
        echo "<color;fg=brand;options=bold>Commands</>\n";
        echo (new Table(indent: 1))->addRows(self::createCommandsTableRows($commands));
        echo "<color;fg=brand;options=bold>Global options</>\n";
        echo (new Table(indent: 1))->addRows(self::createOptionsTableRows($options));
    }

    /**
     * @param iterable<OptionDefinition> $options
     */
    private function showCommandHelp(Command $command, iterable $options): void
    {
        echo \sprintf("<color;fg=brand;options=bold>🌸 %s</>  <color;options=dim>%s</>\n", Server::NAME, Server::getVersion());
        echo "  PHP application server and process manager\n";
        echo "<color;fg=brand;options=bold>Description</>\n";
        echo \sprintf("  %s\n", $command::getDescription());
        echo "<color;fg=brand;options=bold>Usage</>\n";
        echo \sprintf("  <color;fg=token>%s %s</> [options]\n", $this->getInvocation(), $command::getName());
        echo "<color;fg=brand;options=bold>Options</>\n";
        echo (new Table(indent: 1))->addRows(self::createOptionsTableRows($options));
    }

    private function getInvocation(): string
    {
        $startFile = $this->argv[0] ?? getStartFile();
        if (\preg_match('~^[a-zA-Z0-9_./-]+$~D', $startFile) !== 1) {
            $startFile = \escapeshellarg($startFile);
        }

        return 'php ' . $startFile;
    }

    /**
     * @param iterable<Command> $commands
     */
    private static function createCommandsTableRows(iterable $commands): \Generator
    {
        foreach ($commands as $command) {
            yield [
                \sprintf('<color;fg=token>%s</>', $command::getName()),
                $command::getDescription(),
            ];
        }
    }

    /**
     * @param iterable<OptionDefinition> $options
     */
    private static function createOptionsTableRows(iterable $options): \Generator
    {
        foreach ($options as $option) {
            yield [
                \sprintf(
                    '<color;fg=token>%s--%s%s</>',
                    $option->shortName !== null ? \sprintf('-%s, ', $option->shortName) : '    ',
                    $option->name,
                    $option->requiresValue ? '=VALUE' : '',
                ),
                $option->description,
            ];
        }
    }
}
