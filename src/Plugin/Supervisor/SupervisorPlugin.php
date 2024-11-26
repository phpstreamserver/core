<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor;

use Amp\Future;
use PHPStreamServer\Core\Exception\ServiceNotFoundException;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\Command\ProcessesCommand;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\MetricsHandler;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\Supervisor;
use PHPStreamServer\Core\Plugin\Supervisor\Message\GetSupervisorStatusCommand;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Worker\LoggerInterface;
use PHPStreamServer\Plugin\Metrics\RegistryInterface;
use Revolt\EventLoop\Suspension;

final class SupervisorPlugin extends Plugin
{
    private SupervisorStatus $supervisorStatus;
    private Supervisor $supervisor;
    private MessageHandlerInterface $handler;
    private MessageBusInterface $bus;

    public function __construct(
        private readonly int $stopTimeout,
        private readonly float $restartDelay,
    ) {
    }

    protected function beforeStart(): void
    {
        $this->supervisor = new Supervisor($this->status, $this->stopTimeout, $this->restartDelay);
        $this->supervisorStatus = new SupervisorStatus();
        $this->masterContainer->setService(SupervisorStatus::class, $this->supervisorStatus);
    }

    public function addWorker(Process $worker): void
    {
        \assert($worker instanceof WorkerProcess);
        $this->supervisor->addWorker($worker);
        $this->supervisorStatus->addWorker($worker);
    }

    public function onStart(): void
    {
        /** @var Suspension $suspension */
        $suspension = $this->masterContainer->getService('main_suspension');
        /** @var LoggerInterface $logger */
        $logger = $this->masterContainer->getService(LoggerInterface::class);
        $this->handler = $this->masterContainer->getService(MessageHandlerInterface::class);
        $this->bus = $this->masterContainer->getService(MessageBusInterface::class);

        $this->supervisorStatus->subscribeToWorkerMessages($this->handler);
        $this->supervisor->start($suspension, $logger, $this->handler, $this->bus);

        $supervisorStatus = $this->supervisorStatus;
        $this->handler->subscribe(GetSupervisorStatusCommand::class, static function () use ($supervisorStatus): SupervisorStatus {
            return $supervisorStatus;
        });
    }

    public function afterStart(): void
    {
        if (\interface_exists(RegistryInterface::class)) {
            try {
                $registry = $this->masterContainer->getService(RegistryInterface::class);
                $this->masterContainer->setService(MetricsHandler::class, new MetricsHandler($registry, $this->supervisorStatus, $this->handler));
            } catch (ServiceNotFoundException) {
            }
        }
    }

    public function onStop(): Future
    {
        return $this->supervisor->stop();
    }

    public function onReload(): void
    {
        $this->supervisor->reload();
    }

    public function registerCommands(): iterable
    {
        return [
            new ProcessesCommand(),
        ];
    }
}
