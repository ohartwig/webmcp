<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Service;

use Koh\WebMcp\Application\Event\CollectContextsEvent;
use Koh\WebMcp\Domain\Contract\ContextProviderInterface;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;

final readonly class ContextRegistry
{
    /**
     * Prefix for tools derived from contexts. The 2026-07-21 WebMCP draft
     * dropped ModelContext::provideContext(), so contexts only reach native
     * agents when exposed as read-only tools.
     */
    public const string TOOL_NAME_PREFIX = 'get-';

    /** @var ContextProviderInterface[] */
    private array $contextProviders;

    /**
     * @param iterable<ContextProviderInterface> $contextProviders
     */
    public function __construct(
        iterable $contextProviders,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        $providers = [];
        foreach ($contextProviders as $provider) {
            $providers[] = $provider;
        }
        $this->contextProviders = $providers;
    }

    /**
     * @param array<string, mixed> $siteSettings
     *
     * @return ContextDefinition[]
     */
    public function getContexts(array $siteSettings = []): array
    {
        $contexts = [];
        foreach ($this->contextProviders as $provider) {
            foreach ($provider->getContexts() as $context) {
                $contexts[] = $context;
            }
        }

        $event = new CollectContextsEvent($contexts, $siteSettings);
        $this->eventDispatcher->dispatch($event);

        return $event->getContexts();
    }

    /**
     * Derive a read-only tool definition per context.
     *
     * Contexts carry no input, so the schema is an empty object schema; the
     * data is editor-curated page content, hence untrustedContentHint.
     * Contexts whose derived name violates the WebMCP name pattern are
     * skipped rather than failing the whole page render.
     *
     * @param array<string, mixed> $siteSettings
     *
     * @return ToolDefinition[]
     */
    public function getContextTools(array $siteSettings = []): array
    {
        $tools = [];
        foreach ($this->getContexts($siteSettings) as $context) {
            try {
                $tools[] = new ToolDefinition(
                    name: self::TOOL_NAME_PREFIX . $context->name,
                    description: $context->description,
                    // stdClass, not [], so json_encode emits `{}` — an empty
                    // PHP array would serialise to `[]` and break the schema.
                    inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
                    annotations: new ToolAnnotations(
                        readOnlyHint: true,
                        untrustedContentHint: true,
                    ),
                );
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $tools;
    }

    /**
     * Resolve a single context by its derived tool name (e.g. "get-page-meta").
     *
     * @param array<string, mixed> $siteSettings
     */
    public function findContextByToolName(string $toolName, array $siteSettings = []): ?ContextDefinition
    {
        foreach ($this->getContexts($siteSettings) as $context) {
            if (self::TOOL_NAME_PREFIX . $context->name === $toolName) {
                return $context;
            }
        }

        return null;
    }
}
