<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;

/**
 * Implement this interface to handle several tool names with one handler,
 * where the set of names is only known at request time (as is the case for
 * tools derived from contexts).
 *
 * Use ToolHandlerInterface for the common case of one handler per tool.
 * Implementations are auto-tagged via Services.yaml (_instanceof).
 */
interface MultiToolHandlerInterface
{
    /**
     * The tool names this handler is responsible for. Resolved per request,
     * so it must not be cached across requests.
     *
     * @return string[]
     */
    public function getToolNames(): array;

    /**
     * Execute one of the handled tools.
     *
     * @param string               $toolName One of the names returned by getToolNames()
     * @param array<string, mixed> $input    Validated input data
     * @param ExecutionContext     $context  The execution context
     *
     * @return ToolResult The execution result
     */
    public function execute(string $toolName, array $input, ExecutionContext $context): ToolResult;
}
