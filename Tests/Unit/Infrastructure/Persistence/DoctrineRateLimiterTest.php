<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Persistence;

use Koh\WebMcp\Infrastructure\Persistence\DoctrineRateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The counting itself is exercised against a real table in the functional
 * suite; this covers how an address becomes a key.
 */
final class DoctrineRateLimiterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4 is kept' => ['203.0.113.7', '203.0.113.7'];
        yield 'IPv6 is reduced to its /64' => ['2001:db8:1234:5678:9abc:def0:1234:5678', '2001:db8:1234:5678::/64'];
        yield 'two hosts in one /64 share a budget' => ['2001:db8:1234:5678::1', '2001:db8:1234:5678::/64'];
        yield 'an IPv4-mapped IPv6 address lands on its /64' => ['::ffff:203.0.113.7', '::/64'];
        yield 'garbage is used verbatim' => ['not-an-address', 'not-an-address'];
        yield 'empty stays empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function normalisesTheAddress(string $address, string $expected): void
    {
        self::assertSame($expected, DoctrineRateLimiter::normaliseAddress($address));
    }
}
