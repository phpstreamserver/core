<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AuthorizedSources
{
    /** @var array<MessageSource> */
    public array $sources;

    public function __construct(MessageSource ...$sources)
    {
        $this->sources = $sources;
    }
}
