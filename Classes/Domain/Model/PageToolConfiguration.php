<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class PageToolConfiguration
{
    /**
     * @param array<string, bool> $toolOverrides Tool name => enabled/disabled
     */
    public function __construct(
        public array $toolOverrides = [],
    ) {}

    public function isToolEnabled(string $toolName): ?bool
    {
        return $this->toolOverrides[$toolName] ?? null;
    }

    public function hasOverrides(): bool
    {
        return [] !== $this->toolOverrides;
    }
}
