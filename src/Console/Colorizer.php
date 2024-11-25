<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

final class Colorizer
{
    /**
     * @see https://en.wikipedia.org/wiki/ANSI_escape_code#8-bit
     */
    private const COLORMAP = [
        'black' => 0,
        'red' => 1,
        'green' => 2,
        'yellow' => 3,
        'blue' => 4,
        'magenta' => 5,
        'cyan' => 6,
        'white' => 7,
        'gray' => 8,
    ];

    private static bool $color = true;

    private function __construct()
    {
    }

    public static function disableColor(): void
    {
        self::$color = false;
    }

    /**
     * @param resource $stream
     * @psalm-suppress RiskyTruthyFalsyComparison
     */
    public static function hasColorSupport($stream): bool
    {
        // Follow https://no-color.org/
        if (\getenv('NO_COLOR')) {
            return false;
        }

        // Follow https://force-color.org/
        if (\getenv('FORCE_COLOR')) {
            return true;
        }

        return self::$color && \posix_isatty($stream);
    }

    /**
     * Remove colorize tags
     */
    public static function stripTags(string $string): string
    {
        return \preg_replace('/<color;.+?>([^<>]*)<\/(?:color)?>/', "$1", $string);
    }

    /**
     * Colorize string in terminal. Usage: <color;fg=green;bg=black>green text</>
     */
    public static function colorize(string $string): string
    {
        \preg_match_all('/<color;(.+)>.+<\/(?:color)?>/U', $string, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            \parse_str(\str_replace(';', '&', $match[1] ?? ''), $attr);
            /** @var int $pos */
            $pos = \strpos($string, $match[0]);
            $len = \strlen($match[0]);
            $text = \strip_tags($match[0]);
            $color = self::COLORMAP[$attr['fg'] ?? $attr['bg']] ?? $attr['fg'] ?? $attr['bg'];
            $isFg = isset($attr['fg']);
            $formattedString = \sprintf("\e[%s;5;%sm%s\e[0m", $isFg ? '38' : '48', $color, $text);
            $string = \substr_replace($string, $formattedString, $pos, $len);
        }

        return self::stripTags($string);
    }
}
