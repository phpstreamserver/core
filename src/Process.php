<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Worker\ContainerInterface;

interface Process
{
    public function run(ContainerInterface $workerContainer): int;

    /**
     * @return list<class-string<Plugin>>
     */
    public static function handleBy(): array;
}
