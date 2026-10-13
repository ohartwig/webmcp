<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Unit\Infrastructure\ToolHandler;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Infrastructure\ToolHandler\NavigationToolHandler;
use PHPUnit\Framework\Attributes\Test;
use Koh\WebMcp\Tests\Support\Typo3ContainerTrait;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Expression\ExpressionBuilder;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class NavigationToolHandlerTest extends TestCase
{
    use Typo3ContainerTrait;

    private NavigationToolHandler $handler;
    private ConnectionPool $connectionPool;

    protected function setUp(): void
    {
        $this->setUpTypo3Container();
        $this->connectionPool = $this->createMock(ConnectionPool::class);
        $context = $this->createMock(Context::class);
        $this->handler = new NavigationToolHandler($this->connectionPool, $context);
    }

    protected function tearDown(): void
    {
        $this->tearDownTypo3Container();
    }

    #[Test]
    public function getToolNameReturnsNavigation(): void
    {
        self::assertSame('navigation', $this->handler->getToolName());
    }

    #[Test]
    public function executeUsesRootPageIdFromContext(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder([]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 42);
        $result = $this->handler->execute([], $context);

        self::assertTrue($result->success);
        self::assertSame(42, $result->data['rootPageId']);
    }

    #[Test]
    public function executeIgnoresClientRootPageId(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder([]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        // Client sends rootPageId=999 but context has 42
        $context = new ExecutionContext(rootPageId: 42);
        $result = $this->handler->execute(['rootPageId' => 999], $context);

        self::assertTrue($result->success);
        self::assertSame(42, $result->data['rootPageId']);
    }

    #[Test]
    public function executeRespectsMaxDepthFromSettings(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder([]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(
            rootPageId: 1,
            settings: ['tools' => ['navigation' => ['maxDepth' => 2]]],
        );

        // User requests depth 10, but settings limit to 2
        $result = $this->handler->execute(['maxDepth' => 10], $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function executeDefaultsNegativeMaxDepthToOne(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder([]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['maxDepth' => -1], $context);

        self::assertTrue($result->success);
    }

    #[Test]
    public function executeReturnsTreeStructure(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder([
            ['uid' => 10, 'title' => 'Home', 'slug' => '/', 'doktype' => 1, 'nav_hide' => 0],
            ['uid' => 11, 'title' => 'About', 'slug' => '/about', 'doktype' => 1, 'nav_hide' => 0],
        ]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['maxDepth' => 1], $context);

        self::assertTrue($result->success);
        self::assertCount(2, $result->data['navigation']);
        self::assertSame('Home', $result->data['navigation'][0]['title']);
        self::assertSame('page', $result->data['navigation'][0]['type']);
    }

    #[Test]
    public function executeMapsDokTypes(): void
    {
        $queryBuilder = $this->createConfiguredQueryBuilder([
            ['uid' => 10, 'title' => 'Page', 'slug' => '/page', 'doktype' => 1, 'nav_hide' => 0],
            ['uid' => 11, 'title' => 'External', 'slug' => '/ext', 'doktype' => 3, 'nav_hide' => 0],
            ['uid' => 12, 'title' => 'Shortcut', 'slug' => '/sc', 'doktype' => 4, 'nav_hide' => 0],
        ]);
        $this->connectionPool->method('getQueryBuilderForTable')->willReturn($queryBuilder);

        $context = new ExecutionContext(rootPageId: 1);
        $result = $this->handler->execute(['maxDepth' => 1], $context);

        self::assertSame('page', $result->data['navigation'][0]['type']);
        self::assertSame('external_url', $result->data['navigation'][1]['type']);
        self::assertSame('shortcut', $result->data['navigation'][2]['type']);
    }

    private function createConfiguredQueryBuilder(array $rows): QueryBuilder
    {
        $expressionBuilder = $this->createMock(ExpressionBuilder::class);
        $expressionBuilder->method('eq')->willReturn('1=1');
        $expressionBuilder->method('in')->willReturn('1=1');

        $statement = $this->createMock(\Doctrine\DBAL\Result::class);
        $statement->method('fetchAllAssociative')->willReturn($rows);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $queryBuilder->method('expr')->willReturn($expressionBuilder);
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('orderBy')->willReturnSelf();
        $queryBuilder->method('executeQuery')->willReturn($statement);
        $queryBuilder->method('setRestrictions')->willReturnSelf();

        return $queryBuilder;
    }
}
