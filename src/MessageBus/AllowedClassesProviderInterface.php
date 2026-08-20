<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\MessageBus;

/**
 * Declares additional classes permitted for safe deserialization when the message contains nested objects
 */
interface AllowedClassesProviderInterface
{
    /**
     * @return list<class-string>
     */
    public static function getAllowedClasses(): array;
}
