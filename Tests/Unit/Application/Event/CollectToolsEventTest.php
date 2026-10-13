<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Event;

use Koh\WebMcp\Application\Event\CollectToolsEvent;
use Koh\WebMcp\Domain\Model\ToolDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollectToolsEventTest extends TestCase
{
    #[Test]
    public function getToolsReturnsInitialTools(): void
    {
        $tool = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);
        $event = new CollectToolsEvent([$tool], []);

        self::assertCount(1, $event->getTools());
        self::assertSame('search', $event->getTools()[0]->name);
    }

    #[Test]
    public function setToolsReplacesTools(): void
    {
        $tool1 = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);
        $tool2 = new ToolDefinition(name: 'navigation', description: 'Nav', inputSchema: []);
        $event = new CollectToolsEvent([$tool1], []);

        $event->setTools([$tool2]);

        self::assertCount(1, $event->getTools());
        self::assertSame('navigation', $event->getTools()[0]->name);
    }

    #[Test]
    public function addToolAppendsToExistingTools(): void
    {
        $tool1 = new ToolDefinition(name: 'search', description: 'Search', inputSchema: []);
        $tool2 = new ToolDefinition(name: 'navigation', description: 'Nav', inputSchema: []);
        $event = new CollectToolsEvent([$tool1], []);

        $event->addTool($tool2);

        self::assertCount(2, $event->getTools());
    }

    #[Test]
    public function getSiteSettingsReturnsPassedSettings(): void
    {
        $settings = ['enabled' => true, 'tools' => ['search' => ['enabled' => true]]];
        $event = new CollectToolsEvent([], $settings);

        self::assertSame($settings, $event->getSiteSettings());
    }
}
