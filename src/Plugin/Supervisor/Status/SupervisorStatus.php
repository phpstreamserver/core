<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Status;

use PHPStreamServer\Core\Message\ProcessBlockedEvent;
use PHPStreamServer\Core\Message\ProcessDetachedEvent;
use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\Message\ProcessHeartbeatEvent;
use PHPStreamServer\Core\Message\ProcessSpawnedEvent;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Worker\WorkerProcess;
use Revolt\EventLoop;

use function PHPStreamServer\Core\getMemoryUsageByPid;

final class SupervisorStatus
{
    /**
     * @var array<int, WorkerInfo>
     */
    private array $workers = [];

    /**
     * @var array<int, ProcessInfo>
     */
    private array $processes = [];

    public function __construct()
    {
    }

    public function subscribeToWorkerMessages(MessageHandlerInterface $handler): void
    {
        $processes = &$this->processes;

        $handler->subscribe(ProcessSpawnedEvent::class, static function (ProcessSpawnedEvent $message) use (&$processes): void {
            $processes[$message->pid] = new ProcessInfo(
                workerId: $message->workerId,
                pid: $message->pid,
                user: $message->user,
                name: $message->name,
                startedAt: $message->startedAt,
                reloadable: $message->reloadable,
            );
        });

        $handler->subscribe(ProcessHeartbeatEvent::class, static function (ProcessHeartbeatEvent $message) use (&$processes): void {
            if (!isset($processes[$message->pid]) || $processes[$message->pid]->detached === true) {
                return;
            }

            $processes[$message->pid]->memory = $message->memory;
            $processes[$message->pid]->blocked = false;
        });

        $handler->subscribe(ProcessBlockedEvent::class, static function (ProcessBlockedEvent $message) use (&$processes): void {
            if (!isset($processes[$message->pid]) || $processes[$message->pid]->detached === true) {
                return;
            }

            $processes[$message->pid]->blocked = true;
        });

        $handler->subscribe(ProcessExitEvent::class, static function (ProcessExitEvent $message) use (&$processes): void {
            unset($processes[$message->pid]);
        });

        $handler->subscribe(ProcessDetachedEvent::class, static function (ProcessDetachedEvent $message) use (&$processes): void {
            if (!isset($processes[$message->pid])) {
                return;
            }

            $processes[$message->pid]->detached = true;
            $processes[$message->pid]->blocked = false;

            $checkMemoryUsageClosure = static function (string $id) use (&$processes, $message): void {
                if (isset($processes[$message->pid])) {
                    $processes[$message->pid]->memory = getMemoryUsageByPid($message->pid);
                } else {
                    EventLoop::cancel($id);
                }
            };

            EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $checkMemoryUsageClosure);
            EventLoop::delay(0.2, $checkMemoryUsageClosure);
        });
    }

    public function addWorker(WorkerProcess $worker): void
    {
        $this->workers[$worker->id] = new WorkerInfo(
            id: $worker->id,
            user: $worker->getUser(),
            name: $worker->name,
            count: $worker->count,
            reloadable: $worker->reloadable,
        );
    }

    public function getWorkersCount(): int
    {
        return \count($this->workers);
    }

    /**
     * @return list<WorkerInfo>
     */
    public function getWorkers(): array
    {
        return \array_values($this->workers);
    }

    public function getProcessesCount(): int
    {
        return \count($this->processes);
    }

    /**
     * @return list<ProcessInfo>
     */
    public function getProcesses(): array
    {
        return \array_values($this->processes);
    }

    public function getTotalMemory(): int
    {
        return \array_sum(\array_map(static fn(ProcessInfo $p): int => $p->memory, $this->processes));
    }
}
