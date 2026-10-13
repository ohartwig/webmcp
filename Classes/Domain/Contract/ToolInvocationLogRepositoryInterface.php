<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Contract;

use Koh\WebMcp\Domain\Model\ToolInvocationLog;

interface ToolInvocationLogRepositoryInterface
{
    public function save(ToolInvocationLog $log): void;

    /**
     * @return ToolInvocationLog[]
     */
    public function findBySite(string $siteIdentifier, int $limit = 100, int $offset = 0): array;

    /**
     * @return array<string, int>
     */
    public function countByToolForSite(string $siteIdentifier): array;

    public function countBySite(string $siteIdentifier): int;

    public function deleteOlderThan(\DateTimeImmutable $cutoff): int;
}
