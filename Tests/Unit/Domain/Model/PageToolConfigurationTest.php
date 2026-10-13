<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\PageToolConfiguration;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PageToolConfigurationTest extends TestCase
{
    #[Test]
    public function emptyConfigurationHasNoOverrides(): void
    {
        $config = new PageToolConfiguration();

        self::assertFalse($config->hasOverrides());
        self::assertNull($config->isToolEnabled('search'));
    }

    #[Test]
    public function isToolEnabledReturnsOverrideValue(): void
    {
        $config = new PageToolConfiguration(['search' => false, 'navigation' => true]);

        self::assertTrue($config->hasOverrides());
        self::assertFalse($config->isToolEnabled('search'));
        self::assertTrue($config->isToolEnabled('navigation'));
        self::assertNull($config->isToolEnabled('page-content'));
    }
}
