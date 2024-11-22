<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

interface MessageHandlerInterface
{
    /**
     * @template T of MessageInterface
     * @param class-string<T> $class
     * @param \Closure(T): mixed $closure
     */
    public function subscribe(string $class, \Closure $closure): void;

    /**
     * @template T of MessageInterface
     * @param class-string<T> $class
     * @param \Closure(T): mixed $closure
     */
    public function unsubscribe(string $class, \Closure $closure): void;
}
