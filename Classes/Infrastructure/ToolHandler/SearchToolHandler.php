<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use Doctrine\DBAL\ArrayParameterType;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;

final readonly class SearchToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
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

        $sitePageIds = $this->collectSitePageIds($context->rootPageId);

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

    /**
     * Collect all page IDs belonging to the site's page tree.
     *
     * @return int[]
     */
    private function collectSitePageIds(int $rootPageId): array
    {
        $pageIds = [$rootPageId];
        $this->collectChildPageIds($rootPageId, $pageIds, 0, 20);

        return $pageIds;
    }

    /**
     * @param int[] $pageIds
     */
    private function collectChildPageIds(int $parentId, array &$pageIds, int $depth, int $maxDepth): void
    {
        if ($depth >= $maxDepth || count($pageIds) > 10000) {
            return;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $children = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentId, \Doctrine\DBAL\ParameterType::INTEGER)))
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($children as $child) {
            $childId = (int) $child['uid'];
            $pageIds[] = $childId;
            $this->collectChildPageIds($childId, $pageIds, $depth + 1, $maxDepth);
        }
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
