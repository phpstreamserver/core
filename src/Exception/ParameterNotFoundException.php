<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Exception;

final class ParameterNotFoundException extends \InvalidArgumentException
{
    public function __construct(string $id)
    {
        parent::__construct(\sprintf('You have requested a non-existent parameter "%s"', $id));
    }
}
