<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Event;

use Koh\WebMcp\Domain\Model\ToolDefinition;

/**
 * Dispatched when tools are collected for rendering.
 * Listeners can add, remove, or modify tools at runtime.
 */
final class CollectToolsEvent
{
    /**
     * @param ToolDefinition[]     $tools
     * @param array<string, mixed> $siteSettings
     */
    public function __construct(
        private array $tools,
        private readonly array $siteSettings,
    ) {}

    /**
     * @return ToolDefinition[]
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @param ToolDefinition[] $tools
     */
    public function setTools(array $tools): void
    {
        $this->tools = $tools;
    }

    public function addTool(ToolDefinition $tool): void
    {
        $this->tools[] = $tool;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSiteSettings(): array
    {
        return $this->siteSettings;
    }
}
