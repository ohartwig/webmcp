<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ToolAnnotations;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolDefinitionTest extends TestCase
{
    #[Test]
    public function constructsSetsProperties(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: true);
        $schema = [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
            ],
            'required' => ['query'],
        ];

        $definition = new ToolDefinition(
            name: 'search',
            description: 'Search content',
            inputSchema: $schema,
            annotations: $annotations,
            apiEndpoint: '/_webmcp/api/search',
        );

        self::assertSame('search', $definition->name);
        self::assertSame('Search content', $definition->description);
        self::assertSame($schema, $definition->inputSchema);
        self::assertSame($annotations, $definition->annotations);
        self::assertSame('/_webmcp/api/search', $definition->apiEndpoint);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $definition = new ToolDefinition(
            name: 'navigation',
            description: 'Get navigation',
            inputSchema: ['type' => 'object'],
            annotations: new ToolAnnotations(readOnlyHint: false),
        );

        $array = $definition->toArray();

        self::assertSame('navigation', $array['name']);
        self::assertSame('Get navigation', $array['description']);
        self::assertSame(['type' => 'object'], $array['inputSchema']);
        self::assertSame(['readOnlyHint' => false, 'untrustedContentHint' => false], $array['annotations']);
    }

    #[Test]
    public function defaultAnnotationsAreReadOnly(): void
    {
        $definition = new ToolDefinition(
            name: 'test',
            description: 'Test tool',
            inputSchema: [],
        );

        self::assertTrue($definition->annotations->readOnlyHint);
    }

    #[Test]
    public function defaultApiEndpointIsEmpty(): void
    {
        $definition = new ToolDefinition(
            name: 'test',
            description: 'Test',
            inputSchema: [],
        );

        self::assertSame('', $definition->apiEndpoint);
    }
}
