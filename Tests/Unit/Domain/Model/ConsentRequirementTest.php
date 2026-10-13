<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ConsentRequirement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConsentRequirementTest extends TestCase
{
    #[Test]
    public function constructWithDefaults(): void
    {
        $consent = new ConsentRequirement(toolName: 'search');

        self::assertSame('search', $consent->toolName);
        self::assertFalse($consent->required);
        self::assertSame('functional', $consent->category);
        self::assertSame('native', $consent->provider);
    }

    #[Test]
    public function constructWithAllParameters(): void
    {
        $consent = new ConsentRequirement(
            toolName: 'search',
            required: true,
            category: 'analytics',
            provider: 'klaro',
        );

        self::assertTrue($consent->required);
        self::assertSame('analytics', $consent->category);
        self::assertSame('klaro', $consent->provider);
    }
}
