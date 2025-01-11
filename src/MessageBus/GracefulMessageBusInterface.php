<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

use Amp\Future;

interface GracefulMessageBusInterface extends MessageBusInterface
{
    public function stop(): Future;
}
