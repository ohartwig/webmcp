<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\Persistence;

use Doctrine\DBAL\Result;
use Koh\WebMcp\Domain\Model\ToolInvocationLog;
use Koh\WebMcp\Infrastructure\Persistence\DoctrineToolInvocationLogRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

/**
 * This log is an audit trail of what agents invoked, so two things matter: the
 * row/domain translation must not lose or mistype anything, and every read has
 * to stay scoped to one site — leaking rows across sites would expose one
 * tenant's agent activity to another.
 */
final class DoctrineToolInvocationLogRepositoryTest extends TestCase
{
    private const TABLE = 'tx_webmcp_tool_log';

    private ConnectionPool&MockObject $connectionPool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectionPool = $this->createMock(ConnectionPool::class);
    }

    private function subject(): DoctrineToolInvocationLogRepository
    {
        return new DoctrineToolInvocationLogRepository($this->connectionPool);
    }

    #[Test]
    public function savingWritesTheLogAsARow(): void
    {
        $log = new ToolInvocationLog(
            toolName: 'form-contact',
            timestamp: new \DateTimeImmutable('@1785320000'),
            userAgent: 'SomeAgent/1.0',
            ipHash: 'abc123',
            siteIdentifier: 'main',
            rootPageId: 1,
            success: true,
            durationMs: 42,
            errorMessage: '',
        );

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('insert')
            ->with(self::TABLE, $log->toArray())
            ->willReturn(1);

        $this->connectionPool->method('getConnectionForTable')->with(self::TABLE)->willReturn($connection);

        $this->subject()->save($log);
    }

    #[Test]
    public function rowsAreHydratedBackIntoLogs(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->method('executeQuery')->willReturn($this->resultReturningRows([
            [
                'tool_name' => 'form-contact',
                'crdate' => '1785320000',
                'user_agent' => 'SomeAgent/1.0',
                'ip_hash' => 'abc123',
                'site_identifier' => 'main',
                'root_page_id' => '1',
                'success' => '1',
                'duration_ms' => '42',
                'error_message' => null,
            ],
        ]));

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $logs = $this->subject()->findBySite('main');

        self::assertCount(1, $logs);
        self::assertSame('form-contact', $logs[0]->toolName);
        self::assertSame(1785320000, $logs[0]->timestamp->getTimestamp());
        self::assertSame(1, $logs[0]->rootPageId);
        self::assertTrue($logs[0]->success);
        self::assertSame(42, $logs[0]->durationMs);
        self::assertSame('', $logs[0]->errorMessage, 'a null error column must not become null on the model');
    }

    /**
     * The listing is paginated, so limit and offset have to reach the query
     * rather than being silently dropped.
     */
    #[Test]
    public function paginationIsPassedToTheQuery(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->expects(self::once())->method('setMaxResults')->with(25)->willReturnSelf();
        $queryBuilder->expects(self::once())->method('setFirstResult')->with(50)->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($this->resultReturningRows([]));

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertSame([], $this->subject()->findBySite('main', 25, 50));
    }

    /**
     * Newest first — the module shows recent activity, and a wrong direction
     * would make the listing look empty on a busy site.
     */
    #[Test]
    public function listingIsOrderedNewestFirst(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->expects(self::once())->method('orderBy')->with('crdate', 'DESC')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($this->resultReturningRows([]));

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $this->subject()->findBySite('main');
    }

    #[Test]
    public function perToolCountsAreKeyedByToolName(): void
    {
        $queryBuilder = $this->queryBuilder();
        $queryBuilder->method('executeQuery')->willReturn($this->resultReturningRows([
            ['tool_name' => 'form-contact', 'cnt' => '12'],
            ['tool_name' => 'search', 'cnt' => '3'],
        ]));

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertSame(
            ['form-contact' => 12, 'search' => 3],
            $this->subject()->countByToolForSite('main'),
        );
    }

    #[Test]
    public function countingReturnsAnInteger(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchOne')->willReturn('17');

        $queryBuilder = $this->queryBuilder();
        $queryBuilder->method('count')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($result);

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertSame(17, $this->subject()->countBySite('main'));
    }

    /**
     * The cutoff is compared against the crdate integer column, so it has to go
     * in as a unix timestamp rather than a formatted date.
     */
    #[Test]
    public function deletionUsesTheCutoffAsAUnixTimestamp(): void
    {
        $cutoff = new \DateTimeImmutable('@1785320000');

        $expression = $this->createMock(ExpressionBuilder::class);
        $expression->expects(self::once())->method('lt')->with('crdate', ':dcValue1')->willReturn('crdate < :dcValue1');

        $queryBuilder = $this->queryBuilder($expression);
        $queryBuilder->expects(self::once())
            ->method('createNamedParameter')
            ->with(1785320000, self::anything())
            ->willReturn(':dcValue1');
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->expects(self::once())->method('executeStatement')->willReturn(9);

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        self::assertSame(9, $this->subject()->deleteOlderThan($cutoff));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function resultReturningRows(array $rows): Result&MockObject
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($rows);

        return $result;
    }

    private function queryBuilder(?ExpressionBuilder $expression = null): QueryBuilder&MockObject
    {
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('getRestrictions')->willReturn($this->createMock(QueryRestrictionContainerInterface::class));
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('addSelectLiteral')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('groupBy')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('setFirstResult')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn(':dcValue1');
        $queryBuilder->method('expr')->willReturn($expression ?? $this->createMock(ExpressionBuilder::class));

        return $queryBuilder;
    }
}
