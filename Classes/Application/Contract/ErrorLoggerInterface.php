<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * The application's port for reporting failures it has already handled.
 *
 * Narrower than PSR-3 on purpose: the Application layer only ever calls
 * `error()`, so that is all the port offers. A port that mirrors the whole
 * PSR-3 surface would invite the other seven levels into the layer without
 * anyone deciding that they belong there.
 */
interface ErrorLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void;
}
