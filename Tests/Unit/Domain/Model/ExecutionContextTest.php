<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExecutionContextTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $settings = ['tools' => ['search' => ['maxResults' => 20]]];
        $context = new ExecutionContext(
            rootPageId: 42,
            languageId: 1,
            siteIdentifier: 'main',
            settings: $settings,
        );

        self::assertSame(42, $context->rootPageId);
        self::assertSame(1, $context->languageId);
        self::assertSame('main', $context->siteIdentifier);
        self::assertSame($settings, $context->settings);
    }

    #[Test]
    public function defaultValuesAreApplied(): void
    {
        $context = new ExecutionContext(rootPageId: 1);

        self::assertSame(0, $context->languageId);
        self::assertSame('', $context->siteIdentifier);
        self::assertSame([], $context->settings);
    }
}
