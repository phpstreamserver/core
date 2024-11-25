<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Worker;

use PHPStreamServer\Core\Exception\ParameterNotFoundException;
use PHPStreamServer\Core\Exception\ServiceNotFoundException;
use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    /**
     * @template T1 of ContainerInterface
     * @template T2 of object
     * @param class-string<T2>|string $id
     * @param \Closure(T1): T2 $factory
     */
    public function registerService(string $id, \Closure $factory): void;

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @param T $value
     * @psalm-param (T is string ? object : T) $value
     */
    public function setService(string $id, object $value): void;

    public function setAlias(string $alias, string $id): void;

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @psalm-return (T is string ? object : T)
     * @return T
     * @throws ServiceNotFoundException
     */
    public function getService(string $id): object;

    /**
     * @throws ServiceNotFoundException
     */
    public function get(string $id): mixed;

    public function has(string $id): bool;

    /**
     * @throws ParameterNotFoundException
     */
    public function getParameter(string $id): array|bool|string|int|float|null;

    public function hasParameter(string $id): bool;

    public function setParameter(string $id, array|bool|string|int|float|null $value): void;
}
