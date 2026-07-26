<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\Event\ProcessBlockedEvent;
use PHPStreamServer\Core\Event\ProcessHeartbeatEvent;
use PHPStreamServer\Core\Event\ProcessReplacedEvent;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Supervisor\ProcessInfo;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;
use PHPStreamServer\Core\Worker\SupervisedWorker;
use Revolt\EventLoop;

use function PHPStreamServer\Core\getMemoryUsageByPid;

/**
 * @internal
 */
final class WorkerPool
{
    private const BLOCKED_STATUS_RESET_DELAY_SECONDS = 30;
    public const BLOCK_WARNING_THRESHOLD_SECONDS = 6;

    /**
     * @var array<int, SupervisedWorker>
     */
    private array $workersById = [];

    /**
     * @var array<int, WorkerInfo>
     */
    private array $workerInfosById = [];

    /**
     * @var array<int, ProcessInfo>
     */
    private array $processInfosByPid = [];

    public function __construct()
    {
    }

    public function subscribeToEvents(MessageHandlerInterface $handler): void
    {
        $processInfosByPid = &$this->processInfosByPid;

        $handler->subscribe(ProcessHeartbeatEvent::class, static function (ProcessHeartbeatEvent $message) use (&$processInfosByPid): void {
            if (!\array_key_exists($message->pid, $processInfosByPid) || $processInfosByPid[$message->pid]->external === true) {
                return;
            }

            $processInfosByPid[$message->pid]->heartbeatTime = $message->time;
            $processInfosByPid[$message->pid]->memory = $message->memory;
            $processInfosByPid[$message->pid]->blocked = false;
        });

        $handler->subscribe(ProcessBlockedEvent::class, static function (ProcessBlockedEvent $message) use (&$processInfosByPid): void {
            if (!\array_key_exists($message->pid, $processInfosByPid) || $processInfosByPid[$message->pid]->external === true) {
                return;
            }

            $processInfosByPid[$message->pid]->blocked = true;

            $pid = $message->pid;
            EventLoop::unreference(EventLoop::delay(self::BLOCKED_STATUS_RESET_DELAY_SECONDS, static function () use (&$processInfosByPid, $pid): void {
                if (\array_key_exists($pid, $processInfosByPid)) {
                    $processInfosByPid[$pid]->blocked = false;
                }
            }));
        });

        $handler->subscribe(ProcessReplacedEvent::class, static function (ProcessReplacedEvent $message) use (&$processInfosByPid): void {
            if (!\array_key_exists($message->pid, $processInfosByPid)) {
                return;
            }

            $processInfosByPid[$message->pid]->external = true;
            $processInfosByPid[$message->pid]->blocked = false;

            $pid = $message->pid;
            $checkMemoryUsageClosure = static function (string $id) use (&$processInfosByPid, $pid): void {
                if (\array_key_exists($pid, $processInfosByPid)) {
                    $processInfosByPid[$pid]->memory = getMemoryUsageByPid($pid);
                } else {
                    EventLoop::cancel($id);
                }
            };

            EventLoop::unreference(EventLoop::repeat(SupervisedWorker::HEARTBEAT_PERIOD, $checkMemoryUsageClosure));
        });
    }

    public function addWorker(SupervisedWorker $worker): WorkerInfo
    {
        $this->workersById[$worker->id] = $worker;

        $workerInfo = new WorkerInfo(
            id: $worker->id,
            name: $worker->getName(),
            user: $worker->getUser(),
            status: WorkerInfo::STATUS_STARTING,
            processCount: $worker->count,
            reloadable: $worker->reloadable,
        );

        $this->workerInfosById[$worker->id] = $workerInfo;

        return $workerInfo;
    }

    public function removeWorker(int $workerId): void
    {
        if (null === $worker = $this->getWorkerInfoById($workerId)) {
            throw new PHPStreamServerException('Worker is not registered in the pool');
        }

        $worker->status = WorkerInfo::STATUS_STOPPING;
    }

    public function addProcess(int $workerId, int $pid): void
    {
        if (null === $worker = $this->getWorkerInfoById($workerId)) {
            throw new PHPStreamServerException('Worker is not registered in the pool');
        }

        $this->processInfosByPid[$pid] = new ProcessInfo(
            workerId: $worker->id,
            pid: $pid,
            user: $worker->user,
            name: $worker->name,
            startedAt: new \DateTimeImmutable('now'),
            heartbeatTime: \hrtime(true),
            reloadable: $worker->reloadable,
        );

        if ($worker->status === WorkerInfo::STATUS_STARTING && \count($this->getWorkerPids($workerId)) === $worker->processCount) {
            $worker->status = WorkerInfo::STATUS_RUNNING;
        }
    }

    public function removeProcess(int $pid): void
    {
        if (null === $worker = $this->getWorkerInfoByPid($pid)) {
            return;
        }

        unset($this->processInfosByPid[$pid]);

        if ($worker->status === WorkerInfo::STATUS_STOPPING && \count($this->getWorkerPids($worker->id)) === 0) {
            unset($this->workersById[$worker->id]);
            unset($this->workerInfosById[$worker->id]);
        }
    }

    public function markAsBlocked(int $pid): void
    {
        if (\array_key_exists($pid, $this->processInfosByPid)) {
            $processInfosByPid = &$this->processInfosByPid;
            $processInfosByPid[$pid]->blocked = true;
            EventLoop::unreference(EventLoop::delay(self::BLOCKED_STATUS_RESET_DELAY_SECONDS, static function () use (&$processInfosByPid, $pid): void {
                if (\array_key_exists($pid, $processInfosByPid)) {
                    $processInfosByPid[$pid]->blocked = false;
                }
            }));
        }
    }

    public function getWorkerById(int $workerId): SupervisedWorker|null
    {
        return $this->workersById[$workerId] ?? null;
    }

    public function getWorkerInfoById(int $workerId): WorkerInfo|null
    {
        return $this->workerInfosById[$workerId] ?? null;
    }

    public function getWorkerInfoByPid(int $pid): WorkerInfo|null
    {
        if (\array_key_exists($pid, $this->processInfosByPid)) {
            return $this->workerInfosById[$this->processInfosByPid[$pid]->workerId];
        }

        return null;
    }

    /**
     * @return array<WorkerInfo>
     */
    public function getWorkerInfos(): array
    {
        return \array_values($this->workerInfosById);
    }

    /**
     * @return array<ProcessInfo>
     */
    public function getProcessInfos(): array
    {
        return \array_values($this->processInfosByPid);
    }

    /**
     * @return array<int>
     */
    public function getWorkerPids(int $workerId): array
    {
        if (null === $worker = $this->getWorkerInfoById($workerId)) {
            throw new PHPStreamServerException('Worker is not registered in the pool');
        }

        $pids = [];
        foreach ($this->processInfosByPid as $process) {
            if ($process->workerId === $workerId) {
                $pids[] = $process->pid;
            }
        }

        return $pids;
    }
}
