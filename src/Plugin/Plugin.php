<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin;

use Amp\Future;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\Process;

/**
 * @template T of Process
 */
abstract class Plugin
{
    protected readonly ContainerInterface $masterContainer;
    protected readonly ContainerInterface $workerContainer;

    /**
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
     * Registers a worker.
     *
     * @param T $worker
     */
    public function registerWorker(Process $worker): void
    {
    }

    /**
     * Unregisters a worker if registered.
     */
    public function unRegisterWorker(int $workerId): void
    {
    }

    /**
     * Runs when the plugin is initialized.
     */
    protected function beforeStart(): void
    {
    }

    /**
     * Runs during server startup.
     */
    public function onStart(): void
    {
    }

    /**
     * Runs after server startup.
     */
    public function afterStart(): void
    {
    }

    /**
     * Runs during server shutdown.
     */
    public function onStop(): Future
    {
        return Future::complete();
    }

    /**
     * Runs when the server reloads.
     */
    public function onReload(): void
    {
    }

    /**
     * Returns the plugin's CLI commands.
     *
     * @return iterable<Command>
     */
    public function registerCommands(): iterable
    {
        return [];
    }
}
