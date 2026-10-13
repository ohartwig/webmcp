<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolHandler;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Infrastructure\ToolHandler\PageContentToolHandler;
use PHPUnit\Framework\Attributes\Test;
use Koh\WebMcp\Tests\Support\Typo3ContainerTrait;
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
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        $this->setUpTypo3Container();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $context = $this->createMock(Context::class);
        $this->handler = new PageContentToolHandler($this->connectionPool, $context);
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
        $queryBuilder = $this->createConfiguredQueryBuilder(null);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['pageId' => 999], $context);

        self::assertFalse($result->success);
        // Should NOT contain the pageId or slug in the error message
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

    #[Test]
    public function executeReturnsPageContent(): void
    {
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

    private function setupQueryBuilderForPageContent(array $pageRow, array $contentRows): void
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');

        $restrictions = $this->createMock(QueryRestrictionContainerInterface::class);
        $restrictions->method('removeAll')->willReturnSelf();

        // For rootline check: returns page with pid=1 (which is rootPageId)
        $rootlineStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $rootlineStatement->method('fetchAssociative')->willReturn(['pid' => 1]);

        // For page query
        $pageStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $pageStatement->method('fetchAssociative')->willReturn($pageRow);

        // For content query
        $contentStatement = $this->createMock(\Doctrine\DBAL\Result::class);
        $contentStatement->method('fetchAllAssociative')->willReturn($contentRows);

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
            function () use (&$callCount, $rootlineStatement, $pageStatement, $contentStatement) {
                ++$callCount;

                return match ($callCount) {
                    1 => $rootlineStatement,  // pageExistsInSite check
                    2 => $pageStatement,      // getPage
                    3 => $contentStatement,   // getContentElements
                    default => $pageStatement,
                };
            }
        );

        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);
    }
}
