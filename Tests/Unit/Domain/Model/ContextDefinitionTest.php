<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ContextDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextDefinitionTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $data = ['title' => 'Test Page', 'pageId' => 42];
        $context = new ContextDefinition(
            name: 'page-meta',
            description: 'Page metadata',
            data: $data,
        );

        self::assertSame('page-meta', $context->name);
        self::assertSame('Page metadata', $context->description);
        self::assertSame($data, $context->data);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $data = ['title' => 'Test'];
        $context = new ContextDefinition(name: 'test', description: 'desc', data: $data);

        $array = $context->toArray();

        self::assertSame('test', $array['name']);
        self::assertSame('desc', $array['description']);
        self::assertSame($data, $array['data']);
    }
}
