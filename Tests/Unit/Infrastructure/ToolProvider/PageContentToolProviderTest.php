<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolProvider;

use Koh\WebMcp\Infrastructure\ToolProvider\PageContentToolProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageContentToolProviderTest extends TestCase
{
    #[Test]
    public function identifierIsPageContent(): void
    {
        $provider = new PageContentToolProvider();
        self::assertSame('page-content', $provider->getIdentifier());
    }

    #[Test]
    public function providesOneToolNamedPageContent(): void
    {
        $provider = new PageContentToolProvider();
        $tools = $provider->getTools();

        self::assertCount(1, $tools);
        self::assertSame('page-content', $tools[0]->name);
    }

    #[Test]
    public function pageContentToolIsReadOnly(): void
    {
        $provider = new PageContentToolProvider();
        self::assertTrue($provider->getTools()[0]->annotations->readOnlyHint);
    }

    #[Test]
    public function pageContentToolAcceptsPageIdOrSlug(): void
    {
        $provider = new PageContentToolProvider();
        $schema = $provider->getTools()[0]->inputSchema;

        self::assertArrayHasKey('pageId', $schema['properties']);
        self::assertArrayHasKey('slug', $schema['properties']);
    }
}
