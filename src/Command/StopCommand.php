<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Command;

use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\Message\StopServerCommand;
use PHPStreamServer\Core\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Server;

class StopCommand extends Command
{
    final public const COMMAND = 'stop';
    final public const DESCRIPTION = 'Stop server';

    public function execute(array $args): int
    {
        /**
         * @var array{pidFile: string, socketFile: string} $args
         */

        $this->assertServerIsRunning($args['pidFile']);

        $bus = new SocketFileMessageBus($args['socketFile']);
        echo Server::NAME . " stopping ...\n";
        $bus->dispatch(new StopServerCommand())->await();
        echo Server::NAME . " stopped\n";

        return 0;
    }
}
