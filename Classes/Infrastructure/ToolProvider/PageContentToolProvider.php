<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolProvider;

use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;

final readonly class PageContentToolProvider implements ToolProviderInterface
{
    public function getIdentifier(): string
    {
        return 'page-content';
    }

    public function getTools(array $settings = []): array
    {
        return [
            new ToolDefinition(
                name: 'page-content',
                description: 'Get structured content of a specific page',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'pageId' => [
                            'type' => 'integer',
                            'description' => 'Page UID to get content from',
                        ],
                        'slug' => [
                            'type' => 'string',
                            'description' => 'Page slug/path (alternative to pageId)',
                        ],
                    ],
                ],
                annotations: new ToolAnnotations(
                    readOnlyHint: true,
                    // Returns full editor-curated bodytext — high prompt-injection
                    // surface, agents must treat the content as untrusted input.
                    untrustedContentHint: true,
                ),
                title: 'Page Content',
            ),
        ];
    }
}
