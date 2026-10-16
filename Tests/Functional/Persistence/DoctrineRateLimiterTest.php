<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Functional\Persistence;

use Koh\WebMcp\Infrastructure\Persistence\DoctrineRateLimiter;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The limit is only a limit if the sixty-first call is refused, which the
 * previous counter could not guarantee: it read, added one in PHP and wrote
 * back. These tests run the real statements against the real table
 * (AUD-003-F03, I-297).
 */
final class DoctrineRateLimiterTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/webmcp'];

    private DoctrineRateLimiter $limiter;
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = $this->get(Context::class);
        $this->at(1_800_000_000);
        $this->limiter = new DoctrineRateLimiter($this->get(ConnectionPool::class), $this->context);
    }

    private function at(int $timestamp): void
    {
        $this->context->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . $timestamp)));
    }

    #[Test]
    public function theSixtyFirstCallInAWindowIsRefused(): void
    {
        for ($i = 1; $i <= DoctrineRateLimiter::MAX_PER_CLIENT; ++$i) {
            self::assertTrue($this->limiter->allow('203.0.113.7', 'main'), "call {$i} must pass");
        }

        self::assertFalse($this->limiter->allow('203.0.113.7', 'main'));
        self::assertFalse($this->limiter->allow('203.0.113.7', 'main'), 'retrying does not earn a fresh budget');
    }

    #[Test]
    public function anotherAddressHasItsOwnBudget(): void
    {
        for ($i = 0; $i <= DoctrineRateLimiter::MAX_PER_CLIENT; ++$i) {
            $this->limiter->allow('203.0.113.7', 'main');
        }

        self::assertTrue($this->limiter->allow('203.0.113.8', 'main'));
    }

    #[Test]
    public function theBudgetReturnsWithTheNextWindow(): void
    {
        for ($i = 0; $i <= DoctrineRateLimiter::MAX_PER_CLIENT; ++$i) {
            $this->limiter->allow('203.0.113.7', 'main');
        }
        self::assertFalse($this->limiter->allow('203.0.113.7', 'main'));

        $this->at(1_800_000_000 + DoctrineRateLimiter::WINDOW_SECONDS);

        self::assertTrue($this->limiter->allow('203.0.113.7', 'main'));
    }

    /**
     * Many addresses against one site hit the site's own ceiling, which is
     * what bounds the cost of a search flood from a botnet.
     */
    #[Test]
    public function theSiteHasACeilingOfItsOwn(): void
    {
        $address = 0;
        for ($i = 0; $i < DoctrineRateLimiter::MAX_PER_SITE; ++$i) {
            // 10 calls per address keeps every address well under its own limit.
            $this->limiter->allow('10.0.' . intdiv($address, 256) . '.' . ($address % 256), 'main');
            if (0 === ($i + 1) % 10) {
                ++$address;
            }
        }

        self::assertFalse($this->limiter->allow('10.0.200.1', 'main'), 'the site is exhausted');
        self::assertTrue($this->limiter->allow('10.0.200.1', 'other'), 'another site is not');
    }

    #[Test]
    public function hitsAreCountedInTheTable(): void
    {
        $this->limiter->allow('203.0.113.7', 'main');
        $this->limiter->allow('203.0.113.7', 'main');

        $hits = $this->get(ConnectionPool::class)
            ->getConnectionForTable('tx_webmcp_rate_limit')
            ->select(['hits'], 'tx_webmcp_rate_limit', ['identifier' => hash('sha256', 'client|203.0.113.7')])
            ->fetchOne();

        self::assertSame(2, (int) $hits);
    }
}
