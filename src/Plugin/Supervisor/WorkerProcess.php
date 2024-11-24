<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Plugin\Supervisor;

use Amp\DeferredFuture;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\ReloadStrategyStack;
use PHPStreamServer\Core\Plugin\Supervisor\Message\ProcessHeartbeatEvent;
use PHPStreamServer\Core\Plugin\Supervisor\Message\ProcessSpawnedEvent;
use PHPStreamServer\Core\Plugin\Supervisor\ReloadStrategy\ReloadStrategyInterface;
use PHPStreamServer\Core\Exception\UserChangeException;
use PHPStreamServer\Core\Internal\ErrorHandler;
use PHPStreamServer\Core\MessageBus\Message\CompositeMessage;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\ContainerInterface;
use PHPStreamServer\Core\Worker\LoggerInterface;
use PHPStreamServer\Core\Worker\ProcessUserChange;
use PHPStreamServer\Core\Worker\Status;
use Revolt\EventLoop;
use Revolt\EventLoop\CallbackType;
use Revolt\EventLoop\DriverFactory;
use function PHPStreamServer\Core\getCurrentGroup;
use function PHPStreamServer\Core\getCurrentUser;

class WorkerProcess implements Process
{
    final public const HEARTBEAT_PERIOD = 2;
    final public const RELOAD_EXIT_CODE = 100;
    private const GC_PERIOD = 180;

    use ProcessUserChange;

    private Status $status = Status::SHUTDOWN;
    private int $exitCode = 0;
    public readonly int $id;
    public readonly int $pid;
    public readonly ContainerInterface $container;
    public readonly LoggerInterface $logger;
    public readonly MessageBusInterface $bus;
    private DeferredFuture|null $startingFuture;
    private readonly ReloadStrategyStack $reloadStrategyStack;
    protected readonly \Closure $reloadStrategyTrigger;

    /**
     * @param null|\Closure(self):void $onStart
     * @param null|\Closure(self):void $onStop
     * @param null|\Closure(self):void $onReload
     * @param array<ReloadStrategyInterface> $reloadStrategies
     */
    public function __construct(
        public string $name = 'none',
        public readonly int $count = 1,
        public readonly bool $reloadable = true,
        private string|null $user = null,
        private string|null $group = null,
        private \Closure|null $onStart = null,
        private readonly \Closure|null $onStop = null,
        private readonly \Closure|null $onReload = null,
        private array $reloadStrategies = [],
    ) {
        static $nextId = 0;
        $this->id = ++$nextId;
    }

    /**
     * @internal
     */
    final public function run(ContainerInterface $workerContainer): int
    {
        // some command line SAPIs (e.g. phpdbg) don't have that function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: worker process  %s', Server::NAME, $this->name));
        }

        EventLoop::setDriver((new DriverFactory())->create());

        $this->status = Status::STARTING;
        $this->pid = \posix_getpid();
        $this->container = $workerContainer;
        $this->logger = $workerContainer->getService(LoggerInterface::class);
        $this->bus = $workerContainer->getService(MessageBusInterface::class);

        ErrorHandler::register($this->logger);
        EventLoop::setErrorHandler(function (\Throwable $exception) {
            ErrorHandler::handleException($exception);
            $this->reloadStrategyStack->emitEvent($exception);
        });

        try {
            $this->setUserAndGroup($this->user, $this->group);
        } catch (UserChangeException $e) {
            $this->logger->warning($e->getMessage(), [(new \ReflectionObject($this))->getShortName() => $this->name]);
        }

        EventLoop::onSignal(SIGINT, static fn() => null);
        EventLoop::onSignal(SIGTERM, fn() => $this->stop());
        EventLoop::onSignal(SIGUSR1, fn() => $this->reload());

        // Force run garbage collection periodically
        EventLoop::repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
        });

        $this->reloadStrategyStack = new ReloadStrategyStack($this->reload(...), $this->reloadStrategies);
        $this->reloadStrategyTrigger = \Closure::fromCallable($this->reloadStrategyStack);
        unset($this->reloadStrategies);

        $heartbeatEvent = function (): ProcessHeartbeatEvent {
            return new ProcessHeartbeatEvent(
                pid: $this->pid,
                memory: \memory_get_usage(),
                time: \hrtime(true),
            );
        };

        $this->startingFuture = new DeferredFuture();

        EventLoop::repeat(self::HEARTBEAT_PERIOD, function () use ($heartbeatEvent) {
            $this->bus->dispatch($heartbeatEvent());
        });

        EventLoop::queue(function () use ($heartbeatEvent): void {
            $this->bus->dispatch(new CompositeMessage([
                new ProcessSpawnedEvent(
                    workerId: $this->id,
                    pid: $this->pid,
                    user: $this->getUser(),
                    name: $this->name,
                    reloadable: $this->reloadable,
                    startedAt: new \DateTimeImmutable('now'),
                ),
                $heartbeatEvent(),
            ]))->await();

            if ($this->onStart !== null) {
                EventLoop::queue(function () {
                    ($this->onStart)($this);
                });
            }
            $this->status = Status::RUNNING;
            $this->startingFuture->complete();
            $this->startingFuture = null;
        });

        EventLoop::run();

        return $this->exitCode;
    }

    /**
     * @return list<class-string<Plugin>>
     */
    static public function handleBy(): array
    {
        return [SupervisorPlugin::class];
    }

    final public function getUser(): string
    {
        return $this->user ?? getCurrentUser();
    }

    final public function getGroup(): string
    {
        return $this->group ?? getCurrentGroup();
    }

    public function stop(int $code = 0): void
    {
        if ($this->status !== Status::STARTING && $this->status !== Status::RUNNING) {
            return;
        }

        $this->status = Status::STOPPING;
        $this->exitCode = $code;

        EventLoop::defer(function (): void {
            $this->startingFuture?->getFuture()->await();
            if ($this->onStop !== null) {
                ($this->onStop)($this);
            }
            $this->gracefulStop();
        });
    }

    public function reload(): void
    {
        if (!$this->reloadable) {
            return;
        }

        if ($this->status !== Status::STARTING && $this->status !== Status::RUNNING) {
            return;
        }

        $this->status = Status::STOPPING;
        $this->exitCode = self::RELOAD_EXIT_CODE;

        EventLoop::defer(function (): void {
            $this->startingFuture?->getFuture()->await();
            if ($this->onReload !== null) {
                ($this->onReload)($this);
            }
            $this->gracefulStop();
        });
    }

    private function gracefulStop(): void
    {
        foreach (EventLoop::getIdentifiers() as $identifier) {
            $type = EventLoop::getType($identifier);
            if (\in_array($type, [CallbackType::Repeat, CallbackType::Signal])) {
                EventLoop::disable($identifier);
            }
            if (\in_array($type, [CallbackType::Readable, CallbackType::Writable])) {
                EventLoop::unreference($identifier);
            }
        }
    }

    public function addReloadStrategy(ReloadStrategyInterface ...$reloadStrategies): void
    {
        $this->reloadStrategyStack->addReloadStrategy(...$reloadStrategies);
    }
}
