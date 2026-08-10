<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Exception;

final class ConfigurationException extends \InvalidArgumentException
{
    public function __construct(string $parameter, string $error, int $callerDepth = 1)
    {
        parent::__construct(\sprintf('Invalid configuration for parameter "$%s": %s', $parameter, $error));

        $trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $callerDepth + 1);
        $caller = $trace[$callerDepth] ?? $trace[0] ?? [];

        if (isset($caller['file'], $caller['line'])) {
            $this->file = $caller['file'];
            $this->line = $caller['line'];
        }
    }
}
