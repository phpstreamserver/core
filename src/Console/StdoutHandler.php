<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

use Amp\ByteStream\WritableResourceStream;
use PHPStreamServer\Core\Internal\Console\Colorizer;
use Revolt\EventLoop;

/**
 * Redirects standard output to a custom stream with colorization filters
 */
final class StdoutHandler
{
    /** @var resource|null */
    private static mixed $stdoutResource = null;
    /** @var resource|null */
    private static mixed $stderrResource = null;
    /** @var \WeakMap<EventLoop\Driver, WritableResourceStream>|null */
    private static \WeakMap|null $stdout = null;
    /** @var \WeakMap<EventLoop\Driver, WritableResourceStream>|null */
    private static \WeakMap|null $stderr = null;
    private static bool $stdoutIsTty = false;
    /** @var \Closure(string):string|null */
    private static \Closure|null $stdoutHandler = null;
    /** @var \Closure(string):string|null */
    private static \Closure|null $stderrHandler = null;

    private function __construct()
    {
    }

    /**
     * @param resource|string $stdout
     * @param resource|string $stderr
     */
    public static function register(mixed $stdout, mixed $stderr, bool $colors = true, bool $quiet = false): void
    {
        self::$stdoutResource = \is_string($stdout) ? \fopen($stdout, 'ab') : $stdout;
        self::$stderrResource = \is_string($stderr) ? \fopen($stderr, 'ab') : $stderr;
        /** @var \WeakMap<EventLoop\Driver, WritableResourceStream> */
        self::$stdout = new \WeakMap();
        /** @var \WeakMap<EventLoop\Driver, WritableResourceStream> */
        self::$stderr = new \WeakMap();
        self::$stdoutIsTty = \posix_isatty(self::$stdoutResource);
        self::$stdoutHandler = $colors && Colorizer::hasColorSupport(self::$stdoutResource) ? Colorizer::colorize(...) : Colorizer::stripTags(...);
        self::$stderrHandler = $colors && Colorizer::hasColorSupport(self::$stderrResource) ? Colorizer::colorize(...) : Colorizer::stripTags(...);

        \ob_start(static function (string $chunk): string {
            if ($chunk !== '') {
                self::stdout($chunk);
            }

            return '';
        }, 1);

        if ($quiet) {
            self::suppress();
        }
    }

    public static function suppress(): void
    {
        \ob_end_clean();
        \ob_start(static fn(): string => '', 1);
        self::$stdoutResource = null;
        self::$stderrResource = null;
        self::$stdout = null;
        self::$stderr = null;
        self::$stdoutIsTty = false;
        self::$stdoutHandler = null;
        self::$stderrHandler = null;
    }

    public static function clearCurrentLine(): void
    {
        if (!self::$stdoutIsTty || self::$stdoutResource === null) {
            return;
        }

        \assert(self::$stdout !== null);

        $stream = self::$stdout[EventLoop::getDriver()] ??= new WritableResourceStream(self::$stdoutResource);
        $stream->write("\r\e[2K");
    }

    public static function stdout(string $buffer): void
    {
        if (self::$stdoutResource === null) {
            return;
        }

        \assert(self::$stdout !== null);
        \assert(self::$stdoutHandler !== null);

        $stream = self::$stdout[EventLoop::getDriver()] ??= new WritableResourceStream(self::$stdoutResource);
        $stream->write(self::$stdoutHandler->__invoke($buffer));
    }

    public static function stderr(string $buffer): void
    {
        if (self::$stderrResource === null) {
            return;
        }

        \assert(self::$stderr !== null);
        \assert(self::$stderrHandler !== null);

        $stream = self::$stderr[EventLoop::getDriver()] ??= new WritableResourceStream(self::$stderrResource);
        $stream->write(self::$stderrHandler->__invoke($buffer));
    }
}
