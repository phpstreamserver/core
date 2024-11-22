<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Worker;

use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

interface ContainerInterface extends PsrContainerInterface
{
    /**
     * @param \Closure(self): mixed $factory
     */
    public function register(string $id, \Closure $factory): void;

    public function set(string $id, mixed $value): void;

    public function alias(string $alias, string $id): void;

    /**
     * @throws NotFoundExceptionInterface
     */
    public function &get(string $id): mixed;

    public function has(string $id): bool;
}
