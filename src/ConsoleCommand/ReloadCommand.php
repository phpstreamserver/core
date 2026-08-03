<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Command\ReloadServerCommand;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Server;

class ReloadCommand extends Command
{
    final public static function getName(): string
    {
        return 'reload';
    }

    final public static function getDescription(): string
    {
        return 'Reload the server';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);
        $future = $bus->dispatch(new ReloadServerCommand());
        echo \sprintf("<color;fg=brand;options=bold>❯</> Reloading %s...\n", Server::NAME);
        $future->await();
        echo \sprintf("<color;fg=green;options=bold>✓</> %s reloaded\n", Server::NAME);

        return 0;
    }
}
