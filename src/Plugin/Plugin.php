<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin;

use Amp\Future;
use PHPStreamServer\Core\Console\Command;
use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\Process;

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
    final public function register(ContainerInterface $masterContainer, ContainerInterface $workerContainer, Status &$status): void
    {
        $this->masterContainer = $masterContainer;
        $this->workerContainer = $workerContainer;
        $this->status = &$status;
        $this->beforeStart();
    }

    /**
     * Handles a worker to be configured by the plugin
     */
    public function handleWorker(Process $worker): void
    {
    }

    /**
     * Called before the server starts
     */
    protected function beforeStart(): void
    {
    }

    /**
     * Called during server startup
     */
    public function onStart(): void
    {
    }

    /**
     * Called after the server has started
     */
    public function afterStart(): void
    {
    }

    /**
     * Called after the master process receives a stop command
     */
    public function onStop(): Future
    {
        return Future::complete();
    }

    /**
     * Called after the master process receives a reload command
     */
    public function onReload(): void
    {
    }

    /**
     * Registers commands provided by the plugin
     *
     * @return iterable<Command>
     */
    public function registerCommands(): iterable
    {
        return [];
    }
}
