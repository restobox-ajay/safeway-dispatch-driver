<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The complete set of account statuses a User or Admin may hold — the single
 * source of truth (replaces the private ALLOWED_STATUSES const previously
 * duplicated across three controllers).
 *
 * Status is load-bearing: soft-delete sets `inactive` (ADR-020), isEqualTo()
 * compares it, and both firewalls reject a non-active account at login. There
 * is deliberately no `archived`/`banned`/`pending` state — active|inactive is
 * sufficient, and anything else must be impossible to persist (see
 * User::setStatus / Admin::setStatus, which validate against this enum).
 */
enum AccountStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

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
