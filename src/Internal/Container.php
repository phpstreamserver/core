<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Exception\ParameterNotFoundException;
use PHPStreamServer\Core\Exception\ServiceNotFoundException;
use PHPStreamServer\Core\Worker\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

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
     * @template T of object
     * @param class-string<T> $id
     * @param \Closure(self): T $factory
     */
    public function registerService(string $id, \Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->services[$id], $this->aliases[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param T $value
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
     * @param class-string<T> $id
     * @return T
     * @throws ServiceNotFoundException
     */
    public function getService(string $id): object
    {
        if (\array_key_exists($id, $this->aliases)) {
            $id = $this->aliases[$id];
        }

        if (\array_key_exists($id, $this->services)) {
            return $this->services[$id];
        }

        if (\array_key_exists($id, $this->factories)) {
            $value = ($this->factories[$id])($this);
            $this->services[$id] = $value;
            unset($this->factories[$id]);
            return $value;
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
    public function getParameter(string $id): array|bool|string|int|float|\UnitEnum|null
    {
        return $this->parameters[$id] ?? throw new ParameterNotFoundException($id);
    }

    public function hasParameter(string $id): bool
    {
        return \array_key_exists($id, $this->parameters);
    }

    public function setParameter(string $id, array|bool|string|int|float|\UnitEnum|null $value): void
    {
        $this->parameters[$id] = $value;
    }
}
