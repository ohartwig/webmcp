<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Service;

use Koh\WebMcp\Application\Contract\ConsentCheckInterface;
use Koh\WebMcp\Domain\Model\ConsentRequirement;

final readonly class ConsentService implements ConsentCheckInterface
{
    public function isToolAllowed(string $toolName, array $siteSettings): bool
    {
        $consent = $this->getConsentRequirement($toolName, $siteSettings);

        // If consent is not required, tool is always allowed server-side.
        // Client-side JS handles actual consent gating for required tools.
        return !$consent->required;
    }

    public function getConsentRequirement(string $toolName, array $siteSettings): ConsentRequirement
    {
        $toolSettings = $siteSettings['tools'][$toolName] ?? [];
        $consentSettings = $toolSettings['consent'] ?? [];

        return new ConsentRequirement(
            toolName: $toolName,
            required: (bool) ($consentSettings['required'] ?? false),
            category: (string) ($consentSettings['category'] ?? 'functional'),
            provider: (string) ($siteSettings['consent']['provider'] ?? 'native'),
        );
    }
}
