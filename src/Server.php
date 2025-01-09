<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use Composer\InstalledVersions;
use PHPStreamServer\Core\Internal\Console\App;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\SupervisorPlugin;
use PHPStreamServer\Core\Plugin\System\SystemPlugin;
use Revolt\EventLoop;

final class Server
{
    public const PACKAGE = 'phpstreamserver/core';
    public const NAME = 'PHPStreamServer';
    public const SHORTNAME = 'phpss';
    public const TITLE = '🌸 PHPStreamServer - PHP application server';

    /** @var array<Plugin> */
    private array $plugins = [];

    /** @var array<Process> */
    private array $workers = [];

    public function __construct(
        private string|null $pidFile = null,
        private string|null $socketFile = null,
        int|null $stopTimeout = null,
        float|null $restartDelay = null,
    ) {
        $this->pidFile ??= namespace\getDefaultPidFile();
        $this->socketFile ??= namespace\getDefaultSocketFile();
        $this->addPlugin(new SystemPlugin());
        $this->addPlugin(new SupervisorPlugin($stopTimeout ?? 10, $restartDelay ?? 0.25));
    }

    public function addPlugin(Plugin ...$plugins): self
    {
        \array_push($this->plugins, ...$plugins);

        return $this;
    }

    public function addWorker(Process ...$workers): self
    {
        \array_push($this->workers, ...$workers);

        return $this;
    }

    public function run(): int
    {
        /** @psalm-suppress NamedArgumentNotAllowed, InvalidArgument */
        $app = new App(...\array_merge(...\array_map(static fn(Plugin $p) => $p->registerCommands(), $this->plugins)));
        $map = new \WeakMap();
        $map[EventLoop::getDriver()] = \get_object_vars($this);
        unset($this->workers, $this->plugins);
        return $app->run($map);
    }

    public static function getVersion(): string
    {
        static $version;
        try {
            return $version ??= InstalledVersions::getVersion(self::PACKAGE) ?? 'dev';
        } catch (\OutOfBoundsException) {
            return $version ??= 'dev';
        }
    }

    public static function getVersionString(): string
    {
        return \sprintf('%s/%s', \strtolower(self::NAME), self::getVersion());
    }
}
