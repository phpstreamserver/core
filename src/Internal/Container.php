<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Exception\ParameterNotFoundException;
use PHPStreamServer\Core\Exception\ServiceNotFoundException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * @internal
 */
final class Container implements ContainerInterface
{
    private array $factories = [];
    private array $services = [];
    private array $aliases = [];
    private array $parameters = [];

    public function __construct()
    {
    }

    /**
     * @template T1 of ContainerInterface
     * @template T2 of object
     * @param class-string<T2>|string $id
     * @param \Closure(T1): T2 $factory
     */
    public function registerService(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->services[$id], $this->aliases[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @param T $value
     * @psalm-param (T is string ? object : T) $value
     */
    public function setService(string $id, mixed $value): void
    {
        $this->services[$id] = $value;
        unset($this->factories[$id], $this->aliases[$id]);
    }

    public function setAlias(string $alias, string $id): void
    {
        if ($alias === $id) {
            throw new \InvalidArgumentException(\sprintf('An alias cannot reference itself, got a circular reference on "%s".', $alias));
        }

        $this->aliases[$alias] = $id;
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @psalm-return (T is string ? object : T)
     * @return T
     * @throws ServiceNotFoundException
     * @psalm-suppress NonVariableReferenceReturn
     */
    public function &getService(string $id): object
    {
        if (\array_key_exists($id, $this->aliases)) {
            $id = $this->aliases[$id];
        }

        if (\array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }

        if (\array_key_exists($id, $this->factories)) {
            $this->services[$id] = ($this->factories[$id])($this);
            unset($this->factories[$id]);
            return $this->services[$id];
        }

        throw new ServiceNotFoundException($id);
    }

    /**
     * @throws NotFoundExceptionInterface
     */
    public function get(string $id): mixed
    {
        return $this->getService($id);
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->services) || \array_key_exists($id, $this->aliases) || \array_key_exists($id, $this->factories);
    }

    /**
     * @throws ParameterNotFoundException
     */
    public function getParameter(string $id): array|bool|string|int|float|null
    {
        return $this->parameters[$id] ?? throw new ParameterNotFoundException($id);
    }

    public function hasParameter(string $id): bool
    {
        return \array_key_exists($id, $this->parameters);
    }

    public function setParameter(string $id, array|bool|string|int|float|null $value): void
    {
        $this->parameters[$id] = $value;
    }
}
