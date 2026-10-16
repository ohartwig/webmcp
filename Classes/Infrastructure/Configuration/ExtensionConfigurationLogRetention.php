<?php

declare(strict_types=1);

namespace Koh\WebMcp\Infrastructure\Configuration;

use Koh\WebMcp\Application\Contract\LogRetentionInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * Reads `logRetentionDays` from the extension configuration
 * (`ext_conf_template.txt`), 30 days unless an operator says otherwise.
 */
final readonly class ExtensionConfigurationLogRetention implements LogRetentionInterface
{
    public const int DEFAULT_DAYS = 30;

    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function days(): int
    {
        try {
            $configured = $this->extensionConfiguration->get('webmcp', 'logRetentionDays');
        } catch (\Throwable) {
            return self::DEFAULT_DAYS;
        }

        if (!is_scalar($configured) || !is_numeric((string) $configured)) {
            return self::DEFAULT_DAYS;
        }

        return max(1, (int) $configured);
    }
}
