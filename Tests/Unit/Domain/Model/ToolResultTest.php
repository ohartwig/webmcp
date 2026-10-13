<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ToolResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolResultTest extends TestCase
{
    #[Test]
    public function successFactoryCreatesSuccessResult(): void
    {
        $result = ToolResult::success(['items' => [1, 2, 3]]);

        self::assertTrue($result->success);
        self::assertSame(['items' => [1, 2, 3]], $result->data);
        self::assertSame('', $result->error);
    }

    #[Test]
    public function errorFactoryCreatesErrorResult(): void
    {
        $result = ToolResult::error('Something went wrong');

        self::assertFalse($result->success);
        self::assertSame([], $result->data);
        self::assertSame('Something went wrong', $result->error);
    }

    #[Test]
    public function toArrayForSuccessContainsData(): void
    {
        $result = ToolResult::success(['key' => 'value']);
        $array = $result->toArray();

        self::assertTrue($array['success']);
        self::assertSame(['key' => 'value'], $array['data']);
        self::assertArrayNotHasKey('error', $array);
    }

    #[Test]
    public function toArrayForErrorContainsErrorMessage(): void
    {
        $result = ToolResult::error('fail');
        $array = $result->toArray();

        self::assertFalse($array['success']);
        self::assertSame('fail', $array['error']);
        self::assertArrayNotHasKey('data', $array);
    }
}
