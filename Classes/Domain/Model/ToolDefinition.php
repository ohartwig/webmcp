<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class ToolDefinition
{
    /**
     * Tool name pattern per W3C WebMCP Community Draft (2026-05-20):
     * 1-128 chars, ASCII alphanumerics plus U+005F (_), U+002D (-), U+002E (.).
     */
    private const NAME_PATTERN = '/^[A-Za-z0-9_.\-]{1,128}$/';

    /**
     * @param string               $name        Tool identifier (e.g. "search", "navigation"). Must match [A-Za-z0-9_.-]{1,128}.
     * @param string               $description Human-readable description. Must not be empty.
     * @param array<string, mixed> $inputSchema JSON Schema for the tool's input
     * @param ToolAnnotations      $annotations Tool annotations (readOnlyHint etc.)
     * @param string               $apiEndpoint API endpoint path for server-side execution
     * @param string|null          $title       Optional human-readable label for UI rendering
     *
     * @throws \InvalidArgumentException When name violates the WebMCP spec pattern
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public ToolAnnotations $annotations = new ToolAnnotations(),
        public string $apiEndpoint = '',
        public ?string $title = null,
    ) {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new \InvalidArgumentException(sprintf('WebMCP tool name must match [A-Za-z0-9_.-]{1,128} per spec, got: "%s"', $name));
        }
        if ('' === trim($description)) {
            throw new \InvalidArgumentException(sprintf('WebMCP tool description must not be empty (tool: "%s")', $name));
        }
    }

    /**
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array{readOnlyHint: bool, untrustedContentHint: bool}, title?: string}
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
            'annotations' => $this->annotations->toArray(),
        ];
        if (null !== $this->title && '' !== trim($this->title)) {
            $payload['title'] = $this->title;
        }

        return $payload;
    }
}
