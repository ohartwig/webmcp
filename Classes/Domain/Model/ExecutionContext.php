<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

/**
 * Encapsulates the execution context for tool handlers,
 * decoupling the Domain layer from PSR-7 HTTP concerns.
 */
final readonly class ExecutionContext
{
    /**
     * @param int                  $rootPageId     The root page ID of the current site
     * @param int                  $languageId     The current language UID
     * @param string               $siteIdentifier The site identifier string
     * @param array<string, mixed> $settings       Tool-specific settings from site configuration
     */
    public function __construct(
        public int $rootPageId,
        public int $languageId = 0,
        public string $siteIdentifier = '',
        public array $settings = [],
    ) {}
}
