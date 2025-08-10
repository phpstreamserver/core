<?php

declare(strict_types=1);

namespace PHPStreamServer\Core;

use Composer\InstalledVersions;
use PHPStreamServer\Core\Internal\Console\App;
use PHPStreamServer\Core\Plugin\Plugin;
use PHPStreamServer\Core\Plugin\Supervisor\SupervisorPlugin;
use PHPStreamServer\Core\Plugin\System\SystemPlugin;

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
        /** @psalm-suppress PossiblyNullArgument */
        return (new App($this->pidFile, $this->socketFile))->run($this->plugins, $this->workers);
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
