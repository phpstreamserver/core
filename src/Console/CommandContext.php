<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Worker\WorkerFactory;
use PHPStreamServer\Core\WorkerInterface;

final class CommandContext
{
    /**
     * @param array<Plugin> $plugins
     * @param array<WorkerInterface> $workers
     * @param array<WorkerFactory> $workerFactories
     */
    public function __construct(
        public readonly string $pidFile,
        public readonly string $socketFile,
        private array $plugins,
        private array $workers,
        private array $workerFactories,
    ) {
    }

    /**
     * @return array<Plugin>
     */
    public function getPlugins(): array
    {
        return $this->plugins;
    }

    /**
     * @return array<WorkerInterface>
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /**
     * @return array<WorkerFactory>
     */
    public function getWorkerFactories(): array
    {
        return $this->workerFactories;
    }

    /**
     * @internal
     * @return array{plugins: array<Plugin>, workers: array<WorkerInterface>, workerFactories: array<WorkerFactory>}
     */
    public function takeRuntimeState(): array
    {
        $state = [
            'plugins' => $this->plugins,
            'workers' => $this->workers,
            'workerFactories' => $this->workerFactories,
        ];

        $this->plugins = [];
        $this->workers = [];
        $this->workerFactories = [];

        return $state;
    }
}
