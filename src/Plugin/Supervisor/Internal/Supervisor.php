<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use PHPStreamServer\Core\Command\GetProcessesCommand;
use PHPStreamServer\Core\Command\GetWorkersCommand;
use PHPStreamServer\Core\Event\ProcessBlockedEvent;
use PHPStreamServer\Core\Event\ProcessExitEvent;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\SIGCHLDHandler;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Supervisor\WorkerInfo;
use PHPStreamServer\Core\Worker\SupervisedWorker;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function Amp\async;
use function Amp\Future\await;
use function PHPStreamServer\Core\strSignal;

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
        EventLoop::repeat(SupervisedWorker::HEARTBEAT_PERIOD, $this->monitorWorkerStatus(...));

        $this->pool->subscribeToEvents($this->messageHandler);

        $pool = $this->pool;

        $this->messageHandler->subscribe(GetWorkersCommand::class, static function () use ($pool): array {
            return $pool->getWorkerInfos();
        });

        $this->messageHandler->subscribe(GetProcessesCommand::class, static function () use ($pool): array {
            return $pool->getProcessInfos();
        });
    }

    public function registerWorker(SupervisedWorker $worker): void
    {
        $workerInfo = $this->pool->addWorker($worker);
        $this->startWorker($workerInfo);
    }

    public function unregisterWorker(int $workerId): void
    {
        if (null === $workerInfo = $this->pool->getWorkerInfoById($workerId)) {
            return;
        }

        $this->pool->removeWorker($workerInfo->id);
        $this->stopWorker($workerInfo);
    }

    public function stop(): Future
    {
        return async(function (): void {
            $futures = [];
            foreach ($this->pool->getWorkerInfos() as $workerInfo) {
                $futures[] = $this->stopWorker($workerInfo);
            }
            await($futures);
        });
    }

    public function reload(): void
    {
        foreach ($this->pool->getProcessInfos() as $processInfo) {
            if ($processInfo->reloadable) {
                \posix_kill($processInfo->pid, $processInfo->external ? SIGTERM : SIGUSR1);
            }
        }
    }

    private function startWorker(WorkerInfo $workerInfo): Future
    {
        return async(function () use ($workerInfo): void {
            $worker = $this->pool->getWorkerById($workerInfo->id);
            \assert($worker !== null);
            while (\count($this->pool->getWorkerPids($workerInfo->id)) < $workerInfo->processCount) {
                if ($this->spawnProcess($worker)) {
                    return;
                }
            }
        });
    }

    private function stopWorker(WorkerInfo $workerInfo): Future
    {
        $future = new DeferredFuture();
        $stopTimeout = $this->stopTimeout;
        $pidsToKill = $this->pool->getWorkerPids($workerInfo->id);

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
        $stopCallbackId = EventLoop::delay($stopTimeout, static function () use ($stopTimeout, &$pidsToKill, $logger, $workerInfo, $future): void {
            // Send SIGKILL signal to all worker processes after timeout
            foreach ($pidsToKill as $pid) {
                \posix_kill($pid, SIGKILL);
                $logger->notice(\sprintf('Worker "%s" [PID:%d] was killed after a %d-second timeout', $workerInfo->name, $pid, $stopTimeout));
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

    private function spawnProcess(SupervisedWorker $worker): bool
    {
        $pid = \pcntl_fork();
        if ($pid > 0) {
            // Master process
            $this->onProcessStart($worker->getId(), $pid);
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
        foreach ($this->pool->getProcessInfos() as $processInfo) {
            $blockTime = $processInfo->external ? 0 : (int) \round((\hrtime(true) - $processInfo->heartbeatTime) * 1e-9);
            if ($processInfo->blocked === false && $blockTime > $this->pool::BLOCK_WARNING_THRESHOLD_SECONDS) {
                $worker = $this->pool->getWorkerInfoByPid($processInfo->pid);
                $this->pool->markAsBlocked($processInfo->pid);
                $messageBus = $this->messageBus;
                EventLoop::defer(static function () use ($messageBus, $processInfo): void {
                    $messageBus->dispatch(new ProcessBlockedEvent($processInfo->pid));
                });
                $this->logger->warning(\sprintf(
                    'Worker "%s" [PID:%d] blocked the event loop for more than %d seconds',
                    $worker->name ?? '',
                    $processInfo->pid,
                    $blockTime,
                ));
            }
        }
    }

    private function onProcessStart(int $workerId, int $pid): void
    {
        $this->pool->addProcess($workerId, $pid);
    }

    private function onProcessStop(int $pid, int $exitCode, int|null $terminationSignal): void
    {
        if (null === $workerInfo = $this->pool->getWorkerInfoByPid($pid)) {
            return;
        }

        $this->pool->removeProcess($pid);

        $messageBus = $this->messageBus;
        EventLoop::queue(static function () use ($messageBus, $pid, $exitCode, $terminationSignal): void {
            $messageBus->dispatch(new ProcessExitEvent($pid, $exitCode, $terminationSignal));
        });

        if ($this->serverStatus === Status::RUNNING) {
            if ($terminationSignal !== null) {
                $this->logger->warning(\sprintf('Worker "%s" [PID:%d] terminated with signal %s (%d)', $workerInfo->name, $pid, strSignal($terminationSignal), $terminationSignal));
            } elseif ($exitCode === 0) {
                $this->logger->info(\sprintf('Worker "%s" [PID:%d] exited with code %d', $workerInfo->name, $pid, $exitCode));
            } elseif ($exitCode === SupervisedWorker::RELOAD_EXIT_CODE && $workerInfo->reloadable) {
                $this->logger->info(\sprintf('Worker "%s" [PID:%d] reloaded', $workerInfo->name, $pid));
            } else {
                $this->logger->warning(\sprintf('Worker "%s" [PID:%d] exited with code %d', $workerInfo->name, $pid, $exitCode));
            }

            if ($workerInfo->status === WorkerInfo::STATUS_RUNNING) {
                // Restart worker
                EventLoop::delay($this->restartDelay, function () use ($workerInfo): void {
                    $workerInfo = $this->pool->getWorkerInfoById($workerInfo->id);
                    if ($this->serverStatus === Status::RUNNING && $workerInfo !== null && $workerInfo->status === WorkerInfo::STATUS_RUNNING) {
                        $worker = $this->pool->getWorkerById($workerInfo->id);
                        \assert($worker !== null);
                        $this->spawnProcess($worker);
                    }
                });
            }
        }
    }
}
