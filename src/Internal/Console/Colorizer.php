<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

/**
 * @internal
 */
final class Colorizer
{
    /**
     * @see https://en.wikipedia.org/wiki/ANSI_escape_code#8-bit
     */
    private const COLOR_MAP = [
        'black' => 0,
        'red' => 1,
        'green' => 2,
        'yellow' => 3,
        'blue' => 4,
        'magenta' => 5,
        'cyan' => 6,
        'white' => 15,
        'gray' => 8,
        'brand' => 168,
        'token' => 73,
    ];

    private const OPTION_MAP = [
        'bold' => 1,
        'dim' => 2,
    ];

    private function __construct()
    {
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

        return \posix_isatty($stream);
    }

    /**
     * Remove colorization tags
     */
    public static function stripTags(string $string): string
    {
        return \preg_replace('/<color;.+?>([^<>]*)<\/(?:color)?>/', "$1", $string);
    }

    /**
     * Format a string in the terminal. Usage: <color;fg=green;options=bold>bold green text</>
     */
    public static function colorize(string $string): string
    {
        \preg_match_all('/<color;(.+)>.+<\/(?:color)?>/U', $string, $matches, \PREG_SET_ORDER);
        foreach ($matches as $match) {
            /** @var array<string, string> $attr */
            \parse_str(\str_replace(';', '&', $match[1] ?? ''), $attr);
            /** @var int $pos */
            $pos = \strpos($string, $match[0]);
            $len = \strlen($match[0]);
            $text = \strip_tags($match[0]);
            $codes = [];
            foreach (\explode(',', $attr['options'] ?? '') as $option) {
                if (isset(self::OPTION_MAP[$option])) {
                    $codes[] = (string) self::OPTION_MAP[$option];
                }
            }
            foreach (['fg' => 38, 'bg' => 48] as $key => $code) {
                if (!isset($attr[$key])) {
                    continue;
                }

                $color = self::COLOR_MAP[$attr[$key]] ?? $attr[$key];
                $codes[] = \sprintf('%d;5;%s', $code, $color);
            }
            $formattedString = $codes === [] ? $text : \sprintf("\e[%sm%s\e[0m", \implode(';', $codes), $text);
            $string = \substr_replace($string, $formattedString, $pos, $len);
        }

        return self::stripTags($string);
    }
}
