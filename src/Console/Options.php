<?php

declare(strict_types=1);

namespace PHPStreamServer\Core\Console;

use PHPStreamServer\Core\Internal\Console\OptionDefinition;

final class Options
{
    private array $parsedOptions;

    /**
     * @var array<OptionDefinition>
     */
    private array $defaultOptionDefinitions = [];

    /**
     * @var array<OptionDefinition>
     */
    private array $optionDefinitions = [];

    /**
     * @param array<string, string|true> $parsedOptions
     * @param array<OptionDefinition> $defaultOptionDefinitions
     */
    public function __construct(array &$parsedOptions, array $defaultOptionDefinitions = [])
    {
        $this->parsedOptions = &$parsedOptions;
        foreach ($defaultOptionDefinitions as $defaultOptionDefinition) {
            $this->defaultOptionDefinitions[$defaultOptionDefinition->name] = $defaultOptionDefinition;
        }
    }

    public function addOptionDefinition(string $name, string|null $shortcut = null, string $description = '', string|null $default = null): void
    {
        $this->optionDefinitions[$name] = new OptionDefinition($name, $shortcut, $description, $default);
    }

    public function getOptionDefinitions(): array
    {
        return [...$this->optionDefinitions, ...$this->defaultOptionDefinitions];
    }

    public function hasOption(string $name): bool
    {
        $definition = $this->getOptionDefinitions()[$name] ?? null;
        $fullName = $definition?->name;
        $shortName = $definition?->shortName;

        return ($fullName !== null && \array_key_exists($fullName, $this->parsedOptions))
            || ($shortName !== null && \array_key_exists($shortName, $this->parsedOptions));
    }

    public function getOption(string $name): string|true|null
    {
        $definition = $this->getOptionDefinitions()[$name] ?? null;
        $fullName = $definition?->name;
        $shortName = $definition?->shortName;
        $default = $definition?->default;

        return $this->parsedOptions[$fullName] ?? $this->parsedOptions[$shortName] ?? $default;
    }
}
