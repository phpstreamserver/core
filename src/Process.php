<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use PHPStreamServer\Core\Logger\LoggerInterface;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Worker\ContainerInterface;

interface Process
{
    public function run(ContainerInterface $workerContainer): int;

    /**
     * @return list<class-string<Plugin>>
     */
    public static function handleBy(): array;

    public function getPid(): int;

    public function getName(): string;

    public function getUser(): string;

    public function getGroup(): string;

    public function getContainer(): ContainerInterface;

    public function getLogger(): LoggerInterface;

    public function getMessageBus(): MessageBusInterface;
}
