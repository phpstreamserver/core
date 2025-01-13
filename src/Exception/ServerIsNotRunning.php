<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Exception;

use PHPStreamServer\Core\Server;

final class ServerIsNotRunning extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(Server::NAME . ' is not running');
    }
}
