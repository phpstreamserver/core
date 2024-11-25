<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\Console;

/**
 * @internal
 */
final readonly class OptionDefinition
{
    public function __construct(
        public string $name,
        public string|null $shortName = null,
        public string $description = '',
        public string|null $default = null,
    ) {
    }
}
