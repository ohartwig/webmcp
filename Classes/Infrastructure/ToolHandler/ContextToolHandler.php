<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Contract\MultiToolHandlerInterface;
use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;

/**
 * Serves the read-only `get-<context>` tools derived from the registered
 * contexts. One handler covers all of them because the set of contexts is
 * request-dependent and therefore unknown at container build time.
 *
 * This is the path for out-of-band agents calling the API directly. Browser
 * agents never get here: the init script answers `get-*` from the context data
 * already present in its config payload. That matters because the API
 * middleware runs before page-resolver, so page-scoped contexts (page-meta)
 * cannot be rebuilt here and correctly report themselves as unavailable —
 * they are not advertised in the discovery endpoints either.
 */
final readonly class ContextToolHandler implements MultiToolHandlerInterface
{
    public function __construct(
        private ContextRegistry $contextRegistry,
    ) {}

    /**
     * @return string[]
     */
    public function getToolNames(): array
    {
        return array_map(
            static fn($tool) => $tool->name,
            $this->contextRegistry->getContextTools(),
        );
    }

    public function execute(string $toolName, array $input, ExecutionContext $context): ToolResult
    {
        $definition = $this->contextRegistry->findContextByToolName($toolName, $context->settings);
        if (null === $definition) {
            return ToolResult::error('Unknown context');
        }

        return ToolResult::success($definition->data);
    }
}
