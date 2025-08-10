<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Message\StopServerCommand;
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
        return 'Stop server';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);
        $future = $bus->dispatch(new StopServerCommand());
        echo Server::NAME . " stopping ...\n";
        $future->await();
        echo Server::NAME . " has stopped\n";

        return 0;
    }
}
