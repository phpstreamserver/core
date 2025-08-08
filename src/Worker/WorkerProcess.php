<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Worker;

use Amp\DeferredFuture;
use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Exception\UserChangeException;
use PHPStreamServer\Core\Internal\ErrorHandler;
use PHPStreamServer\Core\Internal\ProcessUserChange;
use PHPStreamServer\Core\Internal\Status;
use PHPStreamServer\Core\Logger\LoggerInterface;
use PHPStreamServer\Core\Message\CompositeMessage;
use PHPStreamServer\Core\Message\ProcessHeartbeatEvent;
use PHPStreamServer\Core\Message\ProcessSpawnedEvent;
use PHPStreamServer\Core\MessageBus\GracefulMessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\Internal\ReloadStrategyStack;
use PHPStreamServer\Core\Plugin\Supervisor\SupervisorPlugin;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\ReloadStrategy\ReloadStrategy;
use PHPStreamServer\Core\Server;
use Revolt\EventLoop;
use Revolt\EventLoop\DriverFactory;

use function PHPStreamServer\Core\getCurrentGroup;
use function PHPStreamServer\Core\getCurrentUser;

class WorkerProcess implements Process
{
    use ProcessUserChange;

    final public const HEARTBEAT_PERIOD = 2;
    final public const RELOAD_EXIT_CODE = 100;
    private const GC_PERIOD = 120;

    private Status $status = Status::SHUTDOWN;
    private int $exitCode = 0;
    public readonly int $id;
    public readonly int $pid;
    public readonly string $name;
    public readonly ContainerInterface $container;
    public readonly LoggerInterface $logger;
    public readonly GracefulMessageBusInterface $bus;
    private DeferredFuture|null $startingFuture;
    private readonly ReloadStrategyStack $reloadStrategyStack;

    /**
     * @var array<\Closure(static): void>
     */
    private array $onStartCallbacks = [];

    /**
     * @var array<\Closure(static): void>
     */
    private array $onStopCallbacks = [];

    /**
     * @var array<\Closure(static): void>
     */
    private array $onReloadCallbacks = [];

    /**
     * @param null|\Closure(static):void $onStart
     * @param null|\Closure(static):void $onStop
     * @param null|\Closure(static):void $onReload
     * @param array<ReloadStrategy> $reloadStrategies
     */
    public function __construct(
        string $name = '',
        public readonly int $count = 1,
        public readonly bool $reloadable = true,
        private string|null $user = null,
        private string|null $group = null,
        \Closure|null $onStart = null,
        \Closure|null $onStop = null,
        \Closure|null $onReload = null,
        private array $reloadStrategies = [],
    ) {
        static $nextId = 0;
        $this->id = ++$nextId;

        if ($name === '') {
            $this->name = 'worker_' . $this->id;
        } else {
            $this->name = $name;
        }

        if ($onStart !== null) {
            $this->onStart($onStart);
        }

        if ($onStop !== null) {
            $this->onStop($onStop);
        }

        if ($onReload !== null) {
            $this->onReload($onReload);
        }
    }

    /**
     * @internal
     */
    final public function run(ContainerInterface $workerContainer): int
    {
        // some command line SAPIs (e.g., phpdbg) don't have that function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: worker process  %s', Server::NAME, $this->name));
        }

        EventLoop::setDriver((new DriverFactory())->create());

        $this->status = Status::STARTING;
        $this->pid = \posix_getpid();
        $this->container = $workerContainer;
        $this->logger = $workerContainer->getService(LoggerInterface::class);
        /** @var GracefulMessageBusInterface */
        $this->bus = $workerContainer->getService(MessageBusInterface::class);

        try {
            $this->setUserAndGroup($this->user, $this->group);
        } catch (UserChangeException $e) {
            $this->logger->warning($e->getMessage(), [(new \ReflectionObject($this))->getShortName() => $this->name]);
        }

        $reloadStrategyStack = new ReloadStrategyStack($this->reload(...), $this->reloadStrategies);
        $this->reloadStrategyStack = $reloadStrategyStack;
        unset($this->reloadStrategies);

        $this->startingFuture = new DeferredFuture();
        $this->container->setService('reload_strategy_emitter', $this->reloadStrategyStack->emitEvent(...));

        ErrorHandler::register($this->logger);
        EventLoop::setErrorHandler(static function (\Throwable $exception) use ($reloadStrategyStack): void {
            ErrorHandler::handleException($exception);
            $reloadStrategyStack->emitEvent($exception);
        });

        EventLoop::onSignal(SIGINT, static fn() => null);
        EventLoop::onSignal(SIGTERM, fn() => $this->stop());
        EventLoop::onSignal(SIGUSR1, fn() => $this->reload());

        // Force run garbage collection periodically
        EventLoop::repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
            \clearstatcache();
        });

        $bus = $this->bus;
        $pid = $this->pid;
        $heartbeatEvent = static fn(): ProcessHeartbeatEvent => new ProcessHeartbeatEvent($pid, \memory_get_usage(), \hrtime(true));
        EventLoop::repeat(self::HEARTBEAT_PERIOD, static function () use ($bus, $heartbeatEvent): void {
            $bus->dispatch($heartbeatEvent());
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

            EventLoop::queue(function (): void {
                try {
                    foreach ($this->onStartCallbacks as $onStartCallback) {
                        $onStartCallback($this);
                    }
                } catch (\CompileError $e) {
                    $this->onStartCallbacks = [];
                    $this->onStopCallbacks = [];
                    $this->onReloadCallbacks = [];
                    throw $e;
                }
            });

            $this->status = Status::RUNNING;
            $this->startingFuture?->complete();
            $this->startingFuture = null;
        });

        EventLoop::run();

        return $this->exitCode;
    }

    /**
     * @return list<class-string<Plugin>>
     */
    public static function handledBy(): array
    {
        return [SupervisorPlugin::class];
    }

    public function getPid(): int
    {
        return $this->pid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    final public function getUser(): string
    {
        return $this->user ?? getCurrentUser();
    }

    final public function getGroup(): string
    {
        return $this->group ?? getCurrentGroup();
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function getMessageBus(): MessageBusInterface
    {
        return $this->bus;
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
            foreach ($this->onStopCallbacks as $onStopCallback) {
                $onStopCallback($this);
            }
            $this->bus->stop()->await();
            EventLoop::getDriver()->stop();
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
            foreach ($this->onReloadCallbacks as $onReloadCallback) {
                $onReloadCallback($this);
            }
            $this->bus->stop()->await();
            EventLoop::getDriver()->stop();
        });
    }

    public function addReloadStrategy(ReloadStrategy ...$reloadStrategies): void
    {
        $this->reloadStrategyStack->addReloadStrategy(...$reloadStrategies);
    }

    /**
     * @param \Closure(static): void $onStart
     */
    public function onStart(\Closure $onStart, int $priority = 0): void
    {
        $this->onStartCallbacks[$priority . \uniqid()] = $onStart;
        \ksort($this->onStartCallbacks, SORT_NUMERIC);
    }

    /**
     * @param \Closure(static): void $onStop
     */
    public function onStop(\Closure $onStop, int $priority = 0): void
    {
        $this->onStopCallbacks[$priority . \uniqid()] = $onStop;
        \ksort($this->onStopCallbacks, SORT_NUMERIC);
    }

    /**
     * @param \Closure(static): void $onReload
     */
    public function onReload(\Closure $onReload, int $priority = 0): void
    {
        $this->onReloadCallbacks[$priority . \uniqid()] = $onReload;
        \ksort($this->onReloadCallbacks, SORT_NUMERIC);
    }
}
