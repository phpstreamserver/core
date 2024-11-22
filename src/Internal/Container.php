<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Worker\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class Container implements ContainerInterface
{
    private array $factory = [];
    private array $cache = [];
    private array $alias = [];

    public function __construct()
    {
    }

    /**
     * @param \Closure(self): mixed $factory
     */
    public function register(string $id, \Closure $factory): void
    {
        $this->factory[$id] = $factory;
        unset($this->cache[$id], $this->alias[$id]);
    }

    public function set(string $id, mixed $value): void
    {
        $this->cache[$id] = $value;
        unset($this->factory[$id], $this->alias[$id]);
    }

    public function alias(string $alias, string $id): void
    {
        $this->alias[$alias] = $id;
    }

    /**
     * @throws NotFoundExceptionInterface
     */
    public function &get(string $id): mixed
    {
        if (\array_key_exists($id, $this->alias)) {
            $id = $this->alias[$id];
        }

        if (\array_key_exists($id, $this->cache)) {
            return $this->cache[$id];
        }

        if (\array_key_exists($id, $this->factory)) {
            $value = ($this->factory[$id])($this);
            $this->cache[$id] = $value;
            unset($this->factory[$id]);
            return $value;
        }

        $message = \sprintf('"%s" is not registered in container', $id);
        throw new class($message) extends \InvalidArgumentException implements NotFoundExceptionInterface {};
    }

    public function has(string $id): bool
    {
        return \array_key_exists($id, $this->cache) || \array_key_exists($id, $this->factory);
    }
}
