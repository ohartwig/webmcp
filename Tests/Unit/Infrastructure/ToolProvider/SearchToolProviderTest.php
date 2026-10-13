<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolProvider;

use Koh\WebMcp\Infrastructure\ToolProvider\SearchToolProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SearchToolProviderTest extends TestCase
{
    #[Test]
    public function identifierIsSearch(): void
    {
        $provider = new SearchToolProvider();
        self::assertSame('search', $provider->getIdentifier());
    }

    #[Test]
    public function providesOneToolNamedSearch(): void
    {
        $provider = new SearchToolProvider();
        $tools = $provider->getTools();

        self::assertCount(1, $tools);
        self::assertSame('search', $tools[0]->name);
    }

    #[Test]
    public function searchToolIsReadOnly(): void
    {
        $provider = new SearchToolProvider();
        $tools = $provider->getTools();

        self::assertTrue($tools[0]->annotations->readOnlyHint);
    }

    #[Test]
    public function searchToolRequiresQuery(): void
    {
        $provider = new SearchToolProvider();
        $tools = $provider->getTools();

        self::assertContains('query', $tools[0]->inputSchema['required']);
    }
}
