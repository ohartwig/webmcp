<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Contract\PageAccessInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Infrastructure\ToolHandler\PageContentToolHandler;
use Koh\WebMcp\Tests\Support\Typo3ContainerTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\QueryRestrictionContainerInterface;

final class PageContentToolHandlerTest extends TestCase
{
    use Typo3ContainerTrait;

    private PageContentToolHandler $handler;
    private ConnectionPool&MockObject $connectionPool;
    private PageAccessInterface&MockObject $pageAccess;

    protected function setUp(): void
    {
        $this->setUpTypo3Container();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $this->pageAccess = $this->createMock(PageAccessInterface::class);
        $context = $this->createMock(Context::class);
        $this->handler = new PageContentToolHandler($this->connectionPool, $context, $this->pageAccess);
    }

    protected function tearDown(): void
    {
        $this->tearDownTypo3Container();
    }

    #[Test]
    public function getToolNameReturnsPageContent(): void
    {
        self::assertSame('page-content', $this->handler->getToolName());
    }

    #[Test]
    public function executeReturnsErrorWhenBothParamsMissing(): void
    {
        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute([], $context);

        self::assertFalse($result->success);
        self::assertSame('Either pageId or slug parameter is required', $result->error);
    }

    #[Test]
    public function executeReturnsGenericErrorForMissingPage(): void
    {
        $this->pageAccess->method('isAccessible')->willReturn(false);
        $this->connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['pageId' => 999], $context);

        self::assertFalse($result->success);
        // Should NOT contain the pageId or slug in the error message
        self::assertSame('Page not found', $result->error);
    }

    /**
     * A page the visitor may not read — hidden, scheduled, behind a login,
     * under a protected section or in the recycler — is indistinguishable
     * from a page that does not exist. Page uids are small integers, so
     * anything else would let a caller enumerate the restricted ones.
     */
    #[Test]
    public function aPageTheVisitorMayNotReadIsReportedAsNotFound(): void
    {
        $this->pageAccess->expects(self::once())
            ->method('isAccessible')
            ->with(42, 1)
            ->willReturn(false);
        $this->connectionPool->expects(self::never())->method('getQueryBuilderForTable');

        $result = $this->handler->execute(['pageId' => 42], new ExecutionContext(rootPageId: 1));

        self::assertFalse($result->success);
        self::assertSame('Page not found', $result->error);
    }

    #[Test]
    public function executeReturnsGenericErrorForUnknownSlug(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder(null);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['slug' => '/nonexistent'], $context);

        self::assertFalse($result->success);
        // Should NOT contain the slug in the error message
        self::assertSame('Page not found', $result->error);
        self::assertStringNotContainsString('/nonexistent', $result->error);
    }

    /**
     * Two sites may share a slug, and a slug may belong to a page the visitor
     * may not read. The resolver has to skip both and take the first candidate
     * that is readable on this site.
     */
    #[Test]
    public function slugResolutionSkipsCandidatesTheVisitorMayNotRead(): void
    {
        $this->pageAccess->method('isAccessible')->willReturnMap([
            [30, 1, false],
            [31, 1, true],
        ]);

        $pageRow = ['uid' => 31, 'title' => 'Readable', 'slug' => '/shared', 'description' => '', 'abstract' => ''];
        $this->setupQueryBuilderForPageContent($pageRow, [], [['uid' => 30], ['uid' => 31]]);

        $result = $this->handler->execute(['slug' => '/shared'], new ExecutionContext(rootPageId: 1));

        self::assertTrue($result->success);
        self::assertSame(31, $result->data['page']['pageId']);
    }

    #[Test]
    public function executeReturnsPageContent(): void
    {
        $this->pageAccess->method('isAccessible')->willReturn(true);

        $pageRow = [
            'uid' => 10,
            'title' => 'Test Page',
            'slug' => '/test',
            'description' => 'A test page',
            'abstract' => '',
        ];
        $contentRows = [
            [
                'uid' => 100,
                'CType' => 'text',
                'colPos' => 0,
                'header' => 'Header',
                'subheader' => '',
                'bodytext' => '<p>Hello World</p>',
            ],
        ];

        $this->setupQueryBuilderForPageContent($pageRow, $contentRows);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['pageId' => 10], $context);

        self::assertTrue($result->success);
        self::assertSame(10, $result->data['page']['pageId']);
        self::assertSame('Test Page', $result->data['page']['title']);
        self::assertCount(1, $result->data['content']);
        self::assertSame('text', $result->data['content'][0]['type']);
        self::assertSame('Hello World', $result->data['content'][0]['bodytext']);
    }

    #[Test]
    public function executeStripsHtmlFromBodytext(): void
    {
        $this->pageAccess->method('isAccessible')->willReturn(true);

        $pageRow = [
            'uid' => 10,
            'title' => 'Test',
            'slug' => '/test',
            'description' => '',
            'abstract' => '',
        ];
        $contentRows = [
            [
                'uid' => 100,
                'CType' => 'text',
                'colPos' => 0,
                'header' => '',
                'subheader' => '',
                'bodytext' => '<script>alert("xss")</script><p>Safe content</p>',
            ],
        ];

        $this->setupQueryBuilderForPageContent($pageRow, $contentRows);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['pageId' => 10], $context);

        self::assertTrue($result->success);
        self::assertStringNotContainsString('<script>', $result->data['content'][0]['bodytext']);
        self::assertStringContainsString('Safe content', $result->data['content'][0]['bodytext']);
    }

    private function createConfiguredQueryBuilder(?array $singleRow): QueryBuilder
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $statement = $this->createMock(\Doctrine\DBAL\Result::class);
        $statement->method('fetchAssociative')->willReturn($singleRow ?? false);
        $statement->method('fetchAllAssociative')->willReturn($singleRow ? [$singleRow] : []);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($statement);
        $queryBuilder->method('createNamedParameter')->willReturn("'test'");
        $queryBuilder->method('setRestrictions')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);

        return $queryBuilder;
    }

    /**
     * @param array<string, mixed>            $pageRow
     * @param list<array<string, mixed>>      $contentRows
     * @param list<array<string, mixed>>|null $slugRows    candidates a slug lookup returns; null when no slug is resolved
     */
    private function setupQueryBuilderForPageContent(array $pageRow, array $contentRows, ?array $slugRows = null): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        $slugStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $slugStatement->method('fetchAllAssociative')->willReturn($slugRows ?? []);

        $pageStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $pageStatement->method('fetchAssociative')->willReturn($pageRow);

        $contentStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $contentStatement->method('fetchAllAssociative')->willReturn($contentRows);

        // Rootline checks go through the port now, so the queries are: an
        // optional slug lookup, then the page, then its content.
        $sequence = null === $slugRows
            ? [$pageStatement, $contentStatement]
            : [$slugStatement, $pageStatement, $contentStatement];

        $callCount = 0;
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setMaxResults')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('addOrderBy')->willReturnSelf();
        $queryBuilder->method('createNamedParameter')->willReturn("'test'");
        $queryBuilder->method('setRestrictions')->willReturnSelf();
        $queryBuilder->method('getRestrictions')->willReturn($restrictions);
        $queryBuilder->method('executeQuery')->willReturnCallback(
            function () use (&$callCount, $sequence, $pageStatement) {
                return $sequence[$callCount++] ?? $pageStatement;
            }
        );

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
    }
}
