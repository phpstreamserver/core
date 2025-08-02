<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\WorkerProcess;
use PHPStreamServer\Plugin\Metrics\RegistryInterface;
use Revolt\EventLoop;

final readonly class MetricsHandler
{
    public function __construct(
        RegistryInterface $registry,
        SupervisorStatus $supervisorStatus,
        MessageHandlerInterface $handler,
    ) {
        $workersTotal = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_workers_total',
            help: 'Total number of workers',
        );

        $processesTotal = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_processes_total',
            help: 'Total number of processes',
        );

        $reloadsTotal = $registry->registerCounter(
            namespace: Server::SHORTNAME,
            name: 'supervisor_worker_reloads_total',
            help: 'Total number of workers reloads',
        );

        $crashesTotal = $registry->registerCounter(
            namespace: Server::SHORTNAME,
            name: 'supervisor_worker_crashes_total',
            help: 'Total number of workers crashes (worker exit with non 0 exit code)',
        );

        $memoryBytes = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_memory_bytes',
            help: 'Memory usage by worker',
            labels: ['pid'],
        );

        $handler->subscribe(ProcessExitEvent::class, static function (ProcessExitEvent $message) use ($memoryBytes, $reloadsTotal, $crashesTotal): void {
            $memoryBytes->remove(['pid' => (string) $message->pid]);
            if ($message->exitCode === WorkerProcess::RELOAD_EXIT_CODE) {
                $reloadsTotal->inc();
            } elseif ($message->exitCode > 0) {
                $crashesTotal->inc();
            }
        });

        $workersTotal->set($supervisorStatus->getWorkersCount());

        $heartBeat = static function () use ($processesTotal, $supervisorStatus, $memoryBytes): void {
            $processesTotal->set($supervisorStatus->getProcessesCount());
            foreach ($supervisorStatus->getProcesses() as $process) {
                $memoryBytes->set($process->memory, ['pid' => (string) $process->pid]);
            }
        };

        EventLoop::delay(0.3, $heartBeat);
        EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $heartBeat);
    }
}
