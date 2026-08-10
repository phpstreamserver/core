<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Worker;

use PHPStreamServer\Core\Event\ProcessReplacedEvent;

use function PHPStreamServer\Core\getAbsoluteBinaryPath;

/**
 * Runs an external command in supervised worker processes by replacing process with the executable
 */
final class ExecutableWorker extends SupervisedWorker
{
    private readonly string $command;

    public function __construct(
        string $command,
        string|null $name = null,
        int $count = 1,
        bool $reloadable = true,
        string|null $user = null,
        string|null $group = null,
    ) {
        parent::__construct(name: $name, count: $count, reloadable: $reloadable, user: $user, group: $group);

        $this->command = \trim($command);
        $this->onStart(static fn(self $worker) => self::start($worker));
    }

    private static function start(self $worker): void
    {
        $worker->bus->dispatch(new ProcessReplacedEvent($worker->getPid()))->await();

        if ($worker->command === '') {
            $worker->logger->critical('External process call error: command cannot be empty', ['command' => $worker->command]);
            $worker->stop(1);
            return;
        }

        // Check whether the command contains logical operators such as "&&" and "||"
        if (\preg_match('/(\'[^\']*\'|"[^"]*")(*SKIP)(*FAIL)|&&|\|\|/', $worker->command) === 1) {
            $worker->logger->critical(
                'External process call error: logical operators are not supported. Use a shell with the -c option, for example: /bin/sh -c \'command1 && command2\'',
                ['command' => $worker->command],
            );

            $worker->stop(1);
            return;
        }

        [$absolutePath, $args] = self::convertCommandToPcntl($worker->command);
        \register_shutdown_function(self::exec(...), $absolutePath, $args);

        \set_error_handler(static function (int $code) use ($worker): true {
            $worker->logger->critical('External process call error: ' . \posix_strerror($code), ['command' => $worker->command]);
            return true;
        });

        $worker->stop();
    }

    /**
     * Prepare the command for use with pcntl_exec()
     *
     * @param non-empty-string $command
     * @return array{0: string, 1: list<string>}
     */
    private static function convertCommandToPcntl(string $command): array
    {
        \preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $command, $matches);
        $parts = \array_map(static fn(string $part): string => \trim($part, '"\''), $matches[0]);
        $binary = \array_shift($parts);
        $args = $parts;

        return [getAbsoluteBinaryPath($binary), $args];
    }

    /**
     * Gives control to an external program
     *
     * @param string $path path to a binary executable or script
     * @param array $args array of argument strings passed to the program
     * @see https://www.php.net/manual/en/function.pcntl-exec.php
     */
    private static function exec(string $path, array $args): never
    {
        $envVars = [...\getenv(), ...$_ENV];
        \pcntl_exec($path, $args, $envVars);

        exit(1);
    }
}
