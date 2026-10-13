<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolProvider;

use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;

final readonly class SearchToolProvider implements ToolProviderInterface
{
    public function getIdentifier(): string
    {
        return 'search';
    }

    public function getTools(array $settings = []): array
    {
        return [
            new ToolDefinition(
                name: 'search',
                description: 'Search content on this website',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query string',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results',
                            'default' => 10,
                        ],
                    ],
                    'required' => ['query'],
                ],
                annotations: new ToolAnnotations(
                    readOnlyHint: true,
                    // Results contain editor-curated page bodytext — agents should treat
                    // text content as untrusted (prompt-injection surface).
                    untrustedContentHint: true,
                ),
                title: 'Site Search',
            ),
        ];
    }
}
