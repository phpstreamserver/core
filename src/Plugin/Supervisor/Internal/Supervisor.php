<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\SIGCHLDHandler;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\Message\GetSupervisorStatusCommand;
use PHPStreamServer\Core\Message\ProcessBlockedEvent;
use PHPStreamServer\Core\Message\ProcessDetachedEvent;
use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\Message\ProcessHeartbeatEvent;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Supervisor\Status\SupervisorStatus;
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
    private bool $running = false;
    private LoggerInterface $logger;
    public MessageBusInterface $messageBus;
    public MessageHandlerInterface $messageHandler;
    private WorkerPool $pool;
    public readonly SupervisorStatus $supervisorStatus;
    private Suspension $suspension;
    private readonly float $restartDelay;

    public function __construct(
        private Status &$serverStatus,
        private readonly int $stopTimeout,
        float $restartDelay,
    ) {
        $this->pool = new WorkerPool();
        $this->supervisorStatus = new SupervisorStatus();
        $this->restartDelay = \max($restartDelay, 0);
    }

    public function registerWorker(WorkerProcess $worker): void
    {
        $workerId = generateWorkerId();
        $worker->assignId($workerId);

        $this->pool->registerWorker($worker);
        $this->supervisorStatus->addWorker($worker);

        if ($this->running) {
            $this->logger->info(\sprintf('Worker "%s" was registered in supervisor with %d processes', $worker->name, $worker->count));
            $this->startWorker($worker);
        }
    }

    public function unRegisterWorker(int $workerId): void
    {
        if (null === $worker = $this->pool->getWorkerById($workerId)) {
            return;
        }

        $this->pool->unregisterWorker($worker->id);
        $this->supervisorStatus->removeWorker($worker);
        $this->stopWorker($worker);
    }

    public function start(Suspension $suspension, LoggerInterface &$logger, MessageBusInterface &$messageBus, MessageHandlerInterface &$messageHandler): void
    {
        $this->running = true;
        $this->suspension = $suspension;
        $this->logger = &$logger;
        $this->messageBus = &$messageBus;
        $this->messageHandler = &$messageHandler;

        SIGCHLDHandler::onChildProcessExit($this->onProcessStop(...));
        EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $this->monitorWorkerStatus(...));

        $this->supervisorStatus->subscribeToWorkerMessages($this->messageHandler);

        $workerPool = $this->pool;
        $supervisorStatus = $this->supervisorStatus;

        $this->messageHandler->subscribe(ProcessDetachedEvent::class, static function (ProcessDetachedEvent $message) use ($workerPool): void {
            $workerPool->markAsDetached($message->pid);
        });

        $this->messageHandler->subscribe(ProcessHeartbeatEvent::class, static function (ProcessHeartbeatEvent $message) use ($workerPool): void {
            $workerPool->markAsHealthy($message->pid, $message->time);
        });

        $this->messageHandler->subscribe(GetSupervisorStatusCommand::class, static function () use ($supervisorStatus): SupervisorStatus {
            return $supervisorStatus;
        });

        $this->startAllWorkers();
    }

    public function stop(): Future
    {
        return async(function (): void {
            $futures = [];
            foreach ($this->pool->getRegisteredWorkers() as $worker) {
                $futures[] = $this->stopWorker($worker);
            }
            await($futures);
        });
    }

    public function reload(): void
    {
        foreach ($this->pool->getAllProcessStatuses() as $process) {
            if ($process->reloadable) {
                \posix_kill($process->pid, $process->detached ? SIGTERM : SIGUSR1);
            }
        }
    }

    private function startAllWorkers(): void
    {
        EventLoop::queue(function (): void {
            foreach ($this->pool->getRegisteredWorkers() as $worker) {
                while (\count($this->pool->getWorkerPids($worker->id)) < $worker->count) {
                    if ($this->spawnProcess($worker)) {
                        return;
                    }
                }
            }
        });
    }

    private function startWorker(WorkerProcess $worker): Future
    {
        return async(function () use ($worker): void {
            while (\count($this->pool->getWorkerPids($worker->id)) < $worker->count) {
                if ($this->spawnProcess($worker)) {
                    return;
                }
            }
        });
    }

    private function stopWorker(WorkerProcess $worker): Future
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
                $logger->notice(\sprintf('Worker "%s"[pid:%s] killed after %ss timeout', $worker->name, $pid, $stopTimeout));
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
            $this->onProcessStart($worker, $pid);
            return false;
        } elseif ($pid === 0) {
            // Child process
            $this->suspension->resume($worker);
            return true;
        } else {
            throw new PHPStreamServerException('fork fail');
        }
    }

    private function monitorWorkerStatus(): void
    {
        foreach ($this->pool->getAllProcessStatuses() as $worker => $process) {
            $blockTime = $process->detached ? 0 : (int) \round((\hrtime(true) - $process->time) * 1e-9);
            if ($process->blocked === false && $blockTime > $this->pool::BLOCK_WARNING_TRESHOLD) {
                $this->pool->markAsBlocked($process->pid);
                $messageBus = $this->messageBus;
                EventLoop::defer(static function () use ($messageBus, $process): void {
                    $messageBus->dispatch(new ProcessBlockedEvent($process->pid));
                });
                $this->logger->warning(\sprintf(
                    'Worker "%s"[pid:%d] blocked event loop for more than %s seconds',
                    $worker->name,
                    $process->pid,
                    $blockTime,
                ));
            }
        }
    }

    private function onProcessStart(WorkerProcess $worker, int $pid): void
    {
        $this->pool->addChild($worker->id, $pid);
    }

    private function onProcessStop(int $pid, int $exitCode): void
    {
        if (null === $worker = $this->pool->getWorkerByPid($pid)) {
            return;
        }

        $isWorkerUnloading = $this->pool->isUnloading($worker->id);
        $this->pool->removeChild($pid);

        $messageBus = $this->messageBus;
        EventLoop::queue(static function () use ($messageBus, $pid, $exitCode): void {
            $messageBus->dispatch(new ProcessExitEvent($pid, $exitCode));
        });

        if ($exitCode === 0) {
            $this->logger->info(\sprintf('Worker "%s"[pid:%d] exited with code %s', $worker->name, $pid, $exitCode));
        } elseif ($exitCode === $worker::RELOAD_EXIT_CODE && $worker->reloadable) {
            $this->logger->info(\sprintf('Worker "%s"[pid:%d] reloaded', $worker->name, $pid));
        } else {
            $this->logger->warning(\sprintf('Worker "%s"[pid:%d] exited with code %s', $worker->name, $pid, $exitCode));
        }

        if ($this->serverStatus === Status::RUNNING && !$isWorkerUnloading) {
            // Restart worker
            EventLoop::delay($this->restartDelay, function () use ($worker): void {
                $this->spawnProcess($worker);
            });
        }
    }
}
