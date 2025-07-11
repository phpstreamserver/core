<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

/**
 * @internal
 */
final class ErrorHandler
{
    private const ERROR_LEVEL = [
        \E_DEPRECATED => LogLevel::INFO,
        \E_USER_DEPRECATED => LogLevel::INFO,
        \E_NOTICE => LogLevel::WARNING,
        \E_USER_NOTICE => LogLevel::WARNING,
        \E_WARNING => LogLevel::WARNING,
        \E_USER_WARNING => LogLevel::WARNING,
        \E_COMPILE_WARNING => LogLevel::WARNING,
        \E_CORE_WARNING => LogLevel::WARNING,
        \E_USER_ERROR => LogLevel::CRITICAL,
        \E_RECOVERABLE_ERROR => LogLevel::CRITICAL,
        \E_COMPILE_ERROR => LogLevel::CRITICAL,
        \E_PARSE => LogLevel::CRITICAL,
        \E_ERROR => LogLevel::CRITICAL,
        \E_CORE_ERROR => LogLevel::CRITICAL,
    ];

    private static bool $registered = false;
    private static string|null $reservedMemory = null;
    private static LoggerInterface $logger;

    private function __construct()
    {
    }

    public static function register(LoggerInterface $logger): void
    {
        if (self::$registered === true) {
            throw new \LogicException(\sprintf('%s(): Already registered', __METHOD__));
        }

        if (self::$reservedMemory === null) {
            self::$reservedMemory = \str_repeat('x', 32768);
            \register_shutdown_function(self::shutdownHandler(...));
        }

        \ini_set('display_errors', '0');
        \ini_set('error_log', '/dev/null');
        \ini_set('fatal_error_backtraces', '0');

        \set_error_handler(self::handleError(...));
        \set_exception_handler(self::handleException(...));

        self::$registered = true;
        self::$logger = $logger;
    }

    public static function unregister(): void
    {
        if (self::$registered === false) {
            return;
        }

        \restore_error_handler();
        \restore_exception_handler();

        self::$registered = false;
        self::$logger = new NullLogger();
    }

    public static function handleException(\Throwable $exception): void
    {
        if (self::$registered === false) {
            throw $exception;
        }

        self::$logger->critical(self::formatExceptionMessage($exception), ['exception' => $exception]);
    }

    /**
     * @throws \ErrorException
     */
    private static function handleError(int $type, string $message, string $file, int $line): true
    {
        $errorAsException = new \ErrorException($message, 0, $type, $file, $line);
        $level = self::ERROR_LEVEL[$type];

        if ($level === LogLevel::CRITICAL) {
            throw $errorAsException;
        }

        self::$logger->log($level, self::formatExceptionMessage($errorAsException));

        return true;
    }

    private static function shutdownHandler(): void
    {
        if (self::$registered === false) {
            return;
        }

        self::$reservedMemory = null;
        $error = \error_get_last();

        if (!($error && $error['type'] &= \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR)) {
            return;
        }

        \register_shutdown_function(static function (): never { exit(255); });

        EventLoop::getDriver()->stop();
        EventLoop::setDriver(new StreamSelectDriver());

        $errorAsException = new \ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
        self::$logger->critical(self::formatExceptionMessage($errorAsException));

        EventLoop::defer(static function (): void { EventLoop::defer(EventLoop::getDriver()->stop(...)); });
        EventLoop::run();
    }

    private static function formatExceptionMessage(\Throwable $e): string
    {
        $isErrorException = $e instanceof \ErrorException;
        $errorSeverity = $e instanceof \ErrorException ? $e->getSeverity() : 0;
        $prefix = match (true) {
            $isErrorException && $errorSeverity === \E_DEPRECATED => 'Deprecated',
            $isErrorException && $errorSeverity === \E_USER_DEPRECATED => 'User Deprecated',
            $isErrorException && $errorSeverity === \E_NOTICE => 'Notice',
            $isErrorException && $errorSeverity === \E_USER_NOTICE => 'User Notice',
            $isErrorException && $errorSeverity === \E_WARNING => 'Warning',
            $isErrorException && $errorSeverity === \E_USER_WARNING => 'User Warning',
            $isErrorException && $errorSeverity === \E_COMPILE_WARNING => 'Compile Warning',
            $isErrorException && $errorSeverity === \E_CORE_WARNING => 'Core Warning',
            $isErrorException && $errorSeverity === \E_USER_ERROR => 'User Error',
            $isErrorException && $errorSeverity === \E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
            $isErrorException && $errorSeverity === \E_COMPILE_ERROR => 'Compile Error',
            $isErrorException && $errorSeverity === \E_PARSE => 'Parse Error',
            $isErrorException && $errorSeverity === \E_ERROR => 'Fatal error',
            $isErrorException && $errorSeverity === \E_CORE_ERROR => 'Core Error',
            $e instanceof \Error => 'Error',
            default => 'Exception',
        };

        return \sprintf(
            '%s%s%s: "%s" in %s:%d',
            $isErrorException ? '' : 'Uncaught ',
            $prefix,
            $isErrorException ? '' : ' ' . (new \ReflectionClass($e::class))->getShortName(),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
        );
    }
}
