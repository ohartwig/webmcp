<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ToolAnnotations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolAnnotationsTest extends TestCase
{
    #[Test]
    public function defaultReadOnlyHintIsTrue(): void
    {
        $annotations = new ToolAnnotations();
        self::assertTrue($annotations->readOnlyHint);
    }

    #[Test]
    public function readOnlyHintCanBeSetToFalse(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: false);
        self::assertFalse($annotations->readOnlyHint);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: true);
        self::assertSame(['readOnlyHint' => true, 'untrustedContentHint' => false], $annotations->toArray());
    }

    #[Test]
    public function toArrayReflectsReadOnlyHintFalse(): void
    {
        $annotations = new ToolAnnotations(readOnlyHint: false);
        self::assertSame(['readOnlyHint' => false, 'untrustedContentHint' => false], $annotations->toArray());
    }
}
