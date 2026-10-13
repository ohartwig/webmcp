<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Model;

final readonly class ConsentRequirement
{
    public function __construct(
        public string $toolName,
        public bool $required = false,
        public string $category = 'functional',
        public string $provider = 'native',
    ) {}
}
