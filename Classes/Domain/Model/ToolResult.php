<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class ToolResult
{
    /**
     * @param bool                 $success Whether the tool execution was successful
     * @param array<string, mixed> $data    The result data
     * @param string               $error   Error message if execution failed
     */
    public function __construct(
        public bool $success,
        public array $data = [],
        public string $error = '',
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data): self
    {
        return new self(success: true, data: $data);
    }

    public static function error(string $message): self
    {
        return new self(success: false, error: $message);
    }

    /**
     * @return array{success: bool, data?: array<string, mixed>, error?: string}
     */
    public function toArray(): array
    {
        if ($this->success) {
            return [
                'success' => true,
                'data' => $this->data,
            ];
        }

        return [
            'success' => false,
            'error' => $this->error,
        ];
    }
}
