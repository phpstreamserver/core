<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

use Amp\Future;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;

interface GracefulMessageBusInterface extends MessageBusInterface
{
    public function stop(): Future;
}
