<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Internal;

use PHPStreamServer\Core\WorkerInterface;

/**
 * @internal
 */
final readonly class CallbackSignatureValidator
{
    private function __construct()
    {
    }

    public static function assertWorkerFactoryCallback(\Closure $factory): void
    {
        $reflection = new \ReflectionFunction($factory);
        $returnType = $reflection->getReturnType();
        $parameters = $reflection->getParameters();

        if (!$returnType instanceof \ReflectionNamedType || $returnType->allowsNull() || !\is_a($returnType->getName(), WorkerInterface::class, true)) {
            throw new \TypeError(\sprintf('WorkerFactory callback must have return type %s, %s declared', WorkerInterface::class, $returnType ?? 'none'));
        }

        if (\count($parameters) > 1) {
            throw new \InvalidArgumentException(\sprintf('WorkerFactory callback must accept zero parameters or one array parameter, %d parameters declared', \count($parameters)));
        }

        if ($parameters !== []) {
            $parameter = $parameters[0];
            $parameterType = $parameter->getType();
            if (!$parameterType instanceof \ReflectionNamedType || $parameterType->getName() !== 'array' || $parameterType->allowsNull()) {
                throw new \InvalidArgumentException(\sprintf('WorkerFactory callback parameter "$%s" must have type array, %s declared', $parameter->getName(), $parameterType === null ? 'none' : (string) $parameterType));
            }

            if ($parameter->isPassedByReference()) {
                throw new \InvalidArgumentException(\sprintf('WorkerFactory callback parameter "$%s" must be passed by value', $parameter->getName()));
            }

            if ($parameter->isVariadic()) {
                throw new \InvalidArgumentException(\sprintf('WorkerFactory callback parameter "$%s" must not be variadic', $parameter->getName()));
            }
        }
    }
}
