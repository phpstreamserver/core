<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor;

use PHPStreamServer\Core\ConsoleCommand\SupervisorCommand;
use PHPStreamServer\Core\LoggerInterface;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\MetricsHandler;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\Supervisor;
use PHPStreamServer\Core\Worker\SupervisedWorker;
use PHPStreamServer\Core\WorkerInterface;
use PHPStreamServer\Plugin\Metrics\RegistryInterface;
use Revolt\EventLoop\Suspension;

/**
 * @extends Plugin<SupervisedWorker>
 */
final class SupervisorPlugin extends Plugin
{
    private Supervisor $supervisor;
    private MessageHandlerInterface $handler;

    public function __construct(
        private readonly float $restartDelay,
    ) {
    }

    protected function beforeStart(): void
    {
        /** @var int $stopTimeout */
        $stopTimeout = $this->masterContainer->getParameter('stop_timeout');
        $this->supervisor = new Supervisor($this->status, $stopTimeout, $this->restartDelay);
    }

    public function onStart(): void
    {
        $suspension = $this->masterContainer->getService(Suspension::class);
        $logger = &$this->masterContainer->getService(LoggerInterface::class);
        $bus = $this->masterContainer->getService(MessageBusInterface::class);
        $this->handler = $this->masterContainer->getService(MessageHandlerInterface::class);

        $this->supervisor->start($suspension, $logger, $bus, $this->handler);
    }

    public function afterStart(): void
    {
        if (\interface_exists(RegistryInterface::class) && $this->masterContainer->has(RegistryInterface::class)) {
            $registry = $this->masterContainer->getService(RegistryInterface::class);
            new MetricsHandler($registry, $this->supervisor->pool, $this->handler);
        }
    }

    public function registerWorker(WorkerInterface $worker): void
    {
        $factoryId = $this->masterContainer->getService('worker_factory_id_resolver')->__invoke($worker->getId());
        $this->supervisor->registerWorker($worker, $factoryId);
    }

    public function unregisterWorker(int $workerId): void
    {
        $this->supervisor->unregisterWorker($workerId);
    }

    public function onStop(): void
    {
        $this->supervisor->stop()->await();
    }

    public function onReload(): void
    {
        $this->supervisor->reload();
    }

    public function registerCommands(): iterable
    {
        return [
            new SupervisorCommand(),
        ];
    }
}
