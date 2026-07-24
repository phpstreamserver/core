<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\SIGCHLDHandler;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\Message\GetProcessesCommand;
use PHPStreamServer\Core\Message\GetWorkersCommand;
use PHPStreamServer\Core\Message\ProcessBlockedEvent;
use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;
use PHPStreamServer\Core\Worker\WorkerProcess;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function Amp\async;
use function Amp\Future\await;
use function PHPStreamServer\Core\generateWorkerId;

/**
 * @internal
 */
final class Supervisor
{
    private LoggerInterface $logger;
    public MessageBusInterface $messageBus;
    public MessageHandlerInterface $messageHandler;
    public readonly WorkerPool $pool;
    private Suspension $suspension;
    private readonly float $restartDelay;

    public function __construct(
        private Status &$serverStatus,
        private readonly int $stopTimeout,
        float $restartDelay,
    ) {
        $this->pool = new WorkerPool();
        $this->restartDelay = \max($restartDelay, 0);
    }

    public function start(Suspension $suspension, LoggerInterface &$logger, MessageBusInterface &$messageBus, MessageHandlerInterface &$messageHandler): void
    {
        $this->suspension = $suspension;
        $this->logger = &$logger;
        $this->messageBus = &$messageBus;
        $this->messageHandler = &$messageHandler;

        SIGCHLDHandler::onChildProcessExit($this->onProcessStop(...));
        EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $this->monitorWorkerStatus(...));

        $this->pool->subscribeToEvents($this->messageHandler);

        $pool = $this->pool;

        $this->messageHandler->subscribe(GetWorkersCommand::class, static function () use ($pool): array {
            return $pool->getWorkerInfos();
        });

        $this->messageHandler->subscribe(GetProcessesCommand::class, static function () use ($pool): array {
            return $pool->getProcessInfos();
        });
    }

    public function registerWorker(WorkerProcess $worker): void
    {
        $workerId = generateWorkerId();
        $worker->assignId($workerId);

        $workerInfo = $this->pool->addWorker($worker);
        $this->startWorker($workerInfo);
    }

    public function unregisterWorker(int $workerId): void
    {
        if (null === $worker = $this->pool->getWorkerInfoById($workerId)) {
            return;
        }

        $this->pool->removeWorker($worker->id);
        $this->stopWorker($worker);
    }

    public function stop(): Future
    {
        return async(function (): void {
            $futures = [];
            foreach ($this->pool->getWorkerInfos() as $worker) {
                $futures[] = $this->stopWorker($worker);
            }
            await($futures);
        });
    }

    public function reload(): void
    {
        foreach ($this->pool->getProcessInfos() as $process) {
            if ($process->reloadable) {
                \posix_kill($process->pid, $process->detached ? SIGTERM : SIGUSR1);
            }
        }
    }

    private function startWorker(WorkerInfo $worker): Future
    {
        return async(function () use ($worker): void {
            $workerProcess = $this->pool->getWorkerProcessById($worker->id);
            \assert($workerProcess !== null);
            while (\count($this->pool->getWorkerPids($worker->id)) < $worker->processCount) {
                if ($this->spawnProcess($workerProcess)) {
                    return;
                }
            }
        });
    }

    private function stopWorker(WorkerInfo $worker): Future
    {
        $future = new DeferredFuture();
        $stopTimeout = $this->stopTimeout;
        $pidsToKill = $this->pool->getWorkerPids($worker->id);

        $onProcessExit = static function (ProcessExitEvent $event) use (&$pidsToKill, $future): void {
            $pidsToKill = \array_values(\array_diff($pidsToKill, [$event->pid]));

            if (\count($pidsToKill) === 0 && !$future->isComplete()) {
                $future->complete();
            }
        };

        $this->messageHandler->subscribe(ProcessExitEvent::class, $onProcessExit);

        foreach ($pidsToKill as $pid) {
            \posix_kill($pid, SIGTERM);
        }

        $logger = $this->logger;
        $stopCallbackId = EventLoop::delay($stopTimeout, static function () use ($stopTimeout, &$pidsToKill, $logger, $worker, $future): void {
            // Send SIGKILL signal to all worker processes after timeout
            foreach ($pidsToKill as $pid) {
                \posix_kill($pid, SIGKILL);
                $logger->notice(\sprintf('Worker "%s" [PID:%d] was killed after a %d-second timeout', $worker->name, $pid, $stopTimeout));
            }
            if (!$future->isComplete()) {
                $future->complete();
            }
        });

        $messageHandler = $this->messageHandler;
        $future->getFuture()->finally(static function () use ($messageHandler, $stopCallbackId, $onProcessExit): void {
            EventLoop::cancel($stopCallbackId);
            $messageHandler->unsubscribe(ProcessExitEvent::class, $onProcessExit);
        });

        return $future->getFuture();
    }

    private function spawnProcess(WorkerProcess $worker): bool
    {
        $pid = \pcntl_fork();
        if ($pid > 0) {
            // Master process
            $this->onProcessStart($worker->id, $pid);
            return false;
        } elseif ($pid === 0) {
            // Child process
            $this->suspension->resume($worker);
            return true;
        } else {
            throw new PHPStreamServerException('Fork failed');
        }
    }

    private function monitorWorkerStatus(): void
    {
        foreach ($this->pool->getProcessInfos() as $process) {
            $blockTime = $process->detached ? 0 : (int) \round((\hrtime(true) - $process->heartbeatTime) * 1e-9);
            if ($process->blocked === false && $blockTime > $this->pool::BLOCK_WARNING_THRESHOLD_SECONDS) {
                $worker = $this->pool->getWorkerInfoByPid($process->pid);
                $this->pool->markAsBlocked($process->pid);
                $messageBus = $this->messageBus;
                EventLoop::defer(static function () use ($messageBus, $process): void {
                    $messageBus->dispatch(new ProcessBlockedEvent($process->pid));
                });
                $this->logger->warning(\sprintf(
                    'Worker "%s" [PID:%d] blocked the event loop for more than %d seconds',
                    $worker->name ?? '',
                    $process->pid,
                    $blockTime,
                ));
            }
        }
    }

    private function onProcessStart(int $workerId, int $pid): void
    {
        $this->pool->addProcess($workerId, $pid);
    }

    private function onProcessStop(int $pid, int $exitCode): void
    {
        if (null === $worker = $this->pool->getWorkerInfoByPid($pid)) {
            return;
        }

        $this->pool->removeProcess($pid);

        $messageBus = $this->messageBus;
        EventLoop::queue(static function () use ($messageBus, $pid, $exitCode): void {
            $messageBus->dispatch(new ProcessExitEvent($pid, $exitCode));
        });

        if ($this->serverStatus === Status::RUNNING) {
            if ($exitCode === 0) {
                $this->logger->info(\sprintf('Worker "%s" [PID:%d] exited with code %d', $worker->name, $pid, $exitCode));
            } elseif ($exitCode === WorkerProcess::RELOAD_EXIT_CODE && $worker->reloadable) {
                $this->logger->info(\sprintf('Worker "%s" [PID:%d] reloaded', $worker->name, $pid));
            } else {
                $this->logger->warning(\sprintf('Worker "%s" [PID:%d] exited with code %d', $worker->name, $pid, $exitCode));
            }

            if ($worker->status === WorkerInfo::STATUS_RUNNING) {
                // Restart worker
                EventLoop::delay($this->restartDelay, function () use ($worker): void {
                    $worker = $this->pool->getWorkerInfoById($worker->id);
                    if ($this->serverStatus === Status::RUNNING && $worker !== null && $worker->status === WorkerInfo::STATUS_RUNNING) {
                        $workerProcess = $this->pool->getWorkerProcessById($worker->id);
                        \assert($workerProcess !== null);
                        $this->spawnProcess($workerProcess);
                    }
                });
            }
        }
    }
}
