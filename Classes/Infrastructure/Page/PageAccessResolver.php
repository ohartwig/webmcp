<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Page;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Koh\WebMcp\Application\Contract\PageAccessInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Access\RecordAccessVoter;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;

/**
 * Decides page access the way the page resolver does, for requests that
 * never reach it.
 *
 * The API middleware runs before TYPO3 resolves a page, so there is no
 * rootline to consult and the handlers have to walk the tree themselves.
 * Walking it with the deleted restriction alone (which the handlers did) is
 * right for finding the pages — a hidden page must not cut off a subtree that
 * is visible on its own — but wrong for deciding what may be read. That
 * decision is made here, per page, with the core's own access voter and the
 * two rules the page resolver applies on top of it:
 *
 *  - a page is readable when its own enable fields pass for the current
 *    frontend context (hidden, starttime, endtime, fe_group);
 *  - a page that fails and has "extend to subpages" set takes its whole
 *    subtree with it, as does a backend-user section or a recycler.
 *
 * Spacers, folders and recyclers carry no content and are never returned,
 * though the children of a spacer or a folder are reachable, as in TYPO3.
 * Mount points are not followed; the tree is the site's own.
 */
final readonly class PageAccessResolver implements PageAccessInterface
{
    private const int MAX_DEPTH = 20;
    private const int MAX_PAGES = 10000;
    private const int MAX_ROOTLINE = 100;

    /**
     * Removed from the core in v13 but still present in existing installations.
     */
    private const int DOKTYPE_RECYCLER = 255;

    /** Page types without content of their own. */
    private const array CONTENTLESS_DOKTYPES = [
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
        self::DOKTYPE_RECYCLER,
        PageRepository::DOKTYPE_BE_USER_SECTION,
    ];

    /** Page types that close their whole subtree to visitors. */
    private const array SUBTREE_CLOSING_DOKTYPES = [
        self::DOKTYPE_RECYCLER,
        PageRepository::DOKTYPE_BE_USER_SECTION,
    ];

    private const array FIELDS = ['uid', 'pid', 'doktype', 'extendToSubpages', 'hidden', 'starttime', 'endtime', 'fe_group'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private RecordAccessVoter $accessVoter,
    ) {}

    public function accessiblePageIds(int $rootPageId): array
    {
        $root = $this->fetchByUids([$rootPageId]);
        if (!isset($root[$rootPageId])) {
            return [];
        }

        $accessible = [];
        $seen = 1;
        $level = [$root[$rootPageId]];

        for ($depth = 0; $depth <= self::MAX_DEPTH && [] !== $level; ++$depth) {
            $openParents = [];
            foreach ($level as $page) {
                $granted = $this->accessVoter->accessGranted('pages', $page, $this->context);
                $doktype = (int) $page['doktype'];

                if ($granted && !in_array($doktype, self::CONTENTLESS_DOKTYPES, true)) {
                    $accessible[] = (int) $page['uid'];
                }

                if ($this->closesSubtree($page, $granted)) {
                    continue;
                }
                $openParents[] = (int) $page['uid'];
            }

            if ([] === $openParents || $seen >= self::MAX_PAGES) {
                break;
            }

            $level = $this->fetchChildren($openParents);
            $seen += count($level);
        }

        return $accessible;
    }

    public function isAccessible(int $pageId, int $rootPageId): bool
    {
        $rootline = $this->fetchRootline($pageId, $rootPageId);
        if ([] === $rootline) {
            return false;
        }

        $page = $rootline[0];
        if (in_array((int) $page['doktype'], self::CONTENTLESS_DOKTYPES, true)) {
            return false;
        }
        if (!$this->accessVoter->accessGranted('pages', $page, $this->context)) {
            return false;
        }

        foreach (array_slice($rootline, 1) as $ancestor) {
            $granted = $this->accessVoter->accessGranted('pages', $ancestor, $this->context);
            if ($this->closesSubtree($ancestor, $granted)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $page
     */
    private function closesSubtree(array $page, bool $granted): bool
    {
        if (in_array((int) $page['doktype'], self::SUBTREE_CLOSING_DOKTYPES, true)) {
            return true;
        }

        return !$granted && (bool) ($page['extendToSubpages'] ?? false);
    }

    /**
     * The page and its ancestors up to the site root, nearest first. Empty
     * when the page does not exist or does not belong to the site.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRootline(int $pageId, int $rootPageId): array
    {
        $rootline = [];
        $currentId = $pageId;

        for ($i = 0; $i < self::MAX_ROOTLINE; ++$i) {
            $pages = $this->fetchByUids([$currentId]);
            if (!isset($pages[$currentId])) {
                return [];
            }
            $page = $pages[$currentId];
            $rootline[] = $page;

            if ($currentId === $rootPageId) {
                return $rootline;
            }

            $currentId = (int) $page['pid'];
            if ($currentId <= 0) {
                return [];
            }
        }

        return [];
    }

    /**
     * @param int[] $uids
     *
     * @return array<int, array<string, mixed>> keyed by uid
     */
    private function fetchByUids(array $uids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        $rows = $queryBuilder
            ->select(...self::FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($uids, ArrayParameterType::INTEGER)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int) $row['uid']] = $row;
        }

        return $pages;
    }

    /**
     * The default-language children of the given pages, one query per tree
     * level rather than one per page.
     *
     * @param int[] $parentIds
     *
     * @return list<array<string, mixed>>
     */
    private function fetchChildren(array $parentIds): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder
            ->select(...self::FIELDS)
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parentIds, ArrayParameterType::INTEGER)),
                $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter(0, ParameterType::INTEGER)),
            )
            ->orderBy('pid')
            ->addOrderBy('sorting')
            ->setMaxResults(self::MAX_PAGES)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
