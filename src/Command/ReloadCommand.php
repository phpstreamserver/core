<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Message\ReloadServerCommand;
use PHPStreamServer\Core\MessageBus\ExternalProcessMessageBus;
use PHPStreamServer\Core\Server;

class ReloadCommand extends Command
{
    final public const COMMAND = 'reload';
    final public const DESCRIPTION = 'Reload server';

    public function execute(array $args): int
    {
        /**
         * @var array{pidFile: string, socketFile: string} $args
         */

        $bus = new ExternalProcessMessageBus($args['pidFile'], $args['socketFile']);
        echo Server::NAME . " reloading ...\n";
        $bus->dispatch(new ReloadServerCommand())->await();
        echo Server::NAME . " reloaded\n";

        return 0;
    }
}
