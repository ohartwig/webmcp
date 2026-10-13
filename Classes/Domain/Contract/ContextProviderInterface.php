<?php

declare(strict_types=1);

namespace Koh\WebMcp\Domain\Contract;

use Koh\WebMcp\Domain\Model\ContextDefinition;

/**
 * Implement this interface to provide context data via navigator.modelContext.provideContext().
 * Implementations are auto-tagged via Services.yaml (_instanceof).
 */
interface ContextProviderInterface
{
    public function getIdentifier(): string;

    /**
     * @return ContextDefinition[]
     */
    public function getContexts(): array;
}
