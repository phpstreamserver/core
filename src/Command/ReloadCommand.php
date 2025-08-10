<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Message\ReloadServerCommand;
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
        return 'Reload server';
    }

    public function execute(string $pidFile, string $socketFile): int
    {
        $bus = new ExternalProcessMessageBus($pidFile, $socketFile);
        $future = $bus->dispatch(new ReloadServerCommand());
        echo Server::NAME . " reloading ...\n";
        $future->await();
        echo Server::NAME . " reloaded\n";

        return 0;
    }
}
