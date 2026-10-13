<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolProvider;

use Koh\WebMcp\Application\Service\ContextRegistry;
use Koh\WebMcp\Domain\Contract\ToolProviderInterface;

/**
 * Feeds the tools derived from contexts into the regular ToolRegistry, so the
 * JS config payload and the /.well-known discovery endpoints pick them up
 * through the same path as every other tool.
 */
final readonly class ContextToolProvider implements ToolProviderInterface
{
    public function __construct(
        private ContextRegistry $contextRegistry,
    ) {}

    public function getIdentifier(): string
    {
        return 'context';
    }

    public function getTools(array $settings = []): array
    {
        return $this->contextRegistry->getContextTools();
    }
}
