<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin;

use Amp\Future;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\WorkerInterface;

/**
 * Base class for plugins.
 *
 * @template T of WorkerInterface
 */
abstract class Plugin
{
    /**
     * The master-process service container.
     */
    protected readonly ContainerInterface $masterContainer;

    /**
     * The container inherited by worker processes.
     */
    protected readonly ContainerInterface $workerContainer;

    /**
     * The current master-process status.
     *
     * @readonly
     */
    protected Status $status;

    final public function __destruct()
    {
    }

    /**
     * @internal
     */
    final public function init(ContainerInterface $masterContainer, ContainerInterface $workerContainer, Status &$status): void
    {
        $this->masterContainer = $masterContainer;
        $this->workerContainer = $workerContainer;
        $this->status = &$status;
        $this->beforeStart();
    }

    /**
     * Initializes the plugin before master-process startup.
     *
     * The service containers and current server status are available when this method is called.
     */
    protected function beforeStart(): void
    {
    }

    /**
     * Starts the plugin in the master process.
     *
     * Called during startup before configured workers are registered.
     */
    public function onStart(): void
    {
    }

    /**
     * Runs after onStart() and after all configured workers have been registered.
     */
    public function afterStart(): void
    {
    }

    /**
     * Registers a worker with this plugin.
     *
     * Called when the master registers a worker that declares this plugin in WorkerInterface::handledBy().
     * Depending on the plugin, this may configure, schedule, or start the worker.
     *
     * @param T $worker
     */
    public function registerWorker(WorkerInterface $worker): void
    {
    }

    /**
     * Notifies the plugin that a worker has been unregistered.
     *
     * Called for every plugin when the master unregisters a worker.
     * Implementations MUST ignore unknown worker IDs.
     */
    public function unregisterWorker(int $workerId): void
    {
    }

    /**
     * Stops the plugin during master-process shutdown.
     *
     * Returns a future that completes when the plugin has finished its shutdown work.
     * The master waits for all plugin shutdown futures before exiting.
     */
    public function onStop(): Future
    {
        return Future::complete();
    }

    /**
     * Called in the master process when a server reload is requested.
     *
     * Override this method to refresh plugin state or reload resources managed by the plugin.
     */
    public function onReload(): void
    {
    }

    /**
     * Returns CLI commands provided by the plugin.
     *
     * Called before plugin initialization. Implementations must not access $masterContainer, $workerContainer, or $status.
     *
     * @return iterable<Command>
     */
    public function registerCommands(): iterable
    {
        return [];
    }
}
