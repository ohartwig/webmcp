<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class ToolAnnotations
{
    /**
     * @param bool $readOnlyHint         Tool reads but does not modify state — set false for tools that mutate data (e.g. form submission).
     * @param bool $untrustedContentHint Tool output may contain editor-curated content that the agent should treat as untrusted (prompt-injection risk). Recommended `true` for search/page-content/anything echoing CMS bodytext.
     */
    public function __construct(
        public bool $readOnlyHint = true,
        public bool $untrustedContentHint = false,
    ) {}

    /**
     * @return array{readOnlyHint: bool, untrustedContentHint: bool}
     */
    public function toArray(): array
    {
        return [
            'readOnlyHint' => $this->readOnlyHint,
            'untrustedContentHint' => $this->untrustedContentHint,
        ];
    }
}
