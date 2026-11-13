<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Immutable result of parsing audit-log filter query parameters.
 *
 * $filters is the (validated) filter array consumed by AuditLogRepository:
 * scalar strings for actor/action and \DateTimeImmutable for date_from/date_to.
 * $error is a human-readable message when one or more inputs were invalid
 * (invalid inputs are omitted from $filters), or null when everything parsed.
 */
final class AuditLogFilterResult
{
    /** @param array<string, string|\DateTimeImmutable> $filters */
    public function __construct(
        public readonly array $filters,
        public readonly ?string $error = null,
    ) {
    }

    public function isValid(): bool
    {
        return $this->error === null;
    }
}
