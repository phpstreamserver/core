<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\LoggerInterface;
use PHPStreamServer\Core\Worker\WorkerFactory;
use PHPStreamServer\Core\WorkerInterface;

final class WorkerFactoryManager
{
    /**
     * @var \WeakMap<WorkerInterface, mixed>
     */
    private \WeakMap $registry;

    /**
     * @param array<WorkerFactory> $workerFactories
     */
    public function __construct(
        private readonly array $workerFactories,
        private LoggerInterface &$logger,
    ) {
        WorkerFactory::$registeredFactoryIds = [];
        /** @var \WeakMap<WorkerInterface, mixed> */
        $this->registry = new \WeakMap();
    }

    public function createWorker(string $factoryId, array $parameters): WorkerInterface|null
    {
        foreach ($this->workerFactories as $workerFactory) {
            if ($workerFactory->id !== $factoryId) {
                continue;
            }

            try {
                $worker = ($workerFactory->factory)($parameters);
                $this->registry->offsetSet($worker, $factoryId);

                return $worker;
            } catch (\Throwable $e) {
                $this->logger->critical(\sprintf('Failed to create worker using WorkerFactory "%s": %s', $factoryId, $e->getMessage()), ['exception' => $e]);

                return null;
            }
        }

        $this->logger->warning(\sprintf('No WorkerFactory is registered with ID "%s"', $factoryId));

        return null;
    }

    public function getWorkerById(int $workerId): WorkerInterface|null
    {
        foreach ($this->registry as $worker => $value) {
            if ($worker->getId() === $workerId) {
                return $worker;
            }
        }

        return null;
    }

    public function getFactoryIdByWorkerId(int $workerId): string|null
    {
        foreach ($this->registry as $worker => $value) {
            if ($worker->getId() === $workerId) {
                return $value;
            }
        }

        return null;
    }
}
