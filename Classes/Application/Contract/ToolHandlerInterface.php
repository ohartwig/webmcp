<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;

/**
 * Implement this interface to handle server-side tool execution.
 * Implementations are auto-tagged via Services.yaml (_instanceof).
 */
interface ToolHandlerInterface
{
    /**
     * The tool name this handler is responsible for.
     */
    public function getToolName(): string;

    /**
     * Execute the tool with the given input.
     *
     * @param array<string, mixed> $input   Validated input data
     * @param ExecutionContext     $context The execution context
     *
     * @return ToolResult The execution result
     */
    public function execute(array $input, ExecutionContext $context): ToolResult;
}
