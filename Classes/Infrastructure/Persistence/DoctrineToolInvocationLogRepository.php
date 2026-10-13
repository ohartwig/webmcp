<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Persistence;

use Doctrine\DBAL\ParameterType;
use Koh\WebMcp\Domain\Contract\ToolInvocationLogRepositoryInterface;
use Koh\WebMcp\Domain\Model\ToolInvocationLog;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class DoctrineToolInvocationLogRepository implements ToolInvocationLogRepositoryInterface
{
    private const string TABLE = 'tx_webmcp_tool_log';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function save(ToolInvocationLog $log): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $connection->insert(self::TABLE, $log->toArray());
    }

    /**
     * @return ToolInvocationLog[]
     */
    public function findBySite(string $siteIdentifier, int $limit = 100, int $offset = 0): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->orderBy('crdate', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map([$this, 'hydrate'], $rows);
    }

    /**
     * @return array<string, int>
     */
    public function countByToolForSite(string $siteIdentifier): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        $rows = $queryBuilder
            ->select('tool_name')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->groupBy('tool_name')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['tool_name']] = (int) $row['cnt'];
        }

        return $result;
    }

    public function countBySite(string $siteIdentifier): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return (int) $queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                )
            )
            ->executeQuery()
            ->fetchOne();
    }

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->delete(self::TABLE)
            ->where(
                $queryBuilder->expr()->lt(
                    'crdate',
                    $queryBuilder->createNamedParameter($cutoff->getTimestamp(), ParameterType::INTEGER)
                )
            )
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ToolInvocationLog
    {
        return new ToolInvocationLog(
            toolName: (string) $row['tool_name'],
            timestamp: new \DateTimeImmutable()->setTimestamp((int) $row['crdate']),
            userAgent: (string) $row['user_agent'],
            ipHash: (string) $row['ip_hash'],
            siteIdentifier: (string) $row['site_identifier'],
            rootPageId: (int) $row['root_page_id'],
            success: (bool) $row['success'],
            durationMs: (int) $row['duration_ms'],
            errorMessage: (string) ($row['error_message'] ?? ''),
        );
    }
}
