<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal\MessageBus;

final readonly class MessageBusResponse
{
    public function __construct(
        public mixed $result = null,
        public string|null $error = null,
    ) {
    }
}
