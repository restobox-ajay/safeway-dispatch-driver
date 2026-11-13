<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The outcome recorded for an audit-log entry. Single source of truth for the
 * previously free-string `audit_log.outcome` column; validated in
 * AuditLog::setOutcome.
 */
enum AuditOutcome: string
{
    case Success = 'success';
    case Failure = 'failure';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom($value) !== null;
    }
}
