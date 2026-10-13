<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\ToolHandler;

use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;

final readonly class NavigationToolHandler implements ToolHandlerInterface
{
    private const int MAX_TOTAL_NODES = 500;

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    public function getToolName(): string
    {
        return 'navigation';
    }

    public function execute(array $input, ExecutionContext $context): ToolResult
    {
        $rootPageId = $context->rootPageId;
        $configuredMaxDepth = (int) ($context->settings['tools']['navigation']['maxDepth'] ?? 4);
        $maxDepth = min((int) ($input['maxDepth'] ?? $configuredMaxDepth), $configuredMaxDepth);
        if ($maxDepth < 1) {
            $maxDepth = 1;
        }

        $nodeCount = 0;
        $tree = $this->buildTree($rootPageId, $maxDepth, 0, $nodeCount);

        return ToolResult::success([
            'rootPageId' => $rootPageId,
            'navigation' => $tree,
        ]);
    }

    private function createQueryBuilder(string $table): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->setRestrictions(new FrontendRestrictionContainer($this->context));

        return $queryBuilder;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(int $parentId, int $maxDepth, int $currentDepth, int &$nodeCount): array
    {
        if ($currentDepth >= $maxDepth || $nodeCount >= self::MAX_TOTAL_NODES) {
            return [];
        }

        $queryBuilder = $this->createQueryBuilder('pages');

        $pages = $queryBuilder
            ->select('uid', 'title', 'slug', 'doktype', 'nav_hide')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentId, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->eq('nav_hide', $queryBuilder->createNamedParameter(0, \Doctrine\DBAL\ParameterType::INTEGER)),
                $queryBuilder->expr()->in('doktype', $queryBuilder->createNamedParameter([1, 3, 4], \Doctrine\DBAL\ArrayParameterType::INTEGER)),
            )
            ->orderBy('sorting')
            ->executeQuery()
            ->fetchAllAssociative();

        $items = [];
        foreach ($pages as $page) {
            if ($nodeCount >= self::MAX_TOTAL_NODES) {
                break;
            }

            ++$nodeCount;
            $item = [
                'pageId' => $page['uid'],
                'title' => $page['title'],
                'slug' => $page['slug'],
                'type' => match ((int) $page['doktype']) {
                    1 => 'page',
                    3 => 'external_url',
                    4 => 'shortcut',
                    default => 'other',
                },
            ];

            $children = $this->buildTree($page['uid'], $maxDepth, $currentDepth + 1, $nodeCount);
            if ([] !== $children) {
                $item['children'] = $children;
            }

            $items[] = $item;
        }

        return $items;
    }
}
