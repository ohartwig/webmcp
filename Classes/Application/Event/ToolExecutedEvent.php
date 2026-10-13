<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Event;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;

final readonly class ToolExecutedEvent
{
    public function __construct(
        public string $toolName,
        public ToolResult $result,
        public ExecutionContext $context,
        public int $durationMs,
    ) {}
}
