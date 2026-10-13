<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Event;

use Koh\WebMcp\Domain\Model\ContextDefinition;

/**
 * Dispatched when contexts are collected for rendering.
 * Listeners can add, remove, or modify contexts at runtime.
 */
final class CollectContextsEvent
{
    /**
     * @param ContextDefinition[]  $contexts
     * @param array<string, mixed> $siteSettings
     */
    public function __construct(
        private array $contexts,
        private readonly array $siteSettings,
    ) {}

    /**
     * @return ContextDefinition[]
     */
    public function getContexts(): array
    {
        return $this->contexts;
    }

    /**
     * @param ContextDefinition[] $contexts
     */
    public function setContexts(array $contexts): void
    {
        $this->contexts = $contexts;
    }

    public function addContext(ContextDefinition $context): void
    {
        $this->contexts[] = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteSettings(): array
    {
        return $this->siteSettings;
    }
}
