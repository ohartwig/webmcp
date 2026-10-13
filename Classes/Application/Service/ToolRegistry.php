<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Service;

use Koh\WebMcp\Application\Event\CollectToolsEvent;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\PageToolConfiguration;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;

final readonly class ToolRegistry
{
    /** @var ToolProviderInterface[] */
    private array $toolProviders;

    /**
     * @param iterable<ToolProviderInterface> $toolProviders
     */
    public function __construct(
        iterable $toolProviders,
        private EventDispatcherInterface $eventDispatcher,
    ) {
        $providers = [];
        foreach ($toolProviders as $provider) {
            $providers[] = $provider;
        }
        $this->toolProviders = $providers;
    }

    /**
     * Get all registered tool definitions, filtered by site settings, consent, and page overrides.
     *
     * @param array<string, array<string, mixed>> $siteSettings WebMCP site settings for tool toggles
     *
     * @return ToolDefinition[]
     */
    public function getTools(array $siteSettings = [], ?PageToolConfiguration $pageConfig = null): array
    {
        $tools = [];
        foreach ($this->toolProviders as $provider) {
            foreach ($provider->getTools($siteSettings) as $tool) {
                // Settings are keyed by provider, and most providers happen to
                // emit a single tool named after themselves — so looking up by
                // tool name alone worked for search, navigation and page-content
                // and silently did nothing for the rest. A provider that emits a
                // tool per form ("form-contact") or per context ("get-cart")
                // never matched its own settings key, which meant
                // `tools.form.enabled: false` did not disable anything and the
                // form tools were offered on every site that had a form.
                $toolSettings = $siteSettings['tools'][$tool->name]
                    ?? $siteSettings['tools'][$provider->getIdentifier()]
                    ?? [];
                if (isset($toolSettings['enabled']) && !(bool) $toolSettings['enabled']) {
                    continue;
                }

                // Apply page-level overrides
                if (null !== $pageConfig) {
                    $pageEnabled = $pageConfig->isToolEnabled($tool->name);
                    if (false === $pageEnabled) {
                        continue;
                    }
                }

                $tools[] = $tool;
            }
        }

        $event = new CollectToolsEvent($tools, $siteSettings);
        $this->eventDispatcher->dispatch($event);

        return $event->getTools();
    }
}
