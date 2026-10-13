<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

use Koh\WebMcp\Domain\Model\ConsentRequirement;

interface ConsentCheckInterface
{
    /**
     * Check if a tool is allowed based on consent configuration.
     *
     * @param array<string, mixed> $siteSettings
     */
    public function isToolAllowed(string $toolName, array $siteSettings): bool;

    /**
     * Get the consent requirement for a specific tool.
     *
     * @param array<string, mixed> $siteSettings
     */
    public function getConsentRequirement(string $toolName, array $siteSettings): ConsentRequirement;
}
