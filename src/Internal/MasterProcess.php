<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Command\RegisterWorkerCommand;
use PHPStreamServer\Core\Command\ReloadServerCommand;
use PHPStreamServer\Core\Command\StopServerCommand;
use PHPStreamServer\Core\Command\UnregisterWorkerCommand;
use PHPStreamServer\Core\Console\StdoutHandler;
use PHPStreamServer\Core\ContainerInterface;
use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\Logger\ConsoleLogger;
use PHPStreamServer\Core\Internal\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\Internal\MessageBus\SocketFileMessageHandler;
use PHPStreamServer\Core\LoggerInterface;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Runtime\ErrorHandler;
use PHPStreamServer\Core\Runtime\SIGCHLDHandler;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\WorkerInterface;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Suspension;

use function Amp\async;
use function Amp\Future\awaitAll;
use function PHPStreamServer\Core\getStartFile;
use function PHPStreamServer\Core\isRunning;

/**
 * @internal
 */
final class MasterProcess
{
    private const GC_PERIOD = 300;
    private const DAEMON_WAIT_TIMEOUT_SECONDS = 10;

    private static bool $registered = false;
    private Suspension $suspension;
    private Status $status = Status::SHUTDOWN;
    private MessageHandlerInterface $messageHandler;
    private LoggerInterface $logger;
    private ContainerInterface $masterContainer;
    private ContainerInterface $workerContainer;

    /**
     * @var resource|null
     */
    private mixed $daemonStartupSocket = null;

    /**
     * @var array<class-string<Plugin>, Plugin>
     */
    private array $plugins = [];

    /**
     * @param array<Plugin> $plugins
     * @param array<WorkerInterface> $workers
     */
    public function __construct(
        private readonly string $pidFile,
        private readonly string $socketFile,
        array $plugins,
        private array $workers,
    ) {
        if (!\in_array(PHP_SAPI, ['cli', 'phpdbg', 'micro'], true)) {
            throw new PHPStreamServerException('Can only run in CLI mode');
        }

        if (self::$registered) {
            throw new PHPStreamServerException('The server can only be instantiated once');
        }

        self::$registered = true;

        // Init event loop
        EventLoop::setDriver(new StreamSelectDriver());
        $this->suspension = EventLoop::getDriver()->getSuspension();

        // Init master container
        $this->masterContainer = new Container();
        $this->masterContainer->setService(Suspension::class, $this->suspension);
        $this->masterContainer->registerService(MessageHandlerInterface::class, fn() => new SocketFileMessageHandler($this->socketFile));
        $this->masterContainer->setAlias(MessageBusInterface::class, MessageHandlerInterface::class);
        $this->masterContainer->registerService(LoggerInterface::class, $defaultLogger = static fn() => new ConsoleLogger());
        $this->masterContainer->setParameter('pid_file', $this->pidFile);
        $this->masterContainer->setParameter('socket_file', $this->socketFile);

        // Init worker container
        $this->workerContainer = new Container();
        $this->workerContainer->registerService(MessageBusInterface::class, fn() => new SocketFileMessageBus($this->socketFile));
        $this->workerContainer->registerService(LoggerInterface::class, static fn() => $defaultLogger()->withChannel('worker'));
        $this->workerContainer->setAlias(PsrLoggerInterface::class, LoggerInterface::class);
        $this->workerContainer->setParameter('pid_file', $this->pidFile);
        $this->workerContainer->setParameter('socket_file', $this->socketFile);
        $this->workerContainer->setService(ContainerInterface::class, $this->workerContainer);
        $this->workerContainer->setAlias(PsrContainerInterface::class, ContainerInterface::class);

        // Init plugins
        foreach ($plugins as $plugin) {
            if (isset($this->plugins[$plugin::class])) {
                throw new PHPStreamServerException(\sprintf('Plugin "%s" is already registered', $plugin::class));
            }
            $this->plugins[$plugin::class] = $plugin;
            $plugin->init($this->masterContainer, $this->workerContainer, $this->status);
        }
        unset($plugins);
    }

    public function run(bool $daemonize): int
    {
        if ($this->status === Status::RUNNING || isRunning($this->pidFile)) {
            throw new PHPStreamServerException(\sprintf('%s is already running', Server::NAME));
        }

        if ($daemonize && (null !== $isDaemonStarted = $this->doDaemonize())) {
            // Runs in caller process
            return $isDaemonStarted ? 0 : 1;
        } elseif ($daemonize) {
            // Runs in daemonized master process
            StdoutHandler::suppress();
        }

        // Master process context
        $this->start();
        $ret = $this->suspension->suspend();

        // Child process context
        if ($ret instanceof WorkerInterface) {
            $this->free();
            exit($ret->run($this->workerContainer));
        }

        // Master process shutdown
        \assert(\is_int($ret));
        $this->onMasterShutdown();
        return $ret;
    }

    /**
     * Forks the master process to run it as a daemon.
     *
     * @return bool|null true if daemon startup succeeded, false if it failed, or null in the daemonized child process
     */
    private function doDaemonize(): bool|null
    {
        $sockets = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($sockets === false) {
            throw new PHPStreamServerException('Daemon startup socket creation failed');
        }

        [$parentSocket, $daemonSocket] = $sockets;
        $pid = \pcntl_fork();

        if ($pid === -1) {
            \fclose($parentSocket);
            \fclose($daemonSocket);
            throw new PHPStreamServerException('Fork failed');
        }

        if ($pid > 0) {
            // Original calling process
            \fclose($daemonSocket);
            \stream_set_timeout($parentSocket, self::DAEMON_WAIT_TIMEOUT_SECONDS);
            $result = \fread($parentSocket, 1) ?: "\x01";
            $isTimedOut = \stream_get_meta_data($parentSocket)['timed_out'];
            \fclose($parentSocket);

            if ($isTimedOut) {
                \posix_kill(-$pid, SIGKILL);
                \pcntl_waitpid($pid, $status);
                return false;
            }

            return $result === "\x00";
        }

        // Daemon process
        \fclose($parentSocket);
        $this->daemonStartupSocket = $daemonSocket;

        if (\posix_setsid() === -1) {
            $this->reportDaemonStartup(false);
            throw new PHPStreamServerException('Setsid failed');
        }

        return null;
    }

    private function reportDaemonStartup(bool $success): void
    {
        if ($this->daemonStartupSocket !== null) {
            $socket = $this->daemonStartupSocket;
            $this->daemonStartupSocket = null;
            \fwrite($socket, $success ? "\x00" : "\x01");
            \fclose($socket);
        }
    }

    /**
     * Runs in master process
     */
    private function start(): void
    {
        $startFile = getStartFile();

        // Some command-line SAPIs (e.g., phpdbg) do not provide this function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: master process  start_file=%s', Server::NAME, $startFile));
        }

        $this->status = Status::STARTING;
        $this->saveMasterPid();

        $this->logger = &$this->masterContainer->getService(LoggerInterface::class);
        $this->messageHandler = &$this->masterContainer->getService(MessageHandlerInterface::class);

        $this->masterContainer->setParameter('pid', \posix_getpid());

        $stopCallback = fn(): null => $this->stop();
        $reloadCallback = fn(): null => $this->reload();

        EventLoop::onSignal(SIGINT, static function () use ($stopCallback): void {
            StdoutHandler::clearCurrentLine();
            $stopCallback();
        });
        EventLoop::onSignal(SIGTERM, $stopCallback);
        EventLoop::onSignal(SIGHUP, $stopCallback);
        EventLoop::onSignal(SIGQUIT, $stopCallback);
        EventLoop::onSignal(SIGUSR1, $reloadCallback);

        // Run garbage collection periodically
        EventLoop::repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
            \clearstatcache();
        });

        ErrorHandler::register($this->logger);
        EventLoop::setErrorHandler(ErrorHandler::handleException(...));
        EventLoop::defer(fn () => ErrorHandler::swapLogger($this->logger));

        $this->messageHandler->subscribe(StopServerCommand::class, function (StopServerCommand $command): void {
            $this->stop($command->code);
        });

        $this->messageHandler->subscribe(ReloadServerCommand::class, function (ReloadServerCommand $command): void {
            $this->reload();
        });

        $this->messageHandler->subscribe(RegisterWorkerCommand::class, function (RegisterWorkerCommand $command): int {
            $this->registerWorker($command->worker);

            return $command->worker->getId();
        });

        $this->messageHandler->subscribe(UnregisterWorkerCommand::class, function (UnregisterWorkerCommand $command): void {
            $this->unregisterWorker($command->workerId);
        });

        EventLoop::queue(function (): void {
            [$onStartExceptions] = awaitAll(\array_map(static fn(Plugin $plugin) => async(static fn() => $plugin->onStart()), $this->plugins));
            foreach ($onStartExceptions as $pluginClass => $exception) {
                $this->logger->critical(\sprintf('%s::onStart() failed: %s', $pluginClass, $exception->getMessage()), ['exception' => $exception]);
            }

            if ($onStartExceptions !== []) {
                $this->reportDaemonStartup(false);
                $this->stop(1);
                return;
            }

            $this->registerWorker(...$this->workers);
            unset($this->workers);

            EventLoop::defer(function (): void {
                foreach ($this->plugins as $plugin) {
                    async(static fn() => $plugin->afterStart())->catch(function (\Throwable $e) use ($plugin): void {
                        $this->logger->critical(\sprintf('%s::afterStart() failed: %s', $plugin::class, $e->getMessage()), ['exception' => $e]);
                    });
                }
                $this->status = Status::RUNNING;
                $this->logger->info(Server::NAME . ' started');
                $this->reportDaemonStartup(true);
            });
        });
    }

    private function registerWorker(WorkerInterface ...$workers): void
    {
        /** @var array<class-string<WorkerInterface>, class-string<Plugin>> $cannotBeRegistered */
        $cannotBeRegistered = [];

        foreach ($workers as $worker) {
            foreach ($worker::handledBy() as $handledByPluginClass) {
                if (!isset($this->plugins[$handledByPluginClass])) {
                    $cannotBeRegistered[$worker::class] = $handledByPluginClass;
                    continue 2;
                }
            }

            foreach ($worker::handledBy() as $handledByPluginClass) {
                try {
                    $this->plugins[$handledByPluginClass]->registerWorker($worker);
                } catch (\Throwable $e) {
                    $this->logger->critical(\sprintf('%s::registerWorker() failed: %s', $handledByPluginClass, $e->getMessage()), ['exception' => $e]);
                    $this->unregisterWorker($worker->getId());
                    continue 2;
                }
            }
        }

        foreach ($cannotBeRegistered as $workerClass => $handledByClass) {
            $this->logger->error(\sprintf('Cannot register worker "%s": required plugin "%s" is missing', $workerClass, $handledByClass));
        }
    }

    private function unregisterWorker(int $workerId): void
    {
        foreach ($this->plugins as $plugin) {
            try {
                $plugin->unregisterWorker($workerId);
            } catch (\Throwable $e) {
                $this->logger->critical(\sprintf('%s::unregisterWorker() failed: %s', $plugin::class, $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    private function saveMasterPid(): void
    {
        if (!\is_dir($pidFileDir = \dirname($this->pidFile))) {
            \mkdir(directory: $pidFileDir, recursive: true);
        }

        if (!\is_dir($socketFileDir = \dirname($this->socketFile))) {
            \mkdir(directory: $socketFileDir, recursive: true);
        }

        if (\file_exists($this->socketFile)) {
            \unlink($this->socketFile);
        }

        if (false === \file_put_contents($this->pidFile, (string) \posix_getpid())) {
            throw new PHPStreamServerException(\sprintf('Cannot save PID to %s', $this->pidFile));
        }
    }

    private function onMasterShutdown(): void
    {
        if (\file_exists($this->pidFile)) {
            \unlink($this->pidFile);
        }

        if (\file_exists($this->socketFile)) {
            \unlink($this->socketFile);
        }
    }

    private function stop(int $code = 0): void
    {
        if ($this->status === Status::SHUTDOWN || $this->status === Status::STOPPING) {
            return;
        }

        $this->status = Status::STOPPING;
        $this->logger->info(Server::NAME . ' stopping...');

        [$onStopExceptions] = awaitAll(\array_map(static fn(Plugin $plugin) => async(static fn() => $plugin->onStop()), $this->plugins));
        foreach ($onStopExceptions as $pluginClass => $exception) {
            $this->logger->critical(\sprintf('%s::onStop() failed: %s', $pluginClass, $exception->getMessage()), ['exception' => $exception]);
        }

        $this->status = Status::SHUTDOWN;
        $this->logger->info(Server::NAME . ' stopped');
        $this->suspension->resume($code);
    }

    private function reload(): void
    {
        if ($this->status !== Status::RUNNING) {
            return;
        }

        $this->logger->info(Server::NAME . ' reloading...');

        foreach ($this->plugins as $plugin) {
            EventLoop::queue(static function () use ($plugin): void {
                $plugin->onReload();
            });
        }
    }

    // After forking a worker, free inherited master-process resources in the child process
    private function free(): void
    {
        if ($this->daemonStartupSocket !== null) {
            $socket = $this->daemonStartupSocket;
            $this->daemonStartupSocket = null;
            \fclose($socket);
        }

        if ($this->messageHandler instanceof SocketFileMessageHandler) {
            $this->messageHandler->stop();
        }

        $identifiers = EventLoop::getDriver()->getIdentifiers();
        \array_walk($identifiers, EventLoop::getDriver()->cancel(...));
        EventLoop::getDriver()->stop();
        ErrorHandler::unregister();
        SIGCHLDHandler::unregister();
        EventLoop::getDriver()->run();

        unset($this->messageHandler);
        unset($this->logger);
        unset($this->masterContainer);
        unset($this->plugins);
        unset($this->suspension);

        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }

        \gc_collect_cycles();
        \gc_mem_caches();
        \clearstatcache();
    }
}
