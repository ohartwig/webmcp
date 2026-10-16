<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Http;

use Koh\WebMcp\Infrastructure\Http\UserAgentReducer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class UserAgentReducerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function userAgents(): iterable
    {
        yield 'desktop browser keeps the product tokens and loses the platform comment' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        ];
        yield 'mobile browser loses the device model' => [
            'Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/UD1A.231105.004) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 AppleWebKit/537.36 Chrome/120.0.0.0 Mobile Safari/537.36',
        ];
        yield 'a bot stays recognisable' => [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0',
        ];
        yield 'a bare client token survives' => ['curl/8.4.0', 'curl/8.4.0'];
        yield 'nested comments are removed whole' => ['Agent/1.0 (outer (inner) still) Tail/2', 'Agent/1.0 Tail/2'];
        yield 'an unbalanced comment drops the rest' => ['Agent/1.0 (never closed Tail/2', 'Agent/1.0'];
        yield 'non-ascii and control characters do not pass' => ["Ag\x00ent/1.0 Täst/2 Ok/3", 'Ok/3'];
        yield 'empty stays empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('userAgents')]
    public function reducesToProductTokens(string $header, string $expected): void
    {
        self::assertSame($expected, UserAgentReducer::reduce($header));
    }

    #[Test]
    public function theResultIsBounded(): void
    {
        $header = implode(' ', array_fill(0, 100, 'Product/1.0'));

        self::assertLessThanOrEqual(UserAgentReducer::MAX_LENGTH, strlen(UserAgentReducer::reduce($header)));
    }
}
