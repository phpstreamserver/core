<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

use Amp\ByteStream\WritableResourceStream;

/**
 * Redirects standard output to a custom stream with colorization filters
 * @internal
 */
final class StdoutHandler
{
    private static WritableResourceStream|null $stdout = null;

    private static WritableResourceStream|null $stderr = null;

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
        $stdoutResource = \is_string($stdout) ? \fopen($stdout, 'ab') : $stdout;
        $stderrResource = \is_string($stderr) ? \fopen($stderr, 'ab') : $stderr;
        self::$stdout = new WritableResourceStream($stdoutResource);
        self::$stderr = new WritableResourceStream($stderrResource);
        self::$stdoutHandler = $colors && Colorizer::hasColorSupport($stdoutResource) ? Colorizer::colorize(...) : Colorizer::stripTags(...);
        self::$stderrHandler = $colors && Colorizer::hasColorSupport($stderrResource) ? Colorizer::colorize(...) : Colorizer::stripTags(...);
        unset($stdoutResource, $stderrResource);

        \ob_start(static function (string $chunk, int $phase): string {
            $isWrite = ($phase & \PHP_OUTPUT_HANDLER_WRITE) === \PHP_OUTPUT_HANDLER_WRITE;
            if ($isWrite && $chunk !== '') {
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
        if (self::$stdout === null) {
            return;
        }

        \assert(self::$stdoutHandler !== null);
        self::$stdout->write(self::$stdoutHandler->__invoke($buffer));
    }

    public static function stderr(string $buffer): void
    {
        if (self::$stderr === null) {
            return;
        }

        \assert(self::$stderrHandler !== null);
        self::$stderr->write(self::$stderrHandler->__invoke($buffer));
    }
}
