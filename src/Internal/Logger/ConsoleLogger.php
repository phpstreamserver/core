<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Logger;

use PHPStreamServer\Core\Internal\Console\StdoutHandler;
use PHPStreamServer\Core\LoggerInterface;
use Psr\Log\LoggerTrait;

/**
 * @internal
 */
final class ConsoleLogger implements LoggerInterface
{
    use LoggerTrait;

    public const DEFAULT_JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_PARTIAL_OUTPUT_ON_ERROR
        | JSON_FORCE_OBJECT
    ;

    private const LEVEL_MAP = [
        'debug' => '<color;fg=gray>DEBUG</> ',
        'info' => 'INFO  ',
        'notice' => 'NOTICE',
        'warning' => '<color;fg=yellow>WARN</>  ',
        'error' => '<color;fg=red>ERROR</> ',
        'critical' => '<color;fg=red>CRIT</>  ',
        'alert' => '<color;fg=red>ALERT</> ',
        'emergency' => '<color;fg=red>EMERG</> ',
    ];

    private ContextNormalizer $contextNormalizer;
    private string $channel = 'server';

    public function __construct()
    {
        $this->contextNormalizer = new ContextNormalizer();
    }

    public function withChannel(string $channel): self
    {
        $that = clone $this;
        $that->channel = $channel;

        return $that;
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $now = new \DateTimeImmutable('now');
        $level = (string) $level;
        $message = (string) $message;
        $context = $this->contextNormalizer->normalize($context);
        $context = $context === [] ? '' : \json_encode($this->contextNormalizer->normalize($context), self::DEFAULT_JSON_FLAGS);
        $errorLevel = self::LEVEL_MAP[\strtolower($level)] ?? $level;

        $message = \rtrim(\sprintf(
            "%s %s <color;fg=white>%s</> › %s %s",
            $now->format('Y-m-d H:i:s'),
            $errorLevel,
            $this->channel,
            $message,
            $context,
        ));

        StdoutHandler::stderr($message . "\n");
    }
}
