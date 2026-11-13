<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The type of principal (realm) an action or session belongs to. User and Admin
 * are fully separate entities/firewalls (ADR-003), so a principal is always
 * exactly one of these two.
 *
 * Single source of truth for the previously free-string `audit_log.actor_type`
 * column; validated in AuditLog::setActorType. (The `user_sessions.user_type`
 * discriminator was removed in FEATURE-123 — admin sessions live in their own
 * admin_sessions table, so user_sessions is user-only and needs no discriminator.)
 */
enum PrincipalType: string
{
    case User = 'user';
    case Admin = 'admin';

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
