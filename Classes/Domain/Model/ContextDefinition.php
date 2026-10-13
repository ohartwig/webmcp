<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class ContextDefinition
{
    /**
     * @param string               $name        Context identifier
     * @param string               $description Human-readable description
     * @param array<string, mixed> $data        Structured context data
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $data,
    ) {}

    /**
     * @return array{name: string, description: string, data: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'data' => $this->data,
        ];
    }
}
