<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

/**
 * Redirects standard output to a custom stream with colorization filters
 * @internal
 */
final class StdoutHandler
{
    private static bool $isRegistered = false;

    /** @var resource */
    private static $stdout;

    /** @var resource */
    private static $stderr;

    private function __construct()
    {
    }

    /**
     * @param resource|string $stdout
     * @param resource|string $stderr
     */
    public static function register(mixed $stdout, mixed $stderr, bool $colors = true, bool $quiet = false): void
    {
        if (self::$isRegistered) {
            throw new \RuntimeException('StdoutHandler is already registered');
        }

        self::$isRegistered = true;
        self::$stdout = \is_string($stdout) ? \fopen($stdout, 'ab') : $stdout;
        self::$stderr = \is_string($stderr) ? \fopen($stderr, 'ab') : $stderr;

        if (!$colors) {
            Colorizer::disableColor();
        }

        $hasColorSupport = Colorizer::hasColorSupport(self::$stdout);
        \ob_start(static function (string $chunk, int $phase) use ($hasColorSupport): string {
            $isWrite = ($phase & \PHP_OUTPUT_HANDLER_WRITE) === \PHP_OUTPUT_HANDLER_WRITE;
            if ($isWrite && $chunk !== '') {
                $buffer = $hasColorSupport ? Colorizer::colorize($chunk) : Colorizer::stripTags($chunk);
                \fwrite(self::$stdout, $buffer);
                \fflush(self::$stdout);
            }

            return '';
        }, 1);

        if ($quiet) {
            self::disableStdout();
        }
    }

    public static function disableStdout(): void
    {
        $nullResource = \fopen('/dev/null', 'ab');
        self::$stdout = $nullResource;
        self::$stderr = $nullResource;
        \ob_end_clean();
        \ob_start(static fn() => '', 1);
    }

    /**
     * @return resource
     */
    public static function getStdout()
    {
        return self::$stdout;
    }

    /**
     * @return resource
     */
    public static function getStderr()
    {
        return self::$stderr;
    }
}
