<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor\Internal;

use Amp\DeferredFuture;
use Amp\Future;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\SIGCHLDHandler;
use PHPStreamServer\Core\Message\ProcessBlockedEvent;
use PHPStreamServer\Core\Message\ProcessDetachedEvent;
use PHPStreamServer\Core\Message\ProcessExitEvent;
use PHPStreamServer\Core\Message\ProcessHeartbeatEvent;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Worker\Status;
use PHPStreamServer\Core\WorkerProcess;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Suspension;
use function Amp\weakClosure;

/**
 * @internal
 */
final class Supervisor
{
    public MessageHandlerInterface $messageHandler;
    public MessageBusInterface $messageBus;
    private WorkerPool $workerPool;
    private LoggerInterface $logger;
    private Suspension $suspension;
    private DeferredFuture $stopFuture;

    public function __construct(
        private Status &$status,
        private readonly int $stopTimeout,
        private readonly float $restartDelay,
    ) {
        $this->workerPool = new WorkerPool();
    }

    public function addWorker(WorkerProcess $process): void
    {
        $this->workerPool->registerWorker($process);
    }

    public function start(Suspension $suspension, LoggerInterface &$logger, MessageHandlerInterface &$messageHandler, MessageBusInterface &$messageBus): void
    {
        $this->suspension = $suspension;
        $this->logger = &$logger;
        $this->messageHandler = &$messageHandler;
        $this->messageBus = &$messageBus;

        SIGCHLDHandler::onChildProcessExit(weakClosure(function (int $pid, int $exitCode) {
            if (null !== $worker = $this->workerPool->getWorkerByPid($pid)) {
                $this->onProcessStop($worker, $pid, $exitCode);
            }
        }));

        EventLoop::repeat(WorkerProcess::HEARTBEAT_PERIOD, $this->monitorWorkerStatus(...));

        EventLoop::defer(function () {
            $this->messageHandler->subscribe(ProcessDetachedEvent::class, weakClosure(function (ProcessDetachedEvent $message): void {
                $this->workerPool->markAsDetached($message->pid);
            }));

            $this->messageHandler->subscribe(ProcessHeartbeatEvent::class, weakClosure(function (ProcessHeartbeatEvent $message): void {
                $this->workerPool->markAsHealthy($message->pid, $message->time);
            }));
        });

        $this->spawnProcesses();
    }

    private function spawnProcesses(): void
    {
        EventLoop::defer(function (): void {
            foreach ($this->workerPool->getRegisteredWorkers() as $worker) {
                while (\iterator_count($this->workerPool->getAliveWorkerPids($worker)) < $worker->count) {
                    if ($this->spawnProcess($worker)) {
                        return;
                    }
                }
            }
        });
    }

    private function spawnProcess(WorkerProcess $process): bool
    {
        $pid = \pcntl_fork();
        if ($pid > 0) {
            // Master process
            $this->onProcessStart($process, $pid);
            return false;
        } elseif ($pid === 0) {
            // Child process
            $this->suspension->resume($process);
            return true;
        } else {
            throw new PHPStreamServerException('fork fail');
        }
    }

    private function monitorWorkerStatus(): void
    {
        foreach ($this->workerPool->getProcesses() as $worker => $process) {
            $blockTime = $process->detached ? 0 : (int) \round((\hrtime(true) - $process->time) / 1000000000);
            if ($process->blocked === false && $blockTime > $this->workerPool::BLOCK_WARNING_TRESHOLD) {
                $this->workerPool->markAsBlocked($process->pid);
                EventLoop::defer(function () use ($process): void {
                    $this->messageBus->dispatch(new ProcessBlockedEvent($process->pid));
                });
                $this->logger->warning(\sprintf(
                    'Worker %s[pid:%d] blocked event loop for more than %s seconds',
                    $worker->name,
                    $process->pid,
                    $blockTime,
                ));
            }
        }
    }

    private function onProcessStart(WorkerProcess $process, int $pid): void
    {
        $this->workerPool->addChild($process, $pid);
    }

    private function onProcessStop(WorkerProcess $process, int $pid, int $exitCode): void
    {
        $this->workerPool->markAsDeleted($pid);

        EventLoop::defer(function () use ($pid, $exitCode): void {
            $this->messageBus->dispatch(new ProcessExitEvent($pid, $exitCode));
        });

        if ($this->status === Status::RUNNING) {
            if ($exitCode === 0) {
                $this->logger->info(\sprintf('Worker %s[pid:%d] exit with code %s', $process->name, $pid, $exitCode));
            } elseif ($exitCode === $process::RELOAD_EXIT_CODE && $process->reloadable) {
                $this->logger->info(\sprintf('Worker %s[pid:%d] reloaded', $process->name, $pid));
            } else {
                $this->logger->warning(\sprintf('Worker %s[pid:%d] exit with code %s', $process->name, $pid, $exitCode));
            }

            // Restart worker
            EventLoop::delay(\max($this->restartDelay, 0), function () use ($process): void {
                $this->spawnProcess($process);
            });
        } else {
            if ($this->workerPool->getProcessesCount() === 0) {
                // All processes are stopped now
                $this->stopFuture->complete();
            }
        }
    }

    public function stop(): Future
    {
        $this->stopFuture = new DeferredFuture();

        foreach ($this->workerPool->getProcesses() as $process) {
            \posix_kill($process->pid, SIGTERM);
        }

        if ($this->workerPool->getWorkerCount() === 0) {
            $this->stopFuture->complete();
        } else {
            $stopCallbackId = EventLoop::delay($this->stopTimeout, function (): void {
                // Send SIGKILL signal to all child processes ater timeout
                foreach ($this->workerPool->getProcesses() as $worker => $process) {
                    \posix_kill($process->pid, SIGKILL);
                    $this->logger->notice(\sprintf('Worker %s[pid:%s] killed after %ss timeout', $worker->name, $process->pid, $this->stopTimeout));
                }
                $this->stopFuture->complete();
            });

            $this->stopFuture->getFuture()->finally(static function () use ($stopCallbackId) {
                EventLoop::cancel($stopCallbackId);
            });
        }

        return $this->stopFuture->getFuture();
    }

    public function reload(): void
    {
        foreach ($this->workerPool->getProcesses() as $process) {
            if ($process->reloadable) {
                \posix_kill($process->pid, $process->detached ? SIGTERM : SIGUSR1);
            }
        }
    }
}
