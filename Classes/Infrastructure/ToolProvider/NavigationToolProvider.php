<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolProvider;

use Koh\WebMcp\Domain\Contract\ToolProviderInterface;
use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;

final readonly class NavigationToolProvider implements ToolProviderInterface
{
    public function getIdentifier(): string
    {
        return 'navigation';
    }

    public function getTools(array $settings = []): array
    {
        return [
            new ToolDefinition(
                name: 'navigation',
                description: 'Get the site navigation tree as structured JSON',
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'maxDepth' => [
                            'type' => 'integer',
                            'description' => 'Maximum depth of the navigation tree',
                            'default' => 4,
                        ],
                    ],
                ],
                annotations: new ToolAnnotations(
                    readOnlyHint: true,
                    // Tree carries page titles which are editor-set strings — small
                    // injection surface, but flag as untrusted for consistency.
                    untrustedContentHint: true,
                ),
                title: 'Navigation Tree',
            ),
        ];
    }
}
