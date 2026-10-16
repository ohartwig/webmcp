<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * Answers which pages the caller of the current request may read.
 *
 * The tool handlers used to apply the frontend restrictions to the content
 * rows only and took the page tree as it is. TYPO3 decides the other way
 * round: a page that is hidden, not yet published, expired, moved to the
 * recycler or restricted to a frontend group makes every content element on
 * it inaccessible, and a protected page with "extend to subpages" takes its
 * whole subtree with it. This port is where that decision lives, so every
 * handler asks the same question and gets the same answer.
 */
interface PageAccessInterface
{
    /**
     * The ids of every page below (and including) the root page that the
     * current frontend context may read, in tree order.
     *
     * @return int[]
     */
    public function accessiblePageIds(int $rootPageId): array;

    /**
     * Whether one page belongs to the site rooted at $rootPageId and may be
     * read in the current frontend context, including the restrictions it
     * inherits from its rootline.
     */
    public function isAccessible(int $pageId, int $rootPageId): bool;
}
