<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

final class Options
{
    /** @var array<string, string|true> */
    private array $parsedOptions;

    /** @var array<OptionDefinition> */
    private array $optionDefinitions = [];

    /**
     * @param list<string> $argv
     * @param array<OptionDefinition> $optionDefinitions
     * @throws \InvalidArgumentException
     */
    public function __construct(array $argv, array $optionDefinitions = [])
    {
        foreach ($optionDefinitions as $optionDefinition) {
            $this->optionDefinitions[$optionDefinition->name] = $optionDefinition;
        }
        $this->parsedOptions = $this->parseArguments($argv);
    }

    /**
     * @param list<string> $argv
     * @return array<string, string|true>
     * @throws \InvalidArgumentException
     */
    private function parseArguments(array $argv): array
    {
        $shortDefinitions = [];
        foreach ($this->optionDefinitions as $definition) {
            if ($definition->shortName !== null) {
                $shortDefinitions[$definition->shortName] = $definition;
            }
        }

        $options = [];
        for ($i = 1; $i < \count($argv); $i++) {
            $argument = $argv[$i];
            if ($argument === '--') {
                break;
            }
            if (\str_starts_with($argument, '--')) {
                $optionParts = \explode('=', \substr($argument, 2), 2);
                $definition = $this->optionDefinitions[$optionParts[0]] ?? null;
                if ($definition === null) {
                    continue;
                }

                $hasValue = \array_key_exists(1, $optionParts);
                $value = $optionParts[1] ?? '';
                if ($definition->requiresValue) {
                    if ($hasValue) {
                        if ($value === '') {
                            throw new \InvalidArgumentException(\sprintf('Option "--%s" requires a value', $definition->name));
                        }
                        $options[$definition->name] = $value;
                    } else {
                        if (!isset($argv[$i + 1]) || $argv[$i + 1] === '' || \str_starts_with($argv[$i + 1], '-')) {
                            throw new \InvalidArgumentException(\sprintf('Option "--%s" requires a value', $definition->name));
                        }
                        $options[$definition->name] = $argv[++$i];
                    }
                } else {
                    if ($hasValue) {
                        throw new \InvalidArgumentException(\sprintf('Option "--%s" does not accept a value', $definition->name));
                    }
                    $options[$definition->name] = true;
                }
            } elseif (\str_starts_with($argument, '-') && $argument !== '-') {
                $splitOptions = \str_split(\substr($argument, 1));
                foreach ($splitOptions as $option) {
                    $definition = $shortDefinitions[$option] ?? null;
                    if ($definition === null) {
                        continue;
                    }

                    if ($definition->requiresValue) {
                        if (\count($splitOptions) !== 1) {
                            throw new \InvalidArgumentException(\sprintf('Option "--%s" cannot be grouped because it requires a value', $definition->name));
                        }
                        if (!isset($argv[$i + 1]) || $argv[$i + 1] === '' || \str_starts_with($argv[$i + 1], '-')) {
                            throw new \InvalidArgumentException(\sprintf('Option "--%s" requires a value', $definition->name));
                        }

                        $options[$definition->name] = $argv[++$i];
                    } else {
                        $options[$definition->name] = true;
                    }
                }
            }
        }

        return $options;
    }

    /**
     * @return array<OptionDefinition>
     */
    public function getOptionDefinitions(): array
    {
        return $this->optionDefinitions;
    }

    public function hasOption(string $name): bool
    {
        $definition = $this->optionDefinitions[$name] ?? null;
        $fullName = $definition?->name;

        return $fullName !== null && \array_key_exists($fullName, $this->parsedOptions);
    }

    public function getOption(string $name): string|true|null
    {
        $definition = $this->optionDefinitions[$name] ?? null;
        $fullName = $definition?->name;
        $default = $definition?->default;

        return $this->parsedOptions[$fullName ?? ''] ?? $default;
    }
}
