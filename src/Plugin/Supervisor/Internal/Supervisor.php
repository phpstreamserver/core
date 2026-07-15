<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\SIGCHLDHandler;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\Message\ProcessBlockedEvent;
use PHPStreamServer\Core\Message\ProcessDetachedEvent;
use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\Message\ProcessHeartbeatEvent;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Worker\WorkerProcess;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;

use function PHPStreamServer\Core\generateWorkerId;

/**
 * @internal
 */
final class Supervisor
{
    private bool $running = false;
    public MessageHandlerInterface $messageHandler;
    public MessageBusInterface $messageBus;
    private WorkerPool $pool;
    private LoggerInterface $logger;
    private Suspension $suspension;
    private DeferredFuture $stopFuture;

    public function __construct(
        private Status &$status,
        private readonly int $stopTimeout,
        private readonly float $restartDelay,
    ) {
        $this->pool = new WorkerPool();
    }

    public function registerWorker(WorkerProcess $worker): void
    {
        $workerId = generateWorkerId();
        $worker->assignId($workerId);

        $this->pool->registerWorker($worker);

        if ($this->running) {
            $this->logger->info(\sprintf('Worker "%s" was registered in supervisor with %d processes', $worker->name, $worker->count));
            $this->startWorkerProcess($worker);
        }
    }

    public function start(Suspension $suspension, LoggerInterface &$logger, MessageHandlerInterface &$messageHandler, MessageBusInterface &$messageBus): void
    {
        $this->running = true;
        $this->suspension = $suspension;
        $this->logger = &$logger;
        $this->messageHandler = &$messageHandler;
        $this->messageBus = &$messageBus;

        SIGCHLDHandler::onChildProcessExit($this->onProcessStop(...));

        EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $this->monitorWorkerStatus(...));

        $workerPool = $this->pool;
        EventLoop::defer(static function () use ($workerPool, &$messageHandler): void {
            $messageHandler->subscribe(ProcessDetachedEvent::class, static function (ProcessDetachedEvent $message) use ($workerPool): void {
                $workerPool->markAsDetached($message->pid);
            });

            $messageHandler->subscribe(ProcessHeartbeatEvent::class, static function (ProcessHeartbeatEvent $message) use ($workerPool): void {
                $workerPool->markAsHealthy($message->pid, $message->time);
            });
        });

        $this->startAllWorkersProcesses();
    }

    private function startAllWorkersProcesses(): void
    {
        EventLoop::defer(function (): void {
            foreach ($this->pool->getRegisteredWorkers() as $worker) {
                while (\count($this->pool->getWorkerPids($worker)) < $worker->count) {
                    if ($this->spawnProcess($worker)) {
                        return;
                    }
                }
            }
        });
    }

    private function startWorkerProcess(WorkerProcess $worker): void
    {
        EventLoop::defer(function () use ($worker): void {
            while (\count($this->pool->getWorkerPids($worker)) < $worker->count) {
                if ($this->spawnProcess($worker)) {
                    return;
                }
            }
        });
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
        foreach ($this->pool->getProcessesStatuses() as $worker => $process) {
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
        $this->pool->addChild($worker, $pid);
    }

    private function onProcessStop(int $pid, int $exitCode): void
    {
        if (null === $worker = $this->pool->getWorkerByPid($pid)) {
            return;
        }

        $this->pool->removeChild($pid);
        $messageBus = $this->messageBus;

        EventLoop::queue(static function () use ($messageBus, $pid, $exitCode): void {
            $messageBus->dispatch(new ProcessExitEvent($pid, $exitCode));
        });

        if ($this->status === Status::RUNNING) {
            if ($exitCode === 0) {
                $this->logger->info(\sprintf('Worker "%s"[pid:%d] exited with code %s', $worker->name, $pid, $exitCode));
            } elseif ($exitCode === $worker::RELOAD_EXIT_CODE && $worker->reloadable) {
                $this->logger->info(\sprintf('Worker "%s"[pid:%d] reloaded', $worker->name, $pid));
            } else {
                $this->logger->warning(\sprintf('Worker "%s"[pid:%d] exited with code %s', $worker->name, $pid, $exitCode));
            }

            // Restart worker
            EventLoop::delay(\max($this->restartDelay, 0), function () use ($worker): void {
                $this->spawnProcess($worker);
            });
        } elseif ($this->pool->getProcessesCount() === 0) {
            // All processes are stopped now
            $this->stopFuture->complete();
        }
    }

    public function stop(): Future
    {
        $this->stopFuture = new DeferredFuture();

        foreach ($this->pool->getProcessesStatuses() as $process) {
            \posix_kill($process->pid, SIGTERM);
        }

        if ($this->pool->getWorkerCount() === 0) {
            $this->stopFuture->complete();
        } else {
            $stopTimeout = $this->stopTimeout;
            $workerPool = $this->pool;
            $logger = $this->logger;
            $stopFuture = $this->stopFuture;
            $stopCallbackId = EventLoop::delay($stopTimeout, static function () use ($stopTimeout, $workerPool, $logger, $stopFuture): void {
                // Send SIGKILL signal to all child processes after timeout
                foreach ($workerPool->getProcessesStatuses() as $worker => $processStatus) {
                    \posix_kill($processStatus->pid, SIGKILL);
                    $logger->notice(\sprintf('Worker "%s"[pid:%s] killed after %ss timeout', $worker->name, $processStatus->pid, $stopTimeout));
                }
                $stopFuture->complete();
            });

            $this->stopFuture->getFuture()->finally(static function () use ($stopCallbackId) {
                EventLoop::cancel($stopCallbackId);
            });
        }

        return $this->stopFuture->getFuture();
    }

    public function reload(): void
    {
        foreach ($this->pool->getProcessesStatuses() as $process) {
            if ($process->reloadable) {
                \posix_kill($process->pid, $process->detached ? SIGTERM : SIGUSR1);
            }
        }
    }
}
