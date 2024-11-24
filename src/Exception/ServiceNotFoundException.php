<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class ServiceNotFoundException extends \InvalidArgumentException implements NotFoundExceptionInterface
{
    public function __construct(string $id)
    {
        parent::__construct(\sprintf('You have requested a non-existent service "%s"', $id));
    }
}
