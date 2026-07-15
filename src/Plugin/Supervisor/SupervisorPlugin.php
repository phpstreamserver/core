<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor;

use Amp\Future;
use PHPStreamServer\Core\Command\ProcessesCommand;
use PHPStreamServer\Core\Exception\ServiceNotFoundException;
use PHPStreamServer\Core\Logger\LoggerInterface;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\MetricsHandler;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\Supervisor;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Worker\WorkerProcess;
use PHPStreamServer\Plugin\Metrics\RegistryInterface;
use Revolt\EventLoop\Suspension;

/**
 * @extends Plugin<WorkerProcess>
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
        $this->masterContainer->setService(SupervisorStatus::class, $this->supervisor->supervisorStatus);
    }

    public function registerWorker(Process $worker): void
    {
        $this->supervisor->registerWorker($worker);
    }

    public function onStart(): void
    {
        /** @var Suspension $suspension */
        $suspension = $this->masterContainer->getService('main_suspension');
        /** @var LoggerInterface $logger */
        $logger = &$this->masterContainer->getService(LoggerInterface::class);
        $bus = &$this->masterContainer->getService(MessageBusInterface::class);
        $this->handler = &$this->masterContainer->getService(MessageHandlerInterface::class);

        $this->supervisor->start($suspension, $logger, $bus, $this->handler);
    }

    public function afterStart(): void
    {
        if (\interface_exists(RegistryInterface::class)) {
            try {
                $registry = $this->masterContainer->getService(RegistryInterface::class);
                $this->masterContainer->setService(MetricsHandler::class, new MetricsHandler($registry, $this->supervisor->supervisorStatus, $this->handler));
            } catch (ServiceNotFoundException) {
                // no action
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
