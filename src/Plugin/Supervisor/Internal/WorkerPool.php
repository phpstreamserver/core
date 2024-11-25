<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerProcess;
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
     * @var ProcessStatus
     */
    private array $processStatusMap = [];

    public function __construct()
    {
    }

    public function registerWorker(WorkerProcess $worker): void
    {
        $this->workerPool[$worker->id] = $worker;
        $this->processStatusMap[$worker->id] = [];
    }

    public function addChild(WorkerProcess $worker, int $pid): void
    {
        if (!isset($this->workerPool[$worker->id])) {
            throw new PHPStreamServerException('Worker is not found in pool');
        }

        $this->processStatusMap[$worker->id][$pid] = new ProcessStatus($pid, $worker->reloadable);
    }

    public function markAsDeleted(int $pid): void
    {
        if (null !== $worker = $this->getWorkerByPid($pid)) {
            unset($this->processStatusMap[$worker->id][$pid]);
        }
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
            $this->processStatusMap[$worker->id][$pid]->blocked = true;
            EventLoop::delay(self::BLOCKED_LABEL_PERSISTENCE, function () use ($worker, $pid) {
                if (isset($this->processStatusMap[$worker->id][$pid])) {
                    $this->processStatusMap[$worker->id][$pid]->blocked = false;
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
     * @psalm-return iterable<WorkerProcess>
     */
    public function getRegisteredWorkers(): \Iterator
    {
        return new \ArrayIterator($this->workerPool);
    }

    /**
     * @return \Iterator<int>
     */
    public function getAliveWorkerPids(WorkerProcess $worker): \Iterator
    {
        return new \ArrayIterator(\array_keys($this->processStatusMap[$worker->id] ?? []));
    }

    /**
     * @return \Iterator<WorkerProcess, ProcessStatus>
     */
    public function getProcesses(): \Iterator
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
        return \iterator_count($this->getProcesses());
    }
}
