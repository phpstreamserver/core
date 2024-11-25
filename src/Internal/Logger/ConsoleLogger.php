<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Logger;

use PHPStreamServer\Core\Console\Colorizer;
use PHPStreamServer\Core\Worker\LoggerInterface;
use Psr\Log\LoggerTrait;

use function PHPStreamServer\Core\getStderr;

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

    private const LEVELS_COLOR_MAP = [
        'debug' => 'fg=15',
        'info' => 'fg=116',
        'notice' => 'fg=38',
        'warning' => 'fg=yellow',
        'error' => 'fg=red',
        'critical' => 'fg=red',
        'alert' => 'fg=red',
        'emergency' => 'fg=red',
    ];

    private ContextNormalizer $contextNormalizer;
    private bool $colorSupport;
    private string $channel = 'server';

    public function __construct()
    {
        $this->contextNormalizer = new ContextNormalizer();
        /** @psalm-suppress PossiblyInvalidArgument */
        $this->colorSupport = Colorizer::hasColorSupport(getStderr()->getResource());
    }

    public function withChannel(string $channel): self
    {
        $that = clone $this;
        $that->channel = $channel;

        return $that;
    }

    public function log(mixed $level, string|\Stringable $message, array $context = []): void
    {
        $time = (new \DateTimeImmutable('now'))->format(\DateTimeInterface::RFC3339);
        $level = (string) $level;
        $message = (string) $message;
        $context = $this->contextNormalizer->normalize($context);
        $context = $context === [] ? '' : \json_encode($this->contextNormalizer->normalize($context), self::DEFAULT_JSON_FLAGS);

        $message = \rtrim(\sprintf(
            "[%s] <color;fg=green>%s</>.<color;%s>%s</> %s %s",
            $time,
            $this->channel,
            self::LEVELS_COLOR_MAP[\strtolower($level)] ?? 'fg=gray',
            \strtoupper($level),
            $message,
            $context,
        ));

        $message = $this->colorSupport ? Colorizer::colorize($message) : Colorizer::stripTags($message);

        getStderr()->write($message . "\n");
    }
}
