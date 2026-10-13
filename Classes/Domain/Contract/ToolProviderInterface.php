<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Contract;

use Koh\WebMcp\Domain\Model\ToolDefinition;

/**
 * Implement this interface to register custom WebMCP tools.
 * Implementations are auto-tagged via Services.yaml (_instanceof).
 */
interface ToolProviderInterface
{
    /**
     * Unique identifier for this provider (e.g. "search", "shop").
     */
    public function getIdentifier(): string;

    /**
     * Return the tool definitions provided by this provider.
     *
     * Providers that emit one fixed tool can ignore the settings. Providers
     * that derive a tool per form, per context or per anything else need them:
     * without the settings they cannot tell which of those the site actually
     * permits, and would advertise capabilities the handler then refuses.
     *
     * @param array<string, mixed> $settings the site's `webmcp` settings
     *
     * @return ToolDefinition[]
     */
    public function getTools(array $settings = []): array;
}
