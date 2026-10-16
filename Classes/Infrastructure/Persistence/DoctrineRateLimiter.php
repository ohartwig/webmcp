<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Persistence;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Koh\WebMcp\Application\Contract\RateLimiterInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A fixed-window counter in the database, incremented atomically.
 *
 * The previous counter lived in the TYPO3 cache and was read, incremented
 * in PHP and written back — concurrent requests under-counted, and the
 * cache API has no increment of its own. A row per key with
 * `UPDATE … SET hits = hits + 1` is atomic on every database TYPO3 runs on,
 * and the table already pays one write per call for the audit log.
 *
 * Two keys are counted per call: the caller's address (IPv6 reduced to its
 * /64, because a single host usually holds the whole prefix) and the site,
 * which bounds what one site can be made to spend however many addresses
 * the caller has.
 */
final readonly class DoctrineRateLimiter implements RateLimiterInterface
{
    public const int MAX_PER_CLIENT = 60;
    public const int MAX_PER_SITE = 600;
    public const int WINDOW_SECONDS = 60;

    private const string TABLE = 'tx_webmcp_rate_limit';

    /** Rows older than this many windows are swept on about one call in a thousand. */
    private const int STALE_AFTER_WINDOWS = 60;

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    public function allow(string $address, string $siteIdentifier): bool
    {
        $window = intdiv($this->now(), self::WINDOW_SECONDS);

        $clientHits = $this->hit('client|' . self::normaliseAddress($address), $window);
        $siteHits = $this->hit('site|' . $siteIdentifier, $window);

        if (1 === random_int(1, 1000)) {
            $this->sweep($window);
        }

        return $clientHits <= self::MAX_PER_CLIENT && $siteHits <= self::MAX_PER_SITE;
    }

    /**
     * An IPv6 address is reduced to its /64 prefix; an IPv4 address is kept
     * as it is. Anything that does not parse is used verbatim, so an exotic
     * value still gets a budget of its own rather than a shared one.
     */
    public static function normaliseAddress(string $address): string
    {
        $binary = @inet_pton($address);
        if (false === $binary || 16 !== strlen($binary)) {
            return $address;
        }

        $prefix = substr($binary, 0, 8) . str_repeat("\0", 8);
        $normalised = inet_ntop($prefix);

        return false === $normalised ? $address : $normalised . '/64';
    }

    /**
     * Increments the counter of $key for $window and returns the new count.
     */
    private function hit(string $key, int $window): int
    {
        $identifier = hash('sha256', $key);
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);

        // The common case: the row exists for this window.
        $affected = $connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE identifier = ? AND window_start = ?',
            [$identifier, $window],
            [ParameterType::STRING, ParameterType::INTEGER],
        );
        if ($affected > 0) {
            return $this->currentHits($identifier, $window);
        }

        // A new window for a known key: reset the row.
        $affected = $connection->executeStatement(
            'UPDATE ' . self::TABLE . ' SET hits = 1, window_start = ? WHERE identifier = ? AND window_start <> ?',
            [$window, $identifier, $window],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::INTEGER],
        );
        if ($affected > 0) {
            return 1;
        }

        // An unknown key: insert, and if a concurrent request won the race,
        // count on its row.
        try {
            $connection->insert(self::TABLE, [
                'identifier' => $identifier,
                'window_start' => $window,
                'hits' => 1,
            ], ['identifier' => ParameterType::STRING, 'window_start' => ParameterType::INTEGER, 'hits' => ParameterType::INTEGER]);

            return 1;
        } catch (UniqueConstraintViolationException) {
            $connection->executeStatement(
                'UPDATE ' . self::TABLE . ' SET hits = hits + 1 WHERE identifier = ? AND window_start = ?',
                [$identifier, $window],
                [ParameterType::STRING, ParameterType::INTEGER],
            );

            return $this->currentHits($identifier, $window);
        }
    }

    private function currentHits(string $identifier, int $window): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $hits = $queryBuilder
            ->select('hits')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('identifier', $queryBuilder->createNamedParameter($identifier)),
                $queryBuilder->expr()->eq('window_start', $queryBuilder->createNamedParameter($window, ParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchOne();

        // The row was there a moment ago; if a sweep removed it in between,
        // the honest answer is "one call", the one we just made.
        return false === $hits ? 1 : (int) $hits;
    }

    private function sweep(int $currentWindow): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'window_start',
                    $queryBuilder->createNamedParameter($currentWindow - self::STALE_AFTER_WINDOWS, ParameterType::INTEGER),
                ),
            )
            ->executeStatement();
    }

    private function now(): int
    {
        return (int) $this->context->getPropertyFromAspect('date', 'timestamp', time());
    }
}
