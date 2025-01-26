<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Worker;

use PHPStreamServer\Core\Message\ProcessDetachedEvent;

use function PHPStreamServer\Core\getAbsoluteBinaryPath;

class ExternalProcess extends WorkerProcess
{
    public function __construct(
        string $name = '',
        int $count = 1,
        bool $reloadable = true,
        string|null $user = null,
        string|null $group = null,
        private readonly string $command = '',
    ) {
        parent::__construct(name: $name, count: $count, reloadable: $reloadable, user: $user, group: $group);

        $this->onStart($this->startProcess(...));
    }

    private function startProcess(): void
    {
        $this->bus->dispatch(new ProcessDetachedEvent($this->pid))->await();

        if ($this->command === '') {
            $this->logger->critical('External process call error: command can not be empty', ['comand' => $this->command]);
            $this->stop(1);
            return;
        }

        // Check if command contains logic operators such as && and ||
        if (\preg_match('/(\'[^\']*\'|"[^"]*")(*SKIP)(*FAIL)|&&|\|\|/', $this->command) === 1) {
            $this->logger->critical(\sprintf(
                'External process call error: logical operators not supported, use shell with -c option e.g. "/bin/sh -c "%s"',
                $this->command,
            ), ['comand' => $this->command]);

            $this->stop(1);
            return;
        }

        \register_shutdown_function($this->exec(...), ...$this->convertCommandToPcntl($this->command));
        $this->stop();
    }

    /**
     * Prepare command for pcntl_exec acceptable format
     *
     * @param non-empty-string $command
     * @return array{0: string, 1: list<string>}
     */
    private function convertCommandToPcntl(string $command): array
    {
        \preg_match_all('/\'[^\']*\'|"[^"]*"|\S+/', $command, $matches);
        $parts = \array_map(static fn(string $part): string => \trim($part, '"\''), $matches[0]);
        $binary = \array_shift($parts);
        $args = $parts;

        return [getAbsoluteBinaryPath($binary), $args];
    }

    /**
     * Give control to an external program
     *
     * @param string $path path to a binary executable or a script
     * @param array $args array of argument strings passed to the program
     * @see https://www.php.net/manual/en/function.pcntl-exec.php
     */
    private function exec(string $path, array $args): never
    {
        $envVars = [...\getenv(), ...$_ENV];

        \set_error_handler(function (int $code): true {
            $this->logger->critical('External process call error: ' . \posix_strerror($code), ['comand' => $this->command]);
            return true;
        });

        \pcntl_exec($path, $args, $envVars);

        exit(1);
    }
}
