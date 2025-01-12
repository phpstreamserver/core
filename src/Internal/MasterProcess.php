<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\Exception\PHPStreamServerException;
use PHPStreamServer\Core\Internal\Console\StdoutHandler;
use PHPStreamServer\Core\Internal\Logger\ConsoleLogger;
use PHPStreamServer\Core\MessageBus\Message\ReloadServerCommand;
use PHPStreamServer\Core\MessageBus\Message\StopServerCommand;
use PHPStreamServer\Core\MessageBus\MessageBusInterface;
use PHPStreamServer\Core\MessageBus\MessageHandlerInterface;
use PHPStreamServer\Core\MessageBus\SocketFileMessageBus;
use PHPStreamServer\Core\MessageBus\SocketFileMessageHandler;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Process;
use PHPStreamServer\Core\Server;
use PHPStreamServer\Core\Worker\ContainerInterface;
use PHPStreamServer\Core\Worker\LoggerInterface;
use PHPStreamServer\Core\Worker\Status;
use Psr\Container\ContainerInterface as PsrContainerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Suspension;

use function Amp\Future\await;
use function PHPStreamServer\Core\getStartFile;
use function PHPStreamServer\Core\isRunning;

/**
 * @internal
 */
final class MasterProcess
{
    private const GC_PERIOD = 300;

    private static bool $registered = false;
    private Suspension $suspension;
    private Status $status = Status::SHUTDOWN;
    private MessageHandlerInterface $messageHandler;
    private LoggerInterface $logger;
    private ContainerInterface $masterContainer;
    private ContainerInterface $workerContainer;

    /**
     * @var array<class-string<Plugin>, Plugin>
     */
    private array $plugins = [];

    /**
     * @var array<class-string<Process>, class-string<Plugin>>
     */
    private array $workerClassesCanNotBeHandled = [];

    /**
     * @param array<Plugin> $plugins
     * @param array<Process> $workers
     */
    public function __construct(
        private readonly string $pidFile,
        private readonly string $socketFile,
        array $plugins,
        array $workers,
    ) {
        if (!\in_array(PHP_SAPI, ['cli', 'phpdbg', 'micro'], true)) {
            throw new PHPStreamServerException('Works in command line mode only');
        }

        if (self::$registered) {
            throw new PHPStreamServerException('Only one instance of server can be instantiated');
        }

        self::$registered = true;

        $this->masterContainer = new Container();
        $this->workerContainer = new Container();

        // Init event loop.
        EventLoop::setDriver(new StreamSelectDriver());
        $this->suspension = EventLoop::getDriver()->getSuspension();

        $this->masterContainer->setService('main_suspension', $this->suspension);
        $this->masterContainer->registerService(MessageHandlerInterface::class, fn() => new SocketFileMessageHandler($this->socketFile));
        $this->masterContainer->setAlias(MessageBusInterface::class, MessageHandlerInterface::class);
        $this->masterContainer->registerService(LoggerInterface::class, $defaultLogger = static fn() => new ConsoleLogger());
        $this->masterContainer->setParameter('pid_file', $this->pidFile);
        $this->masterContainer->setParameter('socket_file', $this->socketFile);
        $this->workerContainer->registerService(MessageBusInterface::class, fn() => new SocketFileMessageBus($this->socketFile));
        $this->workerContainer->registerService(LoggerInterface::class, static fn() => $defaultLogger()->withChannel('worker'));
        $this->workerContainer->setAlias(PsrLoggerInterface::class, LoggerInterface::class);
        $this->workerContainer->setParameter('pid_file', $this->pidFile);
        $this->workerContainer->setParameter('socket_file', $this->socketFile);
        $this->workerContainer->setService(ContainerInterface::class, $this->workerContainer);
        $this->workerContainer->setAlias(PsrContainerInterface::class, ContainerInterface::class);

        $this->addPlugin(...$plugins);
        $this->addWorker(...$workers);
    }

    public function addPlugin(Plugin ...$plugins): void
    {
        if ($this->status !== Status::SHUTDOWN) {
            throw new PHPStreamServerException('Cannot add plugin on running server');
        }

        foreach ($plugins as $plugin) {
            if (isset($this->plugins[$plugin::class])) {
                throw new PHPStreamServerException(\sprintf('Plugin "%s" already registered', $plugin::class));
            }
            $this->plugins[$plugin::class] = $plugin;
            $plugin->register($this->masterContainer, $this->workerContainer, $this->status);
        }
    }

    public function addWorker(Process ...$workers): void
    {
        if ($this->status !== Status::SHUTDOWN) {
            throw new PHPStreamServerException('Cannot add worker on running server');
        }

        foreach ($workers as $worker) {
            foreach ($worker::handleBy() as $handledByPluginClass) {
                if (!isset($this->plugins[$handledByPluginClass])) {
                    $this->workerClassesCanNotBeHandled[$worker::class] = $handledByPluginClass;
                    continue 2;
                }
            }

            foreach ($worker::handleBy() as $handledByPluginClass) {
                $this->plugins[$handledByPluginClass]->addWorker($worker);
            }
        }
    }

    public function run(bool $daemonize): int
    {
        if ($this->isRunning()) {
            throw new PHPStreamServerException(\sprintf('%s already running', Server::NAME));
        }

        if ($daemonize && $this->doDaemonize()) {
            // Runs in caller process
            return 0;
        } elseif ($daemonize) {
            // Runs in daemonized master process
            StdoutHandler::disableStdout();
        }

        $this->start();
        $ret = $this->suspension->suspend();

        // child process start
        if ($ret instanceof Process) {
            $this->free();
            exit($ret->run($this->workerContainer));
        }

        // master process shutdown
        \assert(\is_int($ret));
        $this->onMasterShutdown();
        return $ret;
    }

    /**
     * Runs in master process
     */
    private function start(): void
    {
        $startFile = getStartFile();

        // some command line SAPIs (e.g. phpdbg) don't have that function
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title(\sprintf('%s: master process  start_file=%s', Server::NAME, $startFile));
        }

        $this->status = Status::STARTING;
        $this->saveMasterPid();

        $this->logger = &$this->masterContainer->getService(LoggerInterface::class);
        $this->messageHandler = &$this->masterContainer->getService(MessageHandlerInterface::class);

        $stopCallback = function (): void { $this->stop(); };
        $reloadCallback = function (): void { $this->reload(); };
        EventLoop::onSignal(SIGINT, $stopCallback);
        EventLoop::onSignal(SIGTERM, $stopCallback);
        EventLoop::onSignal(SIGHUP, $stopCallback);
        EventLoop::onSignal(SIGTSTP, $stopCallback);
        EventLoop::onSignal(SIGQUIT, $stopCallback);
        EventLoop::onSignal(SIGUSR1, $reloadCallback);

        // Force run garbage collection periodically
        EventLoop::repeat(self::GC_PERIOD, static function (): void {
            \gc_collect_cycles();
            \gc_mem_caches();
        });

        foreach ($this->plugins as $plugin) {
            EventLoop::defer(static function () use ($plugin) {
                $plugin->onStart();
            });
        }

        EventLoop::defer(function () {
            ErrorHandler::register($this->logger);
            EventLoop::setErrorHandler(ErrorHandler::handleException(...));

            $this->messageHandler->subscribe(StopServerCommand::class, function (StopServerCommand $message) {
                $this->stop($message->code);
            });

            $this->messageHandler->subscribe(ReloadServerCommand::class, function () {
                $this->reload();
            });

            $this->logger->info(Server::NAME . ' has started');

            foreach ($this->workerClassesCanNotBeHandled as $workerClass => $handledByClass) {
                $this->logger->error(\sprintf('"%s" process can not be handled. Register "%s" plugin', $workerClass, $handledByClass));
            }

            unset($this->workerClassesCanNotBeHandled);
        });

        foreach ($this->plugins as $plugin) {
            EventLoop::defer(static function () use ($plugin) {
                $plugin->afterStart();
            });
        }

        $this->status = Status::RUNNING;
    }

    /**
     * Fork process for Daemonize
     *
     * @return bool return true in master process and false in child
     */
    private function doDaemonize(): bool
    {
        $pid = \pcntl_fork();
        if ($pid === -1) {
            throw new PHPStreamServerException('Fork fail');
        }
        if ($pid > 0) {
            return true;
        }
        if (\posix_setsid() === -1) {
            throw new PHPStreamServerException('Setsid fail');
        }
        return false;
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
            throw new PHPStreamServerException(\sprintf('Can\'t save pid to %s', $this->pidFile));
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
        if ($this->status !== Status::RUNNING) {
            return;
        }

        $this->status = Status::STOPPING;
        $this->logger->info(Server::NAME . ' stopping ...');
        await(\array_map(static fn(Plugin $p) => $p->onStop(), $this->plugins));
        $this->status = Status::SHUTDOWN;
        $this->logger->info(Server::NAME . ' stopped');
        $this->suspension->resume($code);
    }

    private function reload(): void
    {
        if ($this->status !== Status::RUNNING) {
            return;
        }

        $this->logger->info(Server::NAME . ' reloading ...');

        foreach ($this->plugins as $plugin) {
            EventLoop::queue(static function () use ($plugin) {
                $plugin->onReload();
            });
        }
    }

    public function isRunning(): bool
    {
        return $this->status === Status::RUNNING || isRunning($this->pidFile);
    }

    private function free(): void
    {
        $identifiers = EventLoop::getDriver()->getIdentifiers();
        \array_walk($identifiers, EventLoop::getDriver()->cancel(...));
        EventLoop::getDriver()->stop();

        ErrorHandler::unregister();
        SIGCHLDHandler::unregister();

        EventLoop::getDriver()->run();

        unset($this->plugins);
        unset($this->messageHandler);
        unset($this->masterContainer);
        unset($this->logger);

        \gc_collect_cycles();
        \gc_mem_caches();
    }
}
