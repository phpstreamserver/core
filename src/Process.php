<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use PHPStreamServer\Core\Internal\Container;
use PHPStreamServer\Core\Plugin\Plugin;

interface Process
{
    public function run(Container $workerContainer): int;

    /**
     * @return list<class-string<Plugin>>
     */
    public static function handleBy(): array;
}
