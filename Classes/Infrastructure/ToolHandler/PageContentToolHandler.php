<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolHandler;

use Doctrine\DBAL\ParameterType;
use Koh\WebMcp\Application\Contract\PageAccessInterface;
use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;

final readonly class PageContentToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private PageAccessInterface $pageAccess,
    ) {}

    public function getToolName(): string
    {
        return 'page-content';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $pageId = $input['pageId'] ?? null;
        $slug = $input['slug'] ?? null;

        if (null === $pageId && null === $slug) {
            return ToolResult::error('Either pageId or slug parameter is required');
        }

        // $slug cannot be null here — the guard above returned when both were.
        if (null === $pageId) {
            $pageId = $this->resolvePageIdBySlug((string) $slug, $context->rootPageId);
            if (null === $pageId) {
                return ToolResult::error('Page not found');
            }
        }

        $pageId = (int) $pageId;

        // The page must belong to this site and be readable for the visitor,
        // including what it inherits from its rootline. A page uid is a small
        // integer; "not found" is the only answer a page the visitor may not
        // see gets, whether or not it exists.
        if (!$this->pageAccess->isAccessible($pageId, $context->rootPageId)) {
            return ToolResult::error('Page not found');
        }

        $page = $this->getPage($pageId);
        if (null === $page) {
            return ToolResult::error('Page not found');
        }

        $contentElements = $this->getContentElements($pageId);

        $content = [];
        foreach ($contentElements as $element) {
            $item = [
                'type' => $element['CType'],
                'colPos' => $element['colPos'],
            ];

            if ('' !== $element['header']) {
                $item['header'] = $element['header'];
            }

            if ('' !== $element['bodytext']) {
                $item['bodytext'] = strip_tags($element['bodytext']);
            }

            if ('' !== $element['subheader']) {
                $item['subheader'] = $element['subheader'];
            }

            $content[] = $item;
        }

        return ToolResult::success([
            'page' => [
                'pageId' => $page['uid'],
                'title' => $page['title'],
                'slug' => $page['slug'],
                'description' => $page['description'] ?? '',
                'abstract' => $page['abstract'] ?? '',
            ],
            'content' => $content,
        ]);
    }

    private function createQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->setRestrictions(new FrontendRestrictionContainer($this->context));

        return $queryBuilder;
    }

    private function resolvePageIdBySlug(string $slug, int $rootPageId): ?int
    {
        $queryBuilder = $this->createQueryBuilder('pages');

        $results = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Find the one that belongs to the current site and may be read
        foreach ($results as $result) {
            if ($this->pageAccess->isAccessible((int) $result['uid'], $rootPageId)) {
                return (int) $result['uid'];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getPage(int $pageId): ?array
    {
        $queryBuilder = $this->createQueryBuilder('pages');

        $result = $queryBuilder
            ->select('uid', 'title', 'slug', 'description', 'abstract')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return false !== $result ? $result : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getContentElements(int $pageId): array
    {
        $queryBuilder = $this->createQueryBuilder('tt_content');

        return $queryBuilder
            ->select('uid', 'CType', 'colPos', 'header', 'subheader', 'bodytext')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageId, ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('colPos')
            ->addOrderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
