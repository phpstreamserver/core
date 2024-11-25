<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Supervisor\Message\ProcessExitEvent;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerProcess;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Plugin\Metrics\Counter;
use PHPStreamServer\Plugin\Metrics\Gauge;
use PHPStreamServer\Plugin\Metrics\RegistryInterface;
use Revolt\EventLoop;

use function Amp\weakClosure;

final readonly class MetricsHandler
{
    private Gauge $workersTotal;
    private Gauge $processesTotal;
    private Counter $reloadsTotal;
    private Counter $crashesTotal;
    private Gauge $memoryBytes;

    public function __construct(
        RegistryInterface $registry,
        private SupervisorStatus $supervisorStatus,
        MessageHandlerInterface $handler,
    ) {
        $this->workersTotal = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_workers_total',
            help: 'Total number of workers',
        );

        $this->processesTotal = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_processes_total',
            help: 'Total number of processes',
        );

        $this->reloadsTotal = $registry->registerCounter(
            namespace: Server::SHORTNAME,
            name: 'supervisor_worker_reloads_total',
            help: 'Total number of workers reloads',
        );

        $this->crashesTotal = $registry->registerCounter(
            namespace: Server::SHORTNAME,
            name: 'supervisor_worker_crashes_total',
            help: 'Total number of workers crashes (worker exit with non 0 exit code)',
        );

        $this->memoryBytes = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_memory_bytes',
            help: 'Memory usage by worker',
            labels: ['pid'],
        );

        $handler->subscribe(ProcessExitEvent::class, weakClosure(function (ProcessExitEvent $message): void {
            $this->memoryBytes->remove(['pid' => $message->pid]);
            if ($message->exitCode === WorkerProcess::RELOAD_EXIT_CODE) {
                $this->reloadsTotal->inc();
            } elseif ($message->exitCode > 0) {
                $this->crashesTotal->inc();
            }
        }));

        $this->workersTotal->set($supervisorStatus->getWorkersCount());

        EventLoop::delay(0.3, $this->heartBeat(...));
        EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $this->heartBeat(...));
    }

    private function heartBeat(): void
    {
        $this->processesTotal->set($this->supervisorStatus->getProcessesCount());

        foreach ($this->supervisorStatus->getProcesses() as $process) {
            $this->memoryBytes->set($process->memory, ['pid' => $process->pid]);
        }
    }
}
