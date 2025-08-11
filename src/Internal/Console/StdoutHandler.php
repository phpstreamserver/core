<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

/**
 * Redirects standard output to a custom stream with colorization filters
 * @internal
 */
final class StdoutHandler
{
    /** @var resource|null */
    private static mixed $stdout = null;

    /** @var resource|null */
    private static mixed $stderr = null;

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
        self::$stdout = \is_string($stdout) ? \fopen($stdout, 'ab') : $stdout;
        self::$stderr = \is_string($stderr) ? \fopen($stderr, 'ab') : $stderr;
        self::$stdoutHandler = $colors && Colorizer::hasColorSupport(self::$stdout) ? Colorizer::colorize(...) : Colorizer::stripTags(...);
        self::$stderrHandler = $colors && Colorizer::hasColorSupport(self::$stderr) ? Colorizer::colorize(...) : Colorizer::stripTags(...);

        \ob_start(static function (string $chunk, int $phase): string {
            if (($phase & \PHP_OUTPUT_HANDLER_WRITE) === \PHP_OUTPUT_HANDLER_WRITE) {
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
        self::$stdout = null;
        self::$stderr = null;
        self::$stdoutHandler = null;
        self::$stderrHandler = null;
    }

    public static function stdout(string $buffer): void
    {
        if (self::$stdout === null || $buffer === '') {
            return;
        }

        \assert(self::$stdoutHandler !== null);
        \fwrite(self::$stdout, self::$stdoutHandler->__invoke($buffer));
    }

    public static function stderr(string $buffer): void
    {
        if (self::$stderr === null || $buffer === '') {
            return;
        }

        \assert(self::$stderrHandler !== null);
        \fwrite(self::$stderr, self::$stderrHandler->__invoke($buffer));
    }
}
