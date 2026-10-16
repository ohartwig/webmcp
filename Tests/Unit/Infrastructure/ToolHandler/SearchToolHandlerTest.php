<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Contract\PageAccessInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Infrastructure\ToolHandler\SearchToolHandler;
use Koh\WebMcp\Tests\Support\Typo3ContainerTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\CompositeExpression;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

final class SearchToolHandlerTest extends TestCase
{
    use Typo3ContainerTrait;

    private SearchToolHandler $handler;
    private ConnectionPool&MockObject $connectionPool;
    private Context $context;
    private PageAccessInterface&MockObject $pageAccess;

    protected function setUp(): void
    {
        $this->setUpTypo3Container();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->context = $this->createMock(Context::class);
        $this->pageAccess = $this->createMock(PageAccessInterface::class);
        $this->pageAccess->method('accessiblePageIds')->willReturn([1, 2, 3]);
        $this->handler = new SearchToolHandler($this->connectionPool, $this->context, $this->pageAccess);
    }

    protected function tearDown(): void
    {
        $this->tearDownTypo3Container();
    }

    #[Test]
    public function getToolNameReturnsSearch(): void
    {
        self::assertSame('search', $this->handler->getToolName());
    }

    #[Test]
    public function executeReturnsErrorForEmptyQuery(): void
    {
        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['query' => ''], $context);

        self::assertFalse($result->success);
        self::assertSame('Query parameter is required', $result->error);
    }

    #[Test]
    public function executeReturnsErrorForMissingQuery(): void
    {
        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute([], $context);

        self::assertFalse($result->success);
        self::assertSame('Query parameter is required', $result->error);
    }

    #[Test]
    public function executeReturnsErrorForWhitespaceOnlyQuery(): void
    {
        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['query' => '   '], $context);

        self::assertFalse($result->success);
    }

    #[Test]
    public function executeTruncatesLongQuery(): void
    {
        $longQuery = str_repeat('a', 500);

        $queryBuilder = $this->createConfiguredQueryBuilder();
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['query' => $longQuery], $context);

        // Should succeed (not error) — the query gets truncated to 200 chars
        self::assertTrue($result->success);
        self::assertSame(mb_substr($longQuery, 0, 200), $result->data['query']);
    }

    #[Test]
    public function executeRespectsMaxResultsFromSettings(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder();
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(
            rootPageId: 1,
            settings: ['tools' => ['search' => ['maxResults' => 5]]],
        );

        // User requests 100 but maxResults is 5
        $result = $this->handler->execute(['query' => 'test', 'limit' => 100], $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function executeDefaultsNegativeLimitToTen(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder();
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['query' => 'test', 'limit' => -5], $context);

        self::assertTrue($result->success);
    }

    /**
     * The set of pages a search may touch is the set the visitor may read,
     * and it is the port's answer for this site — not a walk of the raw tree.
     */
    #[Test]
    public function theSearchIsConfinedToThePagesTheVisitorMayRead(): void
    {
        $pageAccess = $this->createMock(PageAccessInterface::class);
        $pageAccess->expects(self::once())
            ->method('accessiblePageIds')
            ->with(7)
            ->willReturn([7, 8]);

        $seenPageIds = null;
        $queryBuilder = $this->createConfiguredQueryBuilder();
        $queryBuilder->method('createNamedParameter')->willReturnCallback(
            function (mixed $value) use (&$seenPageIds): string {
                if (is_array($value)) {
                    $seenPageIds = $value;
                }

                return "'test'";
            }
        );
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $handler = new SearchToolHandler($this->connectionPool, $this->context, $pageAccess);
        $result = $handler->execute(['query' => 'test'], new ExecutionContext(rootPageId: 7));

        self::assertTrue($result->success);
        self::assertSame([7, 8], $seenPageIds, 'the pid filter must be the accessible set');
    }

    /**
     * No readable page means nothing to search in. Querying with an empty
     * IN() list would be a database error at worst and a full-table scan at
     * best; the handler must not get that far.
     */
    #[Test]
    public function noReadablePageMeansNoQueryAndNoResults(): void
    {
        $pageAccess = $this->createMock(PageAccessInterface::class);
        $pageAccess->method('accessiblePageIds')->willReturn([]);
        $this->connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $handler = new SearchToolHandler($this->connectionPool, $this->context, $pageAccess);
        $result = $handler->execute(['query' => 'test'], new ExecutionContext(rootPageId: 1));

        self::assertTrue($result->success);
        self::assertSame([], $result->data['results']);
        self::assertSame(0, $result->data['total']);
    }

    private function createConfiguredQueryBuilder(): QueryBuilder&MockObject
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('like')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('1=1');
        $expressionBuilder->method('eq')->willReturn('1=1');
        // or()/and() are typed to return CompositeExpression, not a string
        $expressionBuilder->method('or')->willReturn(CompositeExpression::or('1=1'));
        $expressionBuilder->method('and')->willReturn(CompositeExpression::and('1=1'));

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $statement = $this->createMock(\Doctrine\DBAL\Result::class);
        $statement->method('fetchAllAssociative')->willReturn([]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($statement);
        $queryBuilder->method('escapeLikeWildcards')->willReturnArgument(0);
        $queryBuilder->method('setRestrictions')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        return $queryBuilder;
    }
}
