<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Domain\Model;

use Koh\WebMcp\Domain\Model\ToolInvocationLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ToolInvocationLogTest extends TestCase
{
    #[Test]
    public function constructSetsAllProperties(): void
    {
        $timestamp = new \DateTimeImmutable('2026-03-23 12:00:00');
        $log = new ToolInvocationLog(
            toolName: 'search',
            timestamp: $timestamp,
            userAgent: 'TestAgent/1.0',
            ipHash: 'abc123',
            siteIdentifier: 'main',
            rootPageId: 1,
            success: true,
            durationMs: 42,
            errorMessage: '',
        );

        self::assertSame('search', $log->toolName);
        self::assertSame($timestamp, $log->timestamp);
        self::assertSame('TestAgent/1.0', $log->userAgent);
        self::assertSame('abc123', $log->ipHash);
        self::assertSame('main', $log->siteIdentifier);
        self::assertSame(1, $log->rootPageId);
        self::assertTrue($log->success);
        self::assertSame(42, $log->durationMs);
        self::assertSame('', $log->errorMessage);
    }

    #[Test]
    public function toArrayReturnsCorrectStructure(): void
    {
        $timestamp = new \DateTimeImmutable('2026-03-23 12:00:00');
        $log = new ToolInvocationLog(
            toolName: 'search',
            timestamp: $timestamp,
            userAgent: 'TestAgent/1.0',
            ipHash: 'abc123',
            siteIdentifier: 'main',
            rootPageId: 1,
            success: false,
            durationMs: 100,
            errorMessage: 'Not found',
        );

        $array = $log->toArray();

        self::assertSame('search', $array['tool_name']);
        self::assertSame($timestamp->getTimestamp(), $array['tstamp']);
        self::assertSame($timestamp->getTimestamp(), $array['crdate']);
        self::assertSame('TestAgent/1.0', $array['user_agent']);
        self::assertSame('abc123', $array['ip_hash']);
        self::assertSame('main', $array['site_identifier']);
        self::assertSame(1, $array['root_page_id']);
        self::assertSame(0, $array['success']);
        self::assertSame(100, $array['duration_ms']);
        self::assertSame('Not found', $array['error_message']);
    }

    #[Test]
    public function successIsMappedToInteger(): void
    {
        $log = new ToolInvocationLog(
            toolName: 'search',
            timestamp: new \DateTimeImmutable(),
            userAgent: '',
            ipHash: '',
            siteIdentifier: '',
            rootPageId: 1,
            success: true,
            durationMs: 0,
        );

        self::assertSame(1, $log->toArray()['success']);
    }
}
