<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Application\Event;

use Koh\WebMcp\Application\Event\CollectContextsEvent;
use Koh\WebMcp\Domain\Model\ContextDefinition;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CollectContextsEventTest extends TestCase
{
    #[Test]
    public function getContextsReturnsInitialContexts(): void
    {
        $context = new ContextDefinition(name: 'test', description: 'desc', data: []);
        $event = new CollectContextsEvent([$context], []);

        self::assertCount(1, $event->getContexts());
        self::assertSame('test', $event->getContexts()[0]->name);
    }

    #[Test]
    public function setContextsReplacesContexts(): void
    {
        $event = new CollectContextsEvent([], []);
        $newContext = new ContextDefinition(name: 'new', description: 'desc', data: []);

        $event->setContexts([$newContext]);

        self::assertCount(1, $event->getContexts());
        self::assertSame('new', $event->getContexts()[0]->name);
    }

    #[Test]
    public function addContextAppendsToList(): void
    {
        $event = new CollectContextsEvent([], ['key' => 'value']);

        $event->addContext(new ContextDefinition(name: 'added', description: 'desc', data: []));

        self::assertCount(1, $event->getContexts());
        self::assertSame(['key' => 'value'], $event->getSiteSettings());
    }
}
