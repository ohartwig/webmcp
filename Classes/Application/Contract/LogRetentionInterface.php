<?php

declare(strict_types=1);

namespace Koh\WebMcp\Application\Contract;

/**
 * How long a tool invocation log entry is kept.
 *
 * The log is a visitor record — a keyed address hash and a reduced user
 * agent per call — and personal data has a retention, not a default of
 * forever. The value is operator configuration; where it is read from is
 * Infrastructure's business.
 */
interface LogRetentionInterface
{
    /**
     * Retention in days, always at least 1.
     */
    public function days(): int;
}
