<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class ToolInvocationLog
{
    public function __construct(
        public string $toolName,
        public \DateTimeImmutable $timestamp,
        public string $userAgent,
        public string $ipHash,
        public string $siteIdentifier,
        public int $rootPageId,
        public bool $success,
        public int $durationMs,
        public string $errorMessage = '',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tool_name' => $this->toolName,
            'tstamp' => $this->timestamp->getTimestamp(),
            'crdate' => $this->timestamp->getTimestamp(),
            'user_agent' => $this->userAgent,
            'ip_hash' => $this->ipHash,
            'site_identifier' => $this->siteIdentifier,
            'root_page_id' => $this->rootPageId,
            'success' => $this->success ? 1 : 0,
            'duration_ms' => $this->durationMs,
            'error_message' => $this->errorMessage,
        ];
    }
}
