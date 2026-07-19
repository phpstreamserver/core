<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Worker\WorkerProcess;
use Revolt\EventLoop;

/**
 * @internal
 */
final class WorkerPool
{
    private const BLOCKED_LABEL_PERSISTENCE = 30;
    public const BLOCK_WARNING_TRESHOLD = 6;

    /**
     * @var array<int, WorkerProcess>
     */
    private array $workerPool = [];

    /**
     * @var array<int, array<int, ProcessStatus>>
     */
    private array $processStatusMap = [];

    /**
     * @var array<int, true>
     */
    private array $workersToUnload = [];

    public function __construct()
    {
    }

    public function registerWorker(WorkerProcess $worker): void
    {
        $this->workerPool[$worker->id] = $worker;
        $this->processStatusMap[$worker->id] = [];
    }

    public function unregisterWorker(int $workerId): void
    {
        if (!isset($this->workerPool[$workerId])) {
            throw new PHPStreamServerException('Worker is not registered in the pool');
        }

        $this->workersToUnload[$workerId] = true;
    }

    public function addChild(int $workerId, int $pid): void
    {
        if (null === $worker = $this->getWorkerById($workerId)) {
            throw new PHPStreamServerException('Worker is not registered in the pool');
        }

        $this->processStatusMap[$worker->id][$pid] = new ProcessStatus($pid, $worker->reloadable);
    }

    public function removeChild(int $pid): void
    {
        if (null === $worker = $this->getWorkerByPid($pid)) {
            return;
        }

        unset($this->processStatusMap[$worker->id][$pid]);

        if ($this->isUnloading($worker->id) && \count($this->getWorkerPids($worker->id)) === 0) {
            unset($this->workersToUnload[$worker->id]);
            unset($this->workerPool[$worker->id]);
            unset($this->processStatusMap[$worker->id]);
        }
    }

    public function isUnloading(int $workerId): bool
    {
        return \array_key_exists($workerId, $this->workersToUnload);
    }

    public function markAsDetached(int $pid): void
    {
        if (null !== $worker = $this->getWorkerByPid($pid)) {
            $this->processStatusMap[$worker->id][$pid]->detached = true;
        }
    }

    public function markAsBlocked(int $pid): void
    {
        if (null !== $worker = $this->getWorkerByPid($pid)) {
            $processStatusMap = &$this->processStatusMap;
            $processStatusMap[$worker->id][$pid]->blocked = true;
            EventLoop::delay(self::BLOCKED_LABEL_PERSISTENCE, static function () use (&$processStatusMap, $worker, $pid): void {
                if (isset($processStatusMap[$worker->id][$pid])) {
                    $processStatusMap[$worker->id][$pid]->blocked = false;
                }
            });
        }
    }

    public function markAsHealthy(int $pid, int $time): void
    {
        if (null !== $worker = $this->getWorkerByPid($pid)) {
            $this->processStatusMap[$worker->id][$pid]->blocked = false;
            $this->processStatusMap[$worker->id][$pid]->time = $time;
        }
    }

    public function getWorkerById(int $id): WorkerProcess|null
    {
        return $this->workerPool[$id] ?? null;
    }

    public function getWorkerByPid(int $pid): WorkerProcess|null
    {
        foreach ($this->processStatusMap as $workerId => $processes) {
            if (\in_array($pid, \array_keys($processes), true)) {
                return $this->workerPool[$workerId];
            }
        }

        return null;
    }

    /**
     * @return \Iterator<WorkerProcess>
     */
    public function getRegisteredWorkers(): \Iterator
    {
        return new \ArrayIterator($this->workerPool);
    }

    /**
     * @return array<int>
     */
    public function getWorkerPids(int $workerId): array
    {
        return \array_keys($this->processStatusMap[$workerId] ?? []);
    }

    /**
     * @return \Iterator<WorkerProcess, ProcessStatus>
     */
    public function getAllProcessStatuses(): \Iterator
    {
        foreach ($this->processStatusMap as $workerId => $processes) {
            foreach ($processes as $process) {
                yield $this->workerPool[$workerId] => $process;
            }
        }
    }

    public function getWorkerCount(): int
    {
        return \count($this->workerPool);
    }

    public function getProcessesCount(): int
    {
        return \iterator_count($this->getAllProcessStatuses());
    }
}
