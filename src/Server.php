<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use Composer\InstalledVersions;
use PHPStreamServer\Core\Console\CommandContext;
use PHPStreamServer\Core\Internal\Console\ConsoleApplication;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\SupervisorPlugin;
use PHPStreamServer\Core\Plugin\System\SystemPlugin;
use PHPStreamServer\Core\Worker\WorkerFactory;

final class Server
{
    public const PACKAGE = 'phpstreamserver/core';
    public const NAME = 'PHPStreamServer';
    public const SHORTNAME = 'phpss';

    private string $pidFile;
    private string $socketFile;

    /** @var array<Plugin> */
    private array $plugins = [];

    /** @var array<WorkerInterface> */
    private array $workers = [];

    /** @var array<WorkerFactory> */
    private array $workerFactories = [];

    public function __construct(
        string|null $pidFile = null,
        string|null $socketFile = null,
        int|null $stopTimeout = null,
        float|null $restartDelay = null,
    ) {
        $this->pidFile = $pidFile ?? namespace\getDefaultPidFile();
        $this->socketFile = $socketFile ?? namespace\getDefaultSocketFile();
        $this->addPlugin(new SystemPlugin(stopTimeout: $stopTimeout ?? 10));
        $this->addPlugin(new SupervisorPlugin(restartDelay: $restartDelay ?? 0.25));
    }

    /**
     * @template T of WorkerInterface
     * @param Plugin<T> ...$plugins
     */
    public function addPlugin(Plugin ...$plugins): self
    {
        \array_push($this->plugins, ...$plugins);

        return $this;
    }

    public function addWorker(WorkerInterface ...$workers): self
    {
        \array_push($this->workers, ...$workers);

        return $this;
    }

    public function addWorkerFactory(WorkerFactory ...$factories): self
    {
        \array_push($this->workerFactories, ...$factories);

        return $this;
    }

    public function run(): int
    {
        $context = new CommandContext($this->pidFile, $this->socketFile, $this->plugins, $this->workers, $this->workerFactories);
        $this->plugins = [];
        $this->workers = [];
        $this->workerFactories = [];
        unset($this->pidFile, $this->socketFile);

        return (new ConsoleApplication())->run($context, $_SERVER['argv'] ?? []);
    }

    public static function getVersion(): string
    {
        static $version;
        try {
            return $version ??= \ltrim(InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'dev', 'v');
        } catch (\OutOfBoundsException) {
            return $version ??= 'dev';
        }
    }

    public static function getProductName(): string
    {
        return \sprintf('%s/%s', \strtolower(self::NAME), self::getVersion());
    }
}
