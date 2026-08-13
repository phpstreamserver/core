<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\Plugin\Plugin;

interface WorkerInterface
{
    /**
     * @internal
     */
    public function run(ContainerInterface $workerContainer): int;

    /**
     * @return list<class-string<Plugin>>
     */
    public static function handledBy(): array;

    public function getId(): int;

    public function getName(): string;

    public function getUser(): string;

    public function getGroup(): string;

    public function getContainer(): ContainerInterface;

    public function getMessageBus(): MessageBusInterface;
}
