<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolProvider;

use Koh\WebMcp\Infrastructure\ToolProvider\NavigationToolProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NavigationToolProviderTest extends TestCase
{
    #[Test]
    public function identifierIsNavigation(): void
    {
        $provider = new NavigationToolProvider();
        self::assertSame('navigation', $provider->getIdentifier());
    }

    #[Test]
    public function providesOneToolNamedNavigation(): void
    {
        $provider = new NavigationToolProvider();
        $tools = $provider->getTools();

        self::assertCount(1, $tools);
        self::assertSame('navigation', $tools[0]->name);
    }

    #[Test]
    public function navigationToolIsReadOnly(): void
    {
        $provider = new NavigationToolProvider();
        self::assertTrue($provider->getTools()[0]->annotations->readOnlyHint);
    }

    #[Test]
    public function navigationToolHasNoRequiredFields(): void
    {
        $provider = new NavigationToolProvider();
        $schema = $provider->getTools()[0]->inputSchema;

        self::assertArrayNotHasKey('required', $schema);
    }
}
