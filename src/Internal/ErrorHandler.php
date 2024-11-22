<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;

/**
 * @internal
 */
final class ErrorHandler
{
    private const ERRORS = [
        \E_DEPRECATED => ['Deprecated', LogLevel::INFO],
        \E_USER_DEPRECATED => ['User Deprecated', LogLevel::INFO],
        \E_NOTICE => ['Notice', LogLevel::WARNING],
        \E_USER_NOTICE => ['User Notice', LogLevel::WARNING],
        \E_STRICT => ['Runtime Notice', LogLevel::WARNING],
        \E_WARNING => ['Warning', LogLevel::WARNING],
        \E_USER_WARNING => ['User Warning', LogLevel::WARNING],
        \E_COMPILE_WARNING => ['Compile Warning', LogLevel::WARNING],
        \E_CORE_WARNING => ['Core Warning', LogLevel::WARNING],
        \E_USER_ERROR => ['User Error', LogLevel::CRITICAL],
        \E_RECOVERABLE_ERROR => ['Catchable Fatal Error', LogLevel::CRITICAL],
        \E_COMPILE_ERROR => ['Compile Error', LogLevel::CRITICAL],
        \E_PARSE => ['Parse Error', LogLevel::CRITICAL],
        \E_ERROR => ['Error', LogLevel::CRITICAL],
        \E_CORE_ERROR => ['Core Error', LogLevel::CRITICAL],
    ];

    private static bool $registered = false;
    private static LoggerInterface $logger;

    private function __construct()
    {
    }

    public static function register(LoggerInterface $logger): void
    {
        if (self::$registered === true) {
            throw new \LogicException(\sprintf('%s(): Already registered', __METHOD__));
        }

        self::$registered = true;
        self::$logger = $logger;
        \set_error_handler(self::handleError(...));
        \set_exception_handler(self::handleException(...));
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

    /**
     * @throws \ErrorException
     */
    private static function handleError(int $type, string $message, string $file, int $line): bool
    {
        $logMessage = \sprintf("%s: %s", self::ERRORS[$type][0], $message);
        $errorAsException = new \ErrorException($logMessage, 0, $type, $file, $line);
        $level = self::ERRORS[$type][1];

        if ($level === LogLevel::CRITICAL) {
            throw $errorAsException;
        }

        self::$logger->log($level, $logMessage, ['exception' => $errorAsException]);

        return true;
    }

    public static function handleException(\Throwable $exception): void
    {
        if (self::$registered === false) {
            throw new \LogicException(\sprintf('%s(): ErrorHandler is unregistered', __METHOD__), 0, $exception);
        }

        $title = match (true) {
            $exception instanceof \Error => 'Error',
            $exception instanceof \ErrorException => '',
            default => 'Exception',
        };

        $message = \sprintf(
            'Uncaught %s %s: "%s" in %s:%d',
            $title,
            (new \ReflectionClass($exception::class))->getShortName(),
            $exception->getMessage(),
            \basename($exception->getFile()),
            $exception->getLine(),
        );

        self::$logger->critical($message, ['exception' => $exception]);
    }
}
