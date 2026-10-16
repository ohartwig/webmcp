<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolHandler;

use Doctrine\DBAL\ArrayParameterType;
use Koh\WebMcp\Application\Contract\PageAccessInterface;
use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;

final readonly class SearchToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private PageAccessInterface $pageAccess,
    ) {}

    public function getToolName(): string
    {
        return 'search';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $query = mb_substr(trim($input['query'] ?? ''), 0, 200);
        if ('' === $query) {
            return ToolResult::error('Query parameter is required');
        }

        $maxResults = (int) ($context->settings['tools']['search']['maxResults'] ?? 20);
        $limit = min((int) ($input['limit'] ?? 10), $maxResults);
        if ($limit < 1) {
            $limit = 10;
        }

        // Only pages the visitor may read. A content element inherits the
        // restrictions of its page, and the element's own flags, which the
        // frontend restrictions below check, say nothing about that.
        $sitePageIds = $this->pageAccess->accessiblePageIds($context->rootPageId);
        if ([] === $sitePageIds) {
            return ToolResult::success([
                'query' => $query,
                'results' => [],
                'total' => 0,
            ]);
        }

        $results = [];

        $pageResults = $this->searchPages($query, $sitePageIds, $limit);
        foreach ($pageResults as $page) {
            $results[] = [
                'type' => 'page',
                'title' => $page['title'],
                'slug' => $page['slug'],
                'pageId' => $page['uid'],
                'description' => $page['description'] ?? '',
            ];
        }

        $contentResults = $this->searchContent($query, $sitePageIds, $limit);
        foreach ($contentResults as $content) {
            $results[] = [
                'type' => 'content',
                'header' => $content['header'],
                'bodytext' => mb_substr(strip_tags($content['bodytext'] ?? ''), 0, 200),
                'pageId' => $content['pid'],
                'contentType' => $content['CType'],
            ];
        }

        $results = array_slice($results, 0, $limit);

        return ToolResult::success([
            'query' => $query,
            'results' => $results,
            'total' => count($results),
        ]);
    }

    private function createQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->setRestrictions(new FrontendRestrictionContainer($this->context));

        return $queryBuilder;
    }

    /**
     * @param int[] $sitePageIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchPages(string $query, array $sitePageIds, int $limit): array
    {
        $queryBuilder = $this->createQueryBuilder('pages');
        $searchTerm = '%' . $queryBuilder->escapeLikeWildcards($query) . '%';

        return $queryBuilder
            ->select('uid', 'title', 'slug', 'description')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('title', $queryBuilder->createNamedParameter($searchTerm)),
                    $queryBuilder->expr()->like('description', $queryBuilder->createNamedParameter($searchTerm)),
                    $queryBuilder->expr()->like('abstract', $queryBuilder->createNamedParameter($searchTerm)),
                ),
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($sitePageIds, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @param int[] $sitePageIds
     *
     * @return array<int, array<string, mixed>>
     */
    private function searchContent(string $query, array $sitePageIds, int $limit): array
    {
        $queryBuilder = $this->createQueryBuilder('tt_content');
        $searchTerm = '%' . $queryBuilder->escapeLikeWildcards($query) . '%';

        return $queryBuilder
            ->select('uid', 'pid', 'header', 'bodytext', 'CType')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->or(
                    $queryBuilder->expr()->like('header', $queryBuilder->createNamedParameter($searchTerm)),
                    $queryBuilder->expr()->like('bodytext', $queryBuilder->createNamedParameter($searchTerm)),
                ),
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($sitePageIds, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', 0),
            )
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
