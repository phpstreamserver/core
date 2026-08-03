<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\ConsoleCommand;

use PHPStreamServer\Core\Command\StopServerCommand;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Server;

class StopCommand extends Command
{
    final public static function getName(): string
    {
        return 'stop';
    }

    final public static function getDescription(): string
    {
        return 'Stop the server';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);
        $future = $bus->dispatch(new StopServerCommand());
        echo \sprintf("<color;fg=brand;options=bold>❯</> Stopping %s...\n", Server::NAME);
        $future->await();
        echo \sprintf("<color;fg=green;options=bold>✓</> %s stopped\n", Server::NAME);

        return 0;
    }
}
