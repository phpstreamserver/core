<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

use PHPStreamServer\Core\Console\Colorizer;

/**
 * Handler for redirect standard output to custom stream with colorize filters
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
    public static function register(mixed $stdout = 'php://stdout', mixed $stderr = 'php://stderr'): void
    {
        if (self::$isRegistered) {
            throw new \RuntimeException('StdoutHandler already registered');
        }

        if (\is_string($stdout)) {
            self::$stdout = \fopen($stdout, 'ab');
        }

        if (\is_string($stderr)) {
            self::$stderr = \fopen($stderr, 'ab');
        }

        self::$isRegistered = true;

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
    }

    public static function disableStdout(): void
    {
        $nullResource = \fopen('/dev/null', 'wb');
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
