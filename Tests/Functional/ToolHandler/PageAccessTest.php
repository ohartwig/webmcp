<?php

declare(strict_types=1);

namespace Koh\WebMcp\Tests\Functional\ToolHandler;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Infrastructure\Page\PageAccessResolver;
use Koh\WebMcp\Infrastructure\ToolHandler\PageContentToolHandler;
use Koh\WebMcp\Infrastructure\ToolHandler\SearchToolHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The tools answer anonymous callers, so they must not say more than the
 * frontend would. These tests run the real handlers against a real page tree
 * (AUD-003-F01): a hidden page, a scheduled one, one behind a login, a
 * protected section with "extend to subpages", a recycler, and the two cases
 * TYPO3 does allow — the child of a hidden page without that flag, and a page
 * inside a folder.
 */
final class PageAccessTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/webmcp'];

    private const int ROOT = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/PageAccess.csv');
    }

    #[Test]
    public function theAccessibleSetIsWhatTheFrontendWouldServe(): void
    {
        $ids = $this->get(PageAccessResolver::class)->accessiblePageIds(self::ROOT);

        // 1 root, 2 public, 11 the child of a hidden page without
        // extendToSubpages, 13 a page inside a folder — and nothing else.
        self::assertSame([1, 2, 11, 13], $ids);
    }

    #[Test]
    public function searchFindsContentOnReadablePagesOnly(): void
    {
        $result = $this->get(SearchToolHandler::class)->execute(
            ['query' => 'text alpha', 'limit' => 20],
            new ExecutionContext(rootPageId: self::ROOT),
        );

        self::assertTrue($result->success);
        $pageIds = array_map(static fn(array $hit): int => (int) $hit['pageId'], $result->data['results']);
        sort($pageIds);

        self::assertSame([2, 11, 13], $pageIds);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function pagesTheVisitorMayNotRead(): iterable
    {
        yield 'hidden page' => [3];
        yield 'page behind a frontend login' => [4];
        yield 'protected section itself' => [5];
        yield 'child of a protected section with extendToSubpages' => [6];
        yield 'the recycler' => [7];
        yield 'page inside the recycler' => [8];
        yield 'page scheduled for the future' => [9];
        yield 'hidden parent' => [10];
        yield 'folder' => [12];
        yield 'expired page' => [14];
        yield 'page that does not exist' => [999];
    }

    #[Test]
    #[DataProvider('pagesTheVisitorMayNotRead')]
    public function pageContentIsNotFoundForAPageTheVisitorMayNotRead(int $pageId): void
    {
        $result = $this->get(PageContentToolHandler::class)->execute(
            ['pageId' => $pageId],
            new ExecutionContext(rootPageId: self::ROOT),
        );

        self::assertFalse($result->success);
        self::assertSame('Page not found', $result->error);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function pagesTheVisitorMayRead(): iterable
    {
        yield 'public page' => [2, 'Public text alpha'];
        yield 'child of a hidden page without extendToSubpages' => [11, 'Orphan text alpha'];
        yield 'page inside a folder' => [13, 'Folder child text alpha'];
    }

    #[Test]
    #[DataProvider('pagesTheVisitorMayRead')]
    public function pageContentIsReturnedForAReadablePage(int $pageId, string $expectedText): void
    {
        $result = $this->get(PageContentToolHandler::class)->execute(
            ['pageId' => $pageId],
            new ExecutionContext(rootPageId: self::ROOT),
        );

        self::assertTrue($result->success);
        self::assertSame($pageId, (int) $result->data['page']['pageId']);
        self::assertSame($expectedText, $result->data['content'][0]['bodytext']);
    }

    #[Test]
    public function aSlugOfARestrictedPageIsNotResolved(): void
    {
        $handler = $this->get(PageContentToolHandler::class);
        $context = new ExecutionContext(rootPageId: self::ROOT);

        self::assertFalse($handler->execute(['slug' => '/section/child'], $context)->success);
        self::assertTrue($handler->execute(['slug' => '/public'], $context)->success);
    }

    #[Test]
    public function aPageOfAnotherSiteIsNotFound(): void
    {
        // Root 2 is the public page; from its point of view the root page is
        // an ancestor, not a descendant, and must not resolve.
        $result = $this->get(PageContentToolHandler::class)->execute(
            ['pageId' => 1],
            new ExecutionContext(rootPageId: 2),
        );

        self::assertFalse($result->success);
    }
}
