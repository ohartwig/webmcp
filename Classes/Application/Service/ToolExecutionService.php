<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Service;

use Koh\WebMcp\Application\Contract\MultiToolHandlerInterface;
use Koh\WebMcp\Application\Contract\ToolHandlerInterface;
use Koh\WebMcp\Application\Event\ToolExecutedEvent;
use Koh\WebMcp\Domain\Model\ExecutionContext;
use Koh\WebMcp\Domain\Model\ToolResult;
use Koh\WebMcp\Application\Contract\ErrorLoggerInterface;
use Koh\WebMcp\Application\Contract\EventDispatcherInterface;

final readonly class ToolExecutionService
{
    /** @var array<string, ToolHandlerInterface> */
    private array $handlers;

    /** @var MultiToolHandlerInterface[] */
    private array $multiHandlers;

    /**
     * @param iterable<ToolHandlerInterface>      $toolHandlers
     * @param iterable<MultiToolHandlerInterface> $multiToolHandlers
     */
    public function __construct(
        iterable $toolHandlers,
        private ErrorLoggerInterface $logger,
        private EventDispatcherInterface $eventDispatcher,
        iterable $multiToolHandlers = [],
    ) {
        $handlers = [];
        foreach ($toolHandlers as $handler) {
            $name = $handler->getToolName();
            if (isset($handlers[$name])) {
                throw new \RuntimeException(sprintf('Duplicate tool handler for "%s"', $name));
            }
            $handlers[$name] = $handler;
        }
        $this->handlers = $handlers;

        $multi = [];
        foreach ($multiToolHandlers as $handler) {
            $multi[] = $handler;
        }
        $this->multiHandlers = $multi;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function execute(string $toolName, array $input, ExecutionContext $context): ToolResult
    {
        $multiHandler = null;
        if (!isset($this->handlers[$toolName])) {
            $multiHandler = $this->findMultiHandler($toolName);
            if (null === $multiHandler) {
                return ToolResult::error('Unknown tool');
            }
        }

        $startTime = hrtime(true);

        try {
            $result = null !== $multiHandler
                ? $multiHandler->execute($toolName, $input, $context)
                : $this->handlers[$toolName]->execute($input, $context);
        } catch (\Throwable $e) {
            $this->logger->error('Tool execution failed', [
                'tool' => $toolName,
                'exception' => $e->getMessage(),
            ]);
            $result = ToolResult::error('Internal error');
        }

        $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $this->eventDispatcher->dispatch(new ToolExecutedEvent(
            toolName: $toolName,
            result: $result,
            context: $context,
            durationMs: $durationMs,
        ));

        return $result;
    }

    public function hasHandler(string $toolName): bool
    {
        return isset($this->handlers[$toolName]) || null !== $this->findMultiHandler($toolName);
    }

    /**
     * Multi-handlers resolve their names per request, so this lookup cannot be
     * precomputed in the constructor.
     */
    private function findMultiHandler(string $toolName): ?MultiToolHandlerInterface
    {
        foreach ($this->multiHandlers as $handler) {
            if (in_array($toolName, $handler->getToolNames(), true)) {
                return $handler;
            }
        }

        return null;
    }
}
