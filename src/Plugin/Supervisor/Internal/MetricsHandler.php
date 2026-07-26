<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\Event\ProcessExitEvent;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\SupervisedWorker;
use PHPStreamServer\Plugin\Metrics\RegistryInterface;
use Revolt\EventLoop;

final readonly class MetricsHandler
{
    private const UPDATE_INTERVAL_SECONDS = SupervisedWorker::HEARTBEAT_PERIOD;

    public function __construct(RegistryInterface $registry, WorkerPool $pool, MessageHandlerInterface $handler)
    {
        $workersGauge = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_workers',
            help: 'Current number of registered workers',
        );

        $processesGauge = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_processes',
            help: 'Current number of running processes',
        );

        $reloadsCounter = $registry->registerCounter(
            namespace: Server::SHORTNAME,
            name: 'supervisor_worker_reloads_total',
            help: 'Total number of worker reloads',
        );

        $crashesCounter = $registry->registerCounter(
            namespace: Server::SHORTNAME,
            name: 'supervisor_process_crashes_total',
            help: 'Total number of process crashes (exited with non-zero code)',
        );

        $memoryGauge = $registry->registerGauge(
            namespace: Server::SHORTNAME,
            name: 'supervisor_process_memory_bytes',
            help: 'Memory usage of the process in bytes',
            labels: ['pid'],
        );

        $handler->subscribe(ProcessExitEvent::class, static function (ProcessExitEvent $message) use ($memoryGauge, $reloadsCounter, $crashesCounter): void {
            $memoryGauge->remove(['pid' => (string) $message->pid]);
            if ($message->exitCode === SupervisedWorker::RELOAD_EXIT_CODE) {
                $reloadsCounter->inc();
            } elseif ($message->exitCode > 0) {
                $crashesCounter->inc();
            }
        });

        $heartbeat = static function () use ($pool, $workersGauge, $processesGauge, $memoryGauge): void {
            $workers = $pool->getWorkerInfos();
            $processes = $pool->getProcessInfos();

            $workersGauge->set(\count($workers));
            $processesGauge->set(\count($processes));

            foreach ($processes as $process) {
                $memoryGauge->set($process->memory, ['pid' => (string) $process->pid]);
            }
        };

        EventLoop::unreference(EventLoop::delay(0.1, $heartbeat));
        EventLoop::unreference(EventLoop::repeat(self::UPDATE_INTERVAL_SECONDS, $heartbeat));
    }
}
