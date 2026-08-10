<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Worker;

use PHPStreamServer\Core\Exception\ConfigurationException;
use PHPStreamServer\Core\Internal\CallbackSignatureValidator;
use PHPStreamServer\Core\WorkerInterface;

/**
 * Creates worker instances on demand, optionally using runtime parameters
 */
final class WorkerFactory
{
    public static array $registeredFactoryIds = [];

    /**
     * @param string $id Unique WorkerFactory ID used to select the factory at runtime
     * @param \Closure(array<string, mixed>=): WorkerInterface $factory Factory that creates a worker from runtime parameters
     */
    public function __construct(public readonly string $id, public readonly \Closure $factory)
    {
        if (isset(self::$registeredFactoryIds[$id])) {
            throw new ConfigurationException('id', \sprintf('WorkerFactory ID "%s" is already exists', $id));
        }

        try {
            CallbackSignatureValidator::assertWorkerFactoryCallback($factory);
        } catch (\Throwable $e) {
            throw new ConfigurationException('factory', $e->getMessage());
        }

        self::$registeredFactoryIds[$id] = true;
    }
}
